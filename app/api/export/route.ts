import { NextRequest, NextResponse } from "next/server";
import { requireAdmin } from "../../lib/auth";
import { query } from "../../lib/db";

const datePattern = /^\d{4}-\d{2}-\d{2}$/;
const today = () => new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Jakarta" });
const validDate = (value: string) => {
  if (!datePattern.test(value)) return false;
  const date = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value;
};
const cell = (value: unknown) => {
  let text = String(value ?? "");
  if (/^[=+\-@\t\r]/u.test(text)) text = `'${text}`;
  return `"${text.replace(/"/g, '""')}"`;
};
const csv = (headers: string[], rows: unknown[][]) =>
  `\uFEFF${[headers, ...rows].map((row) => row.map(cell).join(";")).join("\r\n")}`;
const response = (name: string, content: string) => new NextResponse(content, {
  headers: {
    "Content-Type": "text/csv; charset=utf-8",
    "Content-Disposition": `attachment; filename="${name}"`,
    "Cache-Control": "no-store",
  },
});

export async function GET(request: NextRequest) {
  await requireAdmin();
  const params = request.nextUrl.searchParams;
  const type = params.get("type");
  const from = params.get("from") || "";
  const to = params.get("to") || "";
  if ((from && !validDate(from)) || (to && !validDate(to)) || (from && to && from > to)) {
    return NextResponse.json({ error: "Periode tanggal tidak valid." }, { status: 400 });
  }

  if (type === "borrowers") {
    if (!from || !to) return NextResponse.json({ error: "Tanggal awal dan akhir wajib diisi." }, { status: 400 });
    const data = await query(
      "SELECT l.id AS loan_id,l.loan_date,l.due_date,b.name AS borrower_name,b.employee_id,u.name AS unit_name,d.right_number,c.name AS category,d.district,d.village,li.returned_at,CASE WHEN li.returned_at IS NOT NULL THEN 'Selesai' WHEN l.due_date<CURRENT_DATE THEN 'Terlambat' ELSE 'Dipinjam' END AS item_status,l.purpose FROM loans l JOIN borrowers b ON b.id=l.borrower_id LEFT JOIN units u ON u.id=b.unit_id JOIN loan_items li ON li.loan_id=l.id JOIN documents d ON d.id=li.document_id JOIN right_categories c ON c.id=d.right_category_id WHERE l.loan_date BETWEEN $1 AND $2 ORDER BY l.loan_date DESC,l.id DESC,b.name,d.right_number",
      [from, to],
    );
    const headers = ["No. transaksi", "Tanggal pinjam", "Nama peminjam", "NIP/ID", "Unit kerja / seksi", "Nomor hak", "Jenis hak", "Kecamatan", "Desa/Kelurahan", "Tenggat kembali", "Tanggal kembali", "Status", "Keperluan"];
    const rows = data.map((r) => [r.loan_id, r.loan_date, r.borrower_name, r.employee_id || "—", r.unit_name || "—", r.right_number, r.category, r.district, r.village, r.due_date, r.returned_at || "—", r.item_status, r.purpose]);
    return response(`laporan-peminjam-${from}-sampai-${to}.csv`, csv(headers, rows));
  }

  if (type !== "report") return NextResponse.json({ error: "Jenis ekspor tidak dikenal." }, { status: 400 });
  const report = params.get("report") || "Ketersediaan BTSU";
  let headers: string[] = [];
  let rows: unknown[][] = [];
  if (report === "Aktivitas perubahan") {
    headers = ["Waktu", "Admin", "Aktivitas", "Detail", "Alamat IP"];
    const conditions: string[] = [];
    const values: string[] = [];
    if (from) { values.push(from); conditions.push(`a.created_at::date >= $${values.length}`); }
    if (to) { values.push(to); conditions.push(`a.created_at::date <= $${values.length}`); }
    const data = await query(`SELECT a.created_at,ad.name,a.action,a.detail,a.ip_address FROM audit_logs a LEFT JOIN admins ad ON ad.id=a.admin_id${conditions.length ? ` WHERE ${conditions.join(" AND ")}` : ""} ORDER BY a.created_at DESC LIMIT 5000`, values);
    rows = data.map((r) => [r.created_at, r.name || "Akun dihapus", r.action, r.detail, r.ip_address]);
  } else if (report === "Ketersediaan BTSU") {
    headers = ["Kecamatan", "Desa/Kelurahan", "Jenis hak", "Nomor hak", "Lokasi arsip", "Status"];
    const data = await query(`SELECT d.*,c.name AS category_name,CASE
      WHEN d.status IN ('Hilang','Nonaktif') THEN d.status
      WHEN EXISTS (SELECT 1 FROM loan_items li JOIN loans l ON l.id=li.loan_id WHERE li.document_id=d.id AND li.returned_at IS NULL AND l.due_date<CURRENT_DATE) THEN 'Terlambat'
      WHEN EXISTS (SELECT 1 FROM loan_items li WHERE li.document_id=d.id AND li.returned_at IS NULL) THEN 'Dipinjam'
      ELSE 'Tersedia' END AS effective_status FROM documents d JOIN right_categories c ON c.id=d.right_category_id ORDER BY d.district,d.village,d.right_number`);
    rows = data.map((d) => [d.district, d.village, d.category_name, d.right_number, `Lantai ${d.floor}, Lemari ${d.cabinet}, Rak ${d.rack}, Album ${d.album || "—"}`, d.effective_status]);
  } else if (report === "Dokumen hilang") {
    headers = ["Kecamatan", "Desa/Kelurahan", "Jenis hak", "Nomor hak", "Lokasi arsip"];
    const data = await query("SELECT d.*,c.name AS category_name FROM documents d JOIN right_categories c ON c.id=d.right_category_id WHERE d.status='Hilang' ORDER BY d.district,d.village,d.right_number");
    rows = data.map((d) => [d.district, d.village, d.category_name, d.right_number, `Lantai ${d.floor}, Lemari ${d.cabinet}, Rak ${d.rack}`]);
  } else if (["BTSU masih dipinjam", "Peminjaman per periode", "Peminjaman per dokumen", "Peminjaman per peminjam", "Peminjaman per unit", "Keterlambatan"].includes(report)) {
    const conditions: string[] = [];
    const values: string[] = [];
    if (["BTSU masih dipinjam", "Keterlambatan"].includes(report)) conditions.push("li.returned_at IS NULL");
    if (report === "Keterlambatan") conditions.push("l.due_date<CURRENT_DATE");
    if (from) { values.push(from); conditions.push(`l.loan_date >= $${values.length}`); }
    if (to) { values.push(to); conditions.push(`l.loan_date <= $${values.length}`); }
    const data = await query(`SELECT l.id,l.loan_date,l.due_date,b.name AS borrower_name,u.name AS unit_name,d.district,d.village,d.right_number,c.name AS category,li.returned_at FROM loans l JOIN borrowers b ON b.id=l.borrower_id LEFT JOIN units u ON u.id=b.unit_id JOIN loan_items li ON li.loan_id=l.id JOIN documents d ON d.id=li.document_id JOIN right_categories c ON c.id=d.right_category_id${conditions.length ? ` WHERE ${conditions.join(" AND ")}` : ""} ORDER BY l.loan_date DESC`, values);
    if (report === "BTSU masih dipinjam") {
      headers = ["Kecamatan", "Desa/Kelurahan", "Jenis hak", "Nomor hak", "Nama peminjam", "Unit kerja / seksi", "Tanggal pinjam"];
      rows = data.map((r) => [r.district, r.village, r.category, r.right_number, r.borrower_name, r.unit_name || "—", r.loan_date]);
    } else if (report === "Keterlambatan") {
      headers = ["Nama peminjam", "Nomor hak", "Tanggal pinjam", "Tenggat", "Hari terlambat", "Unit kerja"];
      rows = data.map((r) => [r.borrower_name, r.right_number, r.loan_date, r.due_date, Math.max(0, Math.floor((Date.now() - new Date(`${String(r.due_date).slice(0, 10)}T00:00:00Z`).getTime()) / 86400000)), r.unit_name || "—"]);
    } else {
      headers = ["Tanggal pinjam", "Nama peminjam", "Nomor hak", "Jenis hak", "Unit kerja", "Tanggal kembali", "Status"];
      rows = data.map((r) => [r.loan_date, r.borrower_name, r.right_number, r.category, r.unit_name || "—", r.returned_at || "—", r.returned_at ? "Selesai" : String(r.due_date).slice(0, 10) < today() ? "Terlambat" : "Dipinjam"]);
    }
  } else {
    return NextResponse.json({ error: "Jenis laporan tidak dikenal." }, { status: 400 });
  }
  return response(`laporan-btsu-${new Date().toISOString().slice(0, 10)}.csv`, csv(headers, rows));
}
