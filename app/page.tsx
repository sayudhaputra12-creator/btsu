import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import type { FormHTMLAttributes, ReactNode } from "react";
import { dispatch } from "./actions";
import { ActionButton, Badge, ButtonLink, formatDate, InlineAction, Notice, PageHead, PageShell, pageHref, Table } from "./components";
import { DistrictVillageSelect, DocumentPicker, PrintButton } from "./components-client";
import { requireAdmin, type AdminSession } from "./lib/auth";
import { query } from "./lib/db";

type SearchParams = Record<string, string | string[] | undefined>;

const text = (value: unknown) => String(value ?? "");
const num = (value: unknown) => Number(value || 0);
const first = (value: string | string[] | undefined, fallback = "") => Array.isArray(value) ? value[0] || fallback : value || fallback;
const today = () => new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Jakarta" });
const statusSql = `CASE
  WHEN d.status IN ('Hilang','Nonaktif') THEN d.status
  WHEN EXISTS (SELECT 1 FROM loan_items li JOIN loans l ON l.id=li.loan_id WHERE li.document_id=d.id AND li.returned_at IS NULL AND l.due_date<CURRENT_DATE) THEN 'Terlambat'
  WHEN EXISTS (SELECT 1 FROM loan_items li WHERE li.document_id=d.id AND li.returned_at IS NULL) THEN 'Dipinjam'
  ELSE 'Tersedia' END`;
const loanStatus = (loan: Record<string, unknown>) => num(loan.active_items) === 0 ? "Selesai" : text(loan.due_date).slice(0, 10) < today() ? "Terlambat" : "Dipinjam";
const isDate = (date: string) => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return false;
  const parsed = new Date(`${date}T00:00:00Z`);
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === date;
};
const csvHref = (params: Record<string, string>) => `/api/export?${new URLSearchParams(params).toString()}`;

function Form({
  action,
  children,
  className,
  ...props
}: {
  action: string;
  children: ReactNode;
  className?: string;
} & FormHTMLAttributes<HTMLFormElement>) {
  return <form action={dispatch} className={className} {...props}><input type="hidden" name="action" value={action} />{children}</form>;
}

function AuthCard({ children }: { children: ReactNode }) {
  return <main className="auth-page"><section className="auth-card">{children}</section></main>;
}

function StatusOptions({ current }: { current: string }) {
  return <select name="status" defaultValue={current}>{["Tersedia", "Hilang", "Nonaktif"].map((item) => <option key={item}>{item}</option>)}</select>;
}

async function renderAuthPage(page: string, params: SearchParams) {
  const rows = await query<{ count: string }>("SELECT COUNT(*)::text AS count FROM admins");
  const needsSetup = Number(rows[0]?.count || 0) === 0;
  const notice = first(params.notice);
  const type = first(params.type);

  if (page === "forgot") {
    return <AuthCard><Link className="brand-mark" href="/?page=login">BTSU</Link><p className="eyebrow">BANTUAN AKUN</p><h1>Lupa kata sandi?</h1><p className="muted">Hubungi Super Admin kantor untuk meminta reset kata sandi. Demi keamanan, sistem tidak mengirimkan tautan reset ke alamat yang belum diverifikasi.</p><ButtonLink href="/?page=login" className="button primary wide">Kembali ke halaman masuk</ButtonLink></AuthCard>;
  }
  if (needsSetup && page !== "setup") redirect("/?page=setup");
  if (!needsSetup && page === "setup") redirect("/?page=login");

  return <AuthCard>
    <Link className="brand-mark" href="/?page=login">BTSU</Link>
    <p className="eyebrow">{needsSetup ? "INISIALISASI SISTEM" : "TATA KELOLA ARSIP PERTANAHAN"}</p>
    <h1>{needsSetup ? "Buat akun Super Admin" : "Selamat datang"}</h1>
    <p className="muted">{needsSetup ? "Buat akun pertama untuk mengelola Buku Tanah dan Surat Ukur." : "Masuk untuk mengelola Buku Tanah dan Surat Ukur."}</p>
    <Notice message={notice} type={type} />
    <Form action={needsSetup ? "setup" : "login"} className="stack">
      {needsSetup && <label>Nama lengkap<input name="name" maxLength={120} required autoComplete="name" /></label>}
      <label>Username<input name="username" minLength={3} maxLength={60} required autoComplete="username" autoFocus={!needsSetup} /></label>
      <label>Kata sandi<input type="password" name="password" minLength={needsSetup ? 12 : undefined} required autoComplete={needsSetup ? "new-password" : "current-password"} /></label>
      <button className="button primary wide">{needsSetup ? "Buat akun" : "Masuk"}</button>
    </Form>
    {needsSetup
      ? <p className="auth-foot">Kata sandi minimal 12 karakter.</p>
      : <p className="auth-foot"><Link href="/?page=forgot">Lupa kata sandi?</Link> · Sesi berakhir setelah 30 menit tidak aktif.</p>}
  </AuthCard>;
}

async function Dashboard({ admin }: { admin: AdminSession }) {
  const [counts, overdue, active, activity] = await Promise.all([
    query("SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE d.status='Dipinjam') AS borrowed, COUNT(*) FILTER (WHERE d.status='Dipinjam' AND EXISTS (SELECT 1 FROM loan_items li JOIN loans l ON l.id=li.loan_id WHERE li.document_id=d.id AND li.returned_at IS NULL AND l.due_date<CURRENT_DATE)) AS overdue, COUNT(*) FILTER (WHERE d.status='Dipinjam' AND EXISTS (SELECT 1 FROM loan_items li JOIN loans l ON l.id=li.loan_id WHERE li.document_id=d.id AND li.returned_at IS NULL AND l.due_date BETWEEN CURRENT_DATE AND CURRENT_DATE+INTERVAL '3 days')) AS due_soon FROM documents d WHERE d.status<>'Nonaktif'"),
    query("SELECT l.id,b.name AS borrower_name,l.due_date,CURRENT_DATE-l.due_date AS late_days,STRING_AGG(d.right_number,', ' ORDER BY d.right_number) AS numbers FROM loans l JOIN borrowers b ON b.id=l.borrower_id JOIN loan_items li ON li.loan_id=l.id AND li.returned_at IS NULL JOIN documents d ON d.id=li.document_id WHERE l.due_date<CURRENT_DATE GROUP BY l.id,b.name ORDER BY l.due_date LIMIT 6"),
    query("SELECT l.id,b.name AS borrower_name,l.loan_date,l.due_date,(SELECT COUNT(*) FROM loan_items x WHERE x.loan_id=l.id AND x.returned_at IS NULL) AS active_items FROM loans l JOIN borrowers b ON b.id=l.borrower_id WHERE EXISTS (SELECT 1 FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL) ORDER BY l.due_date LIMIT 6"),
    query("SELECT a.action,a.detail,a.created_at,ad.name FROM audit_logs a LEFT JOIN admins ad ON ad.id=a.admin_id ORDER BY a.created_at DESC LIMIT 8"),
  ]);
  const c = counts[0] || {};
  return <>
    <PageHead title="Dashboard" subtitle="Ringkasan Buku Tanah dan Surat Ukur kantor Semarang." />
    <section className="stats-grid">
      {[
        ["Total dokumen", num(c.total), "Arsip aktif terdaftar", ""],
        ["Sedang dipinjam", num(c.borrowed), "Dokumen dalam transaksi aktif", ""],
        ["Terlambat", num(c.overdue), "Perlu ditindaklanjuti", "overdue-text"],
        ["Jatuh tempo ≤ 3 hari", num(c.due_soon), "Pengembalian mendekati tenggat", "due-text"],
      ].map(([label, amount, caption, cls]) => <article className="stat-card" key={label}><span>{label}</span><strong className={String(cls)}>{amount}</strong><small>{caption}</small></article>)}
    </section>
    <div className="dashboard-grid">
      <section className="panel"><div className="section-title"><h2>Daftar keterlambatan</h2><Link href={pageHref("loans", { filter: "Terlambat" })}>Lihat semua</Link></div>
        <Table headers={["Peminjam", "Nomor hak", "Tenggat", "Keterlambatan"]} empty="Tidak ada dokumen terlambat." rows={overdue.map((row) => [
          <Link href={pageHref("loan-detail", { id: num(row.id) })} key="name">{text(row.borrower_name)}</Link>,
          text(row.numbers), formatDate(text(row.due_date)), <span className="overdue-text" key="late">{num(row.late_days)} hari</span>,
        ])} />
      </section>
      <section className="panel"><div className="section-title"><h2>Peminjaman aktif terbaru</h2><Link href="/?page=loans">Lihat semua</Link></div>
        <Table headers={["Peminjam", "Dokumen", "Tanggal pinjam", "Tenggat", "Status"]} empty="Belum ada peminjaman aktif." rows={active.map((row) => [
          <Link href={pageHref("loan-detail", { id: num(row.id) })} key="name">{text(row.borrower_name)}</Link>,
          `${num(row.active_items)} dokumen`, formatDate(text(row.loan_date)), formatDate(text(row.due_date)), <Badge key="status" status={loanStatus(row)} />,
        ])} />
      </section>
      <section className="panel activity-panel"><h2>Aktivitas terbaru</h2>
        {activity.length === 0 ? <p className="muted">Belum ada aktivitas tercatat.</p> : <ul className="activity-list">{activity.map((row, i) => <li key={i}><strong>{text(row.action)}</strong><span>{text(row.detail || row.name || "Sistem")}</span><small>{new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" }).format(new Date(text(row.created_at)))}</small></li>)}</ul>}
      </section>
    </div>
  </>;
}

async function Documents({ params }: { params: SearchParams }) {
  const search = first(params.q);
  const status = first(params.status);
  const category = Number(first(params.category));
  const clauses: string[] = [];
  const values: unknown[] = [];
  const add = (clause: (parameter: string) => string, value: unknown) => { values.push(value); clauses.push(clause(`$${values.length}`)); };
  if (search) add((p) => `(d.right_number ILIKE ${p} OR c.name ILIKE ${p} OR d.village ILIKE ${p} OR d.district ILIKE ${p})`, `${search}%`);
  if (category > 0) add((p) => `d.right_category_id=${p}`, category);
  if (status === "Terlambat") clauses.push("EXISTS (SELECT 1 FROM loan_items li JOIN loans l ON l.id=li.loan_id WHERE li.document_id=d.id AND li.returned_at IS NULL AND l.due_date<CURRENT_DATE)");
  else if (status === "Dipinjam") clauses.push(`d.status='Dipinjam' AND NOT EXISTS (SELECT 1 FROM loan_items li JOIN loans l ON l.id=li.loan_id WHERE li.document_id=d.id AND li.returned_at IS NULL AND l.due_date<CURRENT_DATE)`);
  else if (status) add((p) => `d.status=${p}`, status);
  const filter = clauses.length ? ` WHERE ${clauses.join(" AND ")}` : "";
  const total = num((await query(`SELECT COUNT(*) AS count FROM documents d JOIN right_categories c ON c.id=d.right_category_id${filter}`, values))[0]?.count);
  const perPage = 100;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const page = Math.min(lastPage, Math.max(1, Number(first(params.page_number, "1")) || 1));
  const docs = await query(`SELECT d.*,c.name AS category_name,${statusSql} AS effective_status FROM documents d JOIN right_categories c ON c.id=d.right_category_id${filter} ORDER BY d.updated_at DESC LIMIT ${perPage} OFFSET ${(page - 1) * perPage}`, values);
  const categories = await query("SELECT id,name FROM right_categories WHERE active=1 ORDER BY name");
  return <>
    <PageHead title="Dokumen" subtitle="Inventaris Buku Tanah dan Surat Ukur." actions={<><ButtonLink href="/?page=document-import">Impor CSV</ButtonLink><ButtonLink href="/?page=document-form" className="button primary">Tambah dokumen</ButtonLink></>} />
    <form method="get" className="filter-bar"><input type="hidden" name="page" value="documents" />
      <label className="search-field">Cari nomor hak, jenis hak, kecamatan, desa<input name="q" defaultValue={search} placeholder="Ketik kata kunci…" /></label>
      <label>Jenis hak<select name="category" defaultValue={category || ""}><option value="">Semua jenis hak</option>{categories.map((item) => <option key={item.id} value={item.id}>{text(item.name)}</option>)}</select></label>
      <label>Status<select name="status" defaultValue={status}><option value="">Semua status</option>{["Tersedia", "Dipinjam", "Terlambat", "Hilang", "Nonaktif"].map((item) => <option key={item}>{item}</option>)}</select></label>
      <button className="button">Terapkan</button>
    </form>
    <section className="panel"><div className="section-title"><h2>Daftar dokumen</h2><span className="muted">{total} hasil</span></div>
      <Table headers={["Nomor hak", "Jenis hak", "Kecamatan / desa", "Lokasi arsip", "Status", ""]} empty="Dokumen belum ditambahkan." rows={docs.map((d) => [
        <Link href={pageHref("document-detail", { id: num(d.id) })} key="number"><strong>{text(d.right_number)}</strong></Link>,
        text(d.category_name), `${text(d.district)}, ${text(d.village)}`, `L${d.floor} · Lemari ${d.cabinet} · Rak ${d.rack}`,
        <Badge status={text(d.effective_status)} key="status" />,
        <ButtonLink href={pageHref("document-detail", { id: num(d.id) })} className="button small" key="detail">Detail</ButtonLink>,
      ])} />
      {lastPage > 1 && <nav className="pagination" aria-label="Halaman daftar"><span>Halaman {page} dari {lastPage} · {total} data</span><div>{page > 1 && <ButtonLink className="button small" href={pageHref("documents", { q: search, status, category: category || "", page_number: page - 1 })}>Sebelumnya</ButtonLink>}{page < lastPage && <ButtonLink className="button small" href={pageHref("documents", { q: search, status, category: category || "", page_number: page + 1 })}>Berikutnya</ButtonLink>}</div></nav>}
    </section>
  </>;
}

async function DocumentFormPage({ params }: { params: SearchParams }) {
  const id = Number(first(params.id));
  const doc = id ? (await query("SELECT * FROM documents WHERE id=$1", [id]))[0] : null;
  if (id && !doc) notFound();
  const [districtRows, villages, categories] = await Promise.all([
    query<{ name: string }>("SELECT name FROM districts WHERE active=1 OR name=$1 ORDER BY name", [text(doc?.district)]),
    query<{ district: string; village: string }>("SELECT d.name AS district,v.name AS village FROM villages v JOIN districts d ON d.id=v.district_id WHERE v.active=1 ORDER BY d.name,v.name"),
    query("SELECT id,name FROM right_categories WHERE active=1 OR id=$1 ORDER BY name", [num(doc?.right_category_id)]),
  ]);
  const byDistrict: Record<string, string[]> = {};
  for (const row of villages) (byDistrict[row.district] ||= []).push(row.village);
  const district = text(doc?.district);
  const village = text(doc?.village);
  const rackMatch = /^([1-9]\d*)([ab])$/i.exec(text(doc?.rack) || "1a");
  return <>
    <PageHead title={doc ? "Ubah dokumen" : "Tambah dokumen"} subtitle="Lokasi wilayah ditetapkan untuk Jawa Tengah · Kota Semarang." />
    <Form action="save_document" className="panel form-panel">
      <input type="hidden" name="id" value={id || ""} />
      <div className="form-section"><h2>Wilayah dokumen</h2><div className="form-grid">
        <label>Provinsi<input value="Jawa Tengah" readOnly /></label><label>Kabupaten/Kota<input value="Semarang" readOnly /></label>
        <DistrictVillageSelect districts={districtRows.map((row) => row.name)} villages={byDistrict} selectedDistrict={district} selectedVillage={village} />
      </div></div>
      <div className="form-section"><h2>Identitas dan lokasi arsip</h2><div className="form-grid">
        <label>Jenis hak<select name="right_category_id" defaultValue={num(doc?.right_category_id) || ""} required><option value="">Pilih jenis hak</option>{categories.map((item) => <option key={item.id} value={item.id}>{text(item.name)}</option>)}</select></label>
        <label>Nomor hak<input name="right_number" maxLength={80} required defaultValue={text(doc?.right_number)} /></label>
        <label>Tanggal dokumen<input type="date" name="document_date" defaultValue={text(doc?.document_date).slice(0, 10)} /></label>
        <label>Kondisi<select name="condition_state" defaultValue={text(doc?.condition_state) || "Baik"}>{["Baik", "Perlu perbaikan", "Rusak"].map((item) => <option key={item}>{item}</option>)}</select></label>
        <label>Lantai<input type="number" name="floor" min="1" step="1" required defaultValue={num(doc?.floor) || 1} /></label>
        <label>Lemari<input type="number" name="cabinet" min="1" step="1" required defaultValue={num(doc?.cabinet) || 1} /></label>
        <label>Nomor rak<input type="number" name="rack_number" min="1" step="1" required defaultValue={rackMatch?.[1] || 1} /></label>
        <label>Sisi rak<select name="rack_side" defaultValue={rackMatch?.[2]?.toLowerCase() || "a"}><option value="a">A</option><option value="b">B</option></select></label>
        <label>Album<input name="album" maxLength={80} defaultValue={text(doc?.album)} /></label>
        <label className="full">Catatan<textarea name="notes" rows={3} maxLength={5000} defaultValue={text(doc?.notes)} /></label>
      </div></div>
      <div className="form-actions"><button className="button primary">Simpan dokumen</button><ButtonLink href="/?page=documents">Batal</ButtonLink></div>
    </Form>
  </>;
}

async function DocumentImportPage() {
  return <>
    <PageHead title="Impor dokumen" subtitle="Tambahkan banyak dokumen sekaligus menggunakan file CSV." />
    <section className="panel form-panel narrow"><h2>Format file</h2><p className="muted">Gunakan header dan urutan kolom pada template. Kecamatan dan jenis hak harus sudah tersedia di master data. Baris valid diimpor; baris duplikat atau tidak valid akan dilaporkan.</p>
      <p><a className="button" href="/database/template_dokumen.csv" download>Unduh template CSV</a></p>
      <Form action="import_documents" className="stack" encType="multipart/form-data"><label>Pilih file CSV<input type="file" name="csv_file" accept=".csv,text/csv" required /><small>Maksimal 5 MB dan 10.000 baris.</small></label><button className="button primary">Mulai impor</button></Form>
    </section>
  </>;
}

async function DocumentDetail({ id }: { id: number }) {
  const doc = (await query(`SELECT d.*,c.name AS category_name,${statusSql} AS effective_status FROM documents d JOIN right_categories c ON c.id=d.right_category_id WHERE d.id=$1`, [id]))[0];
  if (!doc) notFound();
  const history = await query("SELECT l.id,l.loan_date,l.due_date,li.returned_at,b.name AS borrower_name FROM loan_items li JOIN loans l ON l.id=li.loan_id JOIN borrowers b ON b.id=l.borrower_id WHERE li.document_id=$1 ORDER BY l.loan_date DESC", [id]);
  return <>
    <PageHead title={`Nomor hak ${text(doc.right_number)}`} subtitle={`${text(doc.category_name)} · ${text(doc.district)}, ${text(doc.village)} · ${text(doc.effective_status)}`} actions={<><ButtonLink href={pageHref("document-form", { id })}>Ubah</ButtonLink><ButtonLink href="/?page=documents">Kembali</ButtonLink></>} />
    <section className="panel"><div className="section-title"><h2>Informasi dokumen</h2><Badge status={text(doc.effective_status)} /></div>
      <dl className="detail-grid">{[
        ["Provinsi", doc.province], ["Kabupaten/Kota", doc.city], ["Kecamatan", doc.district], ["Desa/Kelurahan", doc.village],
        ["Jenis hak", doc.category_name], ["Nomor hak", doc.right_number], ["Tanggal dokumen", formatDate(text(doc.document_date))],
        ["Lokasi arsip", `Lantai ${doc.floor} · Lemari ${doc.cabinet} · Rak ${doc.rack} · Album ${doc.album || "—"}`],
        ["Kondisi", doc.condition_state], ["Catatan", doc.notes || "—"],
      ].map(([label, value]) => <div key={String(label)}><dt>{label}</dt><dd>{text(value)}</dd></div>)}</dl>
      <div className="form-actions">{["Hilang", "Nonaktif"].includes(text(doc.status))
        ? <ActionButton action="set_document_status" fields={{ id, status: "Tersedia" }} label="Tandai tersedia" />
        : text(doc.status) !== "Dipinjam" && <><ActionButton action="set_document_status" fields={{ id, status: "Hilang" }} label="Tandai hilang" className="button danger" /><ActionButton action="set_document_status" fields={{ id, status: "Nonaktif" }} label="Nonaktifkan" className="button danger" /></>}
      </div>
    </section>
    <section className="panel"><h2>Riwayat peminjaman</h2><Table headers={["Peminjam", "Tanggal pinjam", "Tenggat", "Tanggal kembali", "Status"]} empty="Belum ada riwayat peminjaman." rows={history.map((item) => [
      <Link href={pageHref("loan-detail", { id: num(item.id) })} key="borrower">{text(item.borrower_name)}</Link>,
      formatDate(text(item.loan_date)), formatDate(text(item.due_date)), formatDate(text(item.returned_at)),
      <Badge key="status" status={item.returned_at ? "Selesai" : text(item.due_date).slice(0, 10) < today() ? "Terlambat" : "Dipinjam"} />,
    ])} /></section>
  </>;
}

async function Borrowers({ params }: { params: SearchParams }) {
  const q = first(params.q);
  const filter = first(params.status, "aktif");
  const from = first(params.from, `${today().slice(0, 7)}-01`);
  const to = first(params.to, today());
  const borrowers = await query(`SELECT b.*,u.name AS unit_name FROM borrowers b LEFT JOIN units u ON u.id=b.unit_id WHERE EXISTS(SELECT 1 FROM loans l WHERE l.borrower_id=b.id)${filter === "semua" ? "" : ` AND b.active=${filter === "nonaktif" ? 0 : 1}`} AND ($1='' OR b.name ILIKE $2 OR b.employee_id ILIKE $2 OR u.name ILIKE $2) ORDER BY b.name LIMIT 500`, [q, `%${q}%`]);
  const periodValid = isDate(from) && isDate(to) && from <= to;
  const report = periodValid ? await query("SELECT l.id AS loan_id,l.loan_date,l.due_date,l.purpose,b.name AS borrower_name,b.employee_id,u.name AS unit_name,d.right_number,c.name AS category,d.district,d.village,li.returned_at,CASE WHEN li.returned_at IS NOT NULL THEN 'Selesai' WHEN l.due_date<CURRENT_DATE THEN 'Terlambat' ELSE 'Dipinjam' END AS item_status FROM loans l JOIN borrowers b ON b.id=l.borrower_id LEFT JOIN units u ON u.id=b.unit_id JOIN loan_items li ON li.loan_id=l.id JOIN documents d ON d.id=li.document_id JOIN right_categories c ON c.id=d.right_category_id WHERE l.loan_date BETWEEN $1 AND $2 ORDER BY l.loan_date DESC,l.id DESC,b.name,d.right_number", [from, to]) : [];
  const rows = report.map((r) => [r.loan_id, formatDate(text(r.loan_date)), text(r.borrower_name), text(r.employee_id || "—"), text(r.unit_name || "—"), text(r.right_number), text(r.category), text(r.district), text(r.village), formatDate(text(r.due_date)), formatDate(text(r.returned_at)), text(r.item_status), text(r.purpose)]);
  const listRows = borrowers.map((b) => [
    <Link href={pageHref("borrower-detail", { id: num(b.id) })} key="name"><strong>{text(b.name)}</strong></Link>,
    text(b.employee_id || "—"), text(b.unit_name || "—"), text(b.phone || "—"),
    <Badge key="status" status={num(b.active) ? "Tersedia" : "Nonaktif"} />,
    <ButtonLink href={pageHref("borrower-detail", { id: num(b.id) })} className="button small" key="detail">Detail</ButtonLink>,
  ]);
  return <>
    <PageHead title="Peminjam" subtitle="Daftar peminjam yang memiliki riwayat transaksi." />
    <section className="panel borrower-period-controls"><h2>Ekspor transaksi peminjam</h2><form method="get" className="filter-bar report-filter"><input type="hidden" name="page" value="borrowers" />
      <label>Dari tanggal<input type="date" name="from" required defaultValue={from} /></label><label>Sampai tanggal<input type="date" name="to" required defaultValue={to} /></label><button className="button primary">Tampilkan periode</button>
      {periodValid && <><a className="button" href={csvHref({ type: "borrowers", from, to })}>Ekspor CSV</a><PrintButton /></>}
    </form>{!periodValid && <Notice message="Periode tanggal tidak valid. Pastikan tanggal awal tidak melewati tanggal akhir." type="error" />}</section>
    <section className="panel borrower-period-report"><h2>Laporan transaksi peminjam</h2><p className="muted">Periode {periodValid ? `${formatDate(from)} sampai ${formatDate(to)}` : "tanggal tidak valid"} · {rows.length} baris transaksi dokumen.</p>
      <Table headers={["No. transaksi", "Tanggal pinjam", "Nama peminjam", "NIP/ID", "Unit kerja / seksi", "Nomor hak", "Jenis hak", "Kecamatan", "Desa/Kelurahan", "Tenggat kembali", "Tanggal kembali", "Status", "Keperluan"]} rows={rows} empty="Tidak ada transaksi peminjam pada periode ini." />
    </section>
    <form method="get" className="filter-bar"><input type="hidden" name="page" value="borrowers" /><input type="hidden" name="from" value={from} /><input type="hidden" name="to" value={to} />
      <label className="search-field">Cari nama, NIP/ID, atau unit<input name="q" defaultValue={q} placeholder="Ketik kata kunci…" /></label>
      <label>Status<select name="status" defaultValue={filter}><option value="aktif">Aktif</option><option value="nonaktif">Nonaktif</option><option value="semua">Semua</option></select></label><button className="button">Terapkan</button>
    </form>
    <section className="panel borrowers-list-panel"><div className="section-title"><h2>Daftar peminjam</h2><span className="muted">{borrowers.length} hasil</span></div>
      <Table headers={["Nama", "NIP/ID", "Unit kerja / seksi", "Kontak", "Status", ""]} rows={listRows} empty="Belum ada peminjam." />
    </section>
  </>;
}

async function BorrowerFormPage({ id }: { id: number }) {
  if (!id) redirect("/?page=borrowers");
  const borrower = (await query("SELECT * FROM borrowers WHERE id=$1", [id]))[0];
  if (!borrower) notFound();
  const units = await query("SELECT id,name FROM units WHERE active=1 ORDER BY name");
  return <>
    <PageHead title="Ubah peminjam" subtitle="Simpan hanya informasi kontak yang diperlukan." />
    <Form action="save_borrower" className="panel form-panel narrow"><input type="hidden" name="id" value={id} /><div className="form-grid">
      <label className="full">Nama lengkap<input name="name" maxLength={160} required defaultValue={text(borrower.name)} /></label>
      <label>NIP/ID (opsional)<input name="employee_id" maxLength={60} defaultValue={text(borrower.employee_id)} /></label>
      <label>Unit kerja / seksi<select name="unit_id" defaultValue={num(borrower.unit_id) || ""}><option value="">Pilih unit</option>{units.map((unit) => <option key={unit.id} value={unit.id}>{text(unit.name)}</option>)}</select></label>
      <label>Nomor kontak<input name="phone" maxLength={40} defaultValue={text(borrower.phone)} /></label>
      <label>Email (opsional)<input type="email" name="email" maxLength={190} defaultValue={text(borrower.email)} /></label>
    </div><div className="form-actions"><button className="button primary">Simpan peminjam</button><ButtonLink href={pageHref("borrower-detail", { id })}>Batal</ButtonLink></div></Form>
  </>;
}

async function BorrowerDetail({ id }: { id: number }) {
  const borrower = (await query("SELECT b.*,u.name AS unit_name FROM borrowers b LEFT JOIN units u ON u.id=b.unit_id WHERE b.id=$1", [id]))[0];
  if (!borrower) notFound();
  const [history, units] = await Promise.all([
    query("SELECT l.id,l.loan_date,l.due_date,l.purpose,(SELECT COUNT(*) FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL) AS active_items FROM loans l WHERE l.borrower_id=$1 ORDER BY l.loan_date DESC", [id]),
    query("SELECT id,name FROM units WHERE active=1 ORDER BY name"),
  ]);
  return <>
    <PageHead title={text(borrower.name)} subtitle="Riwayat peminjaman dan informasi kontak." actions={<ButtonLink href={pageHref("borrower-form", { id })}>Ubah</ButtonLink>} />
    <section className="panel"><div className="section-title"><h2>Informasi peminjam</h2><Badge status={num(borrower.active) ? "Tersedia" : "Nonaktif"} /></div>
      <dl className="detail-grid">{[["Nama lengkap", borrower.name], ["NIP/ID", borrower.employee_id || "—"], ["Unit kerja / seksi", borrower.unit_name || "—"], ["Nomor kontak", borrower.phone || "—"], ["Email", borrower.email || "—"]].map(([label, v]) => <div key={String(label)}><dt>{label}</dt><dd>{text(v)}</dd></div>)}</dl>
      <div className="form-actions"><ActionButton action="toggle_borrower" fields={{ id }} label={num(borrower.active) ? "Nonaktifkan" : "Aktifkan"} className={num(borrower.active) ? "button danger" : "button"} />
        {history.length === 0 && <ActionButton action="delete_borrower" fields={{ id }} label="Hapus" className="button danger" />}
      </div>
    </section>
    <section className="panel"><h2>Riwayat peminjaman</h2><Table headers={["No. transaksi", "Tanggal pinjam", "Tenggat", "Dokumen aktif", "Keperluan", "Status"]} rows={history.map((loan) => [
      <Link href={pageHref("loan-detail", { id: num(loan.id) })} key="id">#{num(loan.id)}</Link>, formatDate(text(loan.loan_date)), formatDate(text(loan.due_date)), num(loan.active_items), text(loan.purpose),
      <Badge key="status" status={loanStatus(loan)} />,
    ])} empty="Belum ada riwayat peminjaman." /></section>
    <section className="panel"><h2>Ubah informasi kontak</h2><Form action="save_borrower" className="form-grid"><input type="hidden" name="id" value={id} />
      <label>Nama<input name="name" required maxLength={160} defaultValue={text(borrower.name)} /></label><label>NIP/ID<input name="employee_id" maxLength={60} defaultValue={text(borrower.employee_id)} /></label>
      <label>Unit<select name="unit_id" defaultValue={num(borrower.unit_id) || ""}><option value="">Pilih unit</option>{units.map((u) => <option key={u.id} value={u.id}>{text(u.name)}</option>)}</select></label>
      <label>Telepon<input name="phone" maxLength={40} defaultValue={text(borrower.phone)} /></label><label>Email<input name="email" type="email" maxLength={190} defaultValue={text(borrower.email)} /></label>
      <div className="full"><button className="button primary">Simpan peminjam</button></div>
    </Form></section>
  </>;
}

async function Loans({ params }: { params: SearchParams }) {
  const filter = first(params.filter, "Aktif");
  const q = first(params.q);
  const clauses: string[] = [];
  if (filter === "Aktif") clauses.push("EXISTS (SELECT 1 FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL)");
  else if (filter === "Selesai") clauses.push("NOT EXISTS (SELECT 1 FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL)");
  else if (filter === "Terlambat") clauses.push("l.due_date<CURRENT_DATE AND EXISTS (SELECT 1 FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL)");
  if (q) clauses.push("(b.name ILIKE $1 OR d.right_number ILIKE $1)");
  const loans = await query(`SELECT l.*,b.name AS borrower_name,(SELECT COUNT(*) FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL) AS active_items,STRING_AGG(d.right_number,', ' ORDER BY d.right_number) AS documents FROM loans l JOIN borrowers b ON b.id=l.borrower_id JOIN loan_items x ON x.loan_id=l.id JOIN documents d ON d.id=x.document_id${clauses.length ? ` WHERE ${clauses.join(" AND ")}` : ""} GROUP BY l.id,b.name ORDER BY l.loan_date DESC LIMIT 500`, q ? [`%${q}%`] : []);
  return <>
    <PageHead title="Peminjaman" subtitle="Transaksi aktif, keterlambatan, dan riwayat." actions={<ButtonLink href="/?page=loan-form" className="button primary">Peminjaman baru</ButtonLink>} />
    <form method="get" className="filter-bar"><input type="hidden" name="page" value="loans" /><label className="search-field">Cari peminjam atau nomor hak<input name="q" defaultValue={q} placeholder="Ketik kata kunci…" /></label><label>Status<select name="filter" defaultValue={filter}>{["Aktif", "Terlambat", "Selesai", "Semua"].map((item) => <option key={item}>{item}</option>)}</select></label><button className="button">Terapkan</button></form>
    <section className="panel"><div className="section-title"><h2>Daftar transaksi</h2><span className="muted">{loans.length} hasil</span></div>
      <Table headers={["Peminjam", "Dokumen", "Tanggal pinjam", "Tenggat", "Status", ""]} rows={loans.map((loan) => {
        const active = num(loan.active_items) > 0;
        const destination = pageHref(active ? "loan-manage" : "loan-detail", { id: num(loan.id) });
        return [
          <Link href={pageHref("loan-detail", { id: num(loan.id) })} key="borrower"><strong>{text(loan.borrower_name)}</strong></Link>,
          text(loan.documents), formatDate(text(loan.loan_date)), formatDate(text(loan.due_date)), <Badge key="status" status={loanStatus(loan)} />,
          <ButtonLink href={destination} className="button small" key="manage">{active ? "Kelola" : "Detail"}</ButtonLink>,
        ];
      })} empty="Belum ada transaksi." />
    </section>
  </>;
}

async function LoanFormPage() {
  const [units, documents, days] = await Promise.all([
    query("SELECT id,name FROM units WHERE active=1 ORDER BY name"),
    query("SELECT d.id,d.right_number,d.district,d.village,c.name AS category FROM documents d JOIN right_categories c ON c.id=d.right_category_id WHERE d.status='Tersedia' ORDER BY d.district,d.village,d.right_number LIMIT 1000"),
    query<{ setting_value: string }>("SELECT setting_value FROM settings WHERE setting_key='default_loan_days'"),
  ]);
  const duration = Number(days[0]?.setting_value || 14);
  const due = new Date(new Date(`${today()}T00:00:00+07:00`).getTime() + duration * 86400000).toLocaleDateString("en-CA", { timeZone: "Asia/Jakarta" });
  return <>
    <PageHead title="Peminjaman baru" subtitle="Daftarkan peminjam dan pilih dokumen untuk transaksi ini." />
    <Form action="create_loan" className="panel form-panel">
      <div className="form-section"><h2>Data peminjam</h2><p className="muted">Data akan dicatat pada daftar peminjam setelah transaksi berhasil. Jika NIP/ID sudah terdaftar, riwayat transaksi akan digabung ke peminjam tersebut.</p>
        <div className="form-grid"><label className="full">Nama lengkap<input name="name" maxLength={160} required /></label><label>NIP/ID (opsional)<input name="employee_id" maxLength={60} /></label>
          <label>Unit kerja / seksi<select name="unit_id"><option value="">Pilih unit</option>{units.map((unit) => <option key={unit.id} value={unit.id}>{text(unit.name)}</option>)}</select></label>
          <label>Nomor kontak<input name="phone" maxLength={40} /></label><label>Email (opsional)<input type="email" name="email" maxLength={190} /></label>
          <label>Tanggal pinjam<input type="date" name="loan_date" defaultValue={today()} required /></label><label>Tenggat kembali<input type="date" name="due_date" defaultValue={due} required /></label>
          <label className="full">Keperluan<input name="purpose" maxLength={255} required /></label><label className="full">Catatan<textarea name="notes" rows={2} maxLength={5000} /></label>
        </div>
      </div>
      <div className="form-section"><h2>Pilih dokumen <small>({documents.length} tersedia)</small></h2><DocumentPicker documents={documents} /></div>
      <div className="form-actions"><button className="button primary">Simpan peminjaman</button><ButtonLink href="/?page=loans">Batal</ButtonLink></div>
    </Form>
  </>;
}

async function LoanDetail({ id, manage }: { id: number; manage: boolean }) {
  const loan = (await query("SELECT l.*,b.name AS borrower_name,b.id AS borrower_id,u.name AS unit_name,a.name AS admin_name,(SELECT COUNT(*) FROM loan_items li WHERE li.loan_id=l.id AND li.returned_at IS NULL) AS active_items,(SELECT COUNT(*) FROM loan_extensions le WHERE le.loan_id=l.id) AS extension_count FROM loans l JOIN borrowers b ON b.id=l.borrower_id LEFT JOIN units u ON u.id=b.unit_id JOIN admins a ON a.id=l.admin_id WHERE l.id=$1", [id]))[0];
  if (!loan) notFound();
  const [items, extensions, maximumSetting] = await Promise.all([
    query("SELECT li.id AS item_id,li.document_id,d.right_number,c.name AS category,d.district,d.village,li.returned_at,li.return_condition FROM loan_items li JOIN documents d ON d.id=li.document_id JOIN right_categories c ON c.id=d.right_category_id WHERE li.loan_id=$1 ORDER BY li.id", [id]),
    query("SELECT le.*,a.name AS admin_name FROM loan_extensions le JOIN admins a ON a.id=le.admin_id WHERE le.loan_id=$1 ORDER BY le.created_at DESC", [id]),
    query<{ setting_value: string }>("SELECT setting_value FROM settings WHERE setting_key='max_extensions'"),
  ]);
  const status = loanStatus(loan);
  const maximum = Number(maximumSetting[0]?.setting_value || 2);
  const activeItems = num(loan.active_items) > 0;
  const itemRows = items.map((item) => [
    <Link href={pageHref("document-detail", { id: num(item.document_id) })} key="doc">{text(item.right_number)}</Link>,
    text(item.category), `${text(item.district)}, ${text(item.village)}`, formatDate(text(item.returned_at)), text(item.return_condition || "—"),
    <Badge key="status" status={item.returned_at ? item.return_condition === "Hilang" ? "Hilang" : "Selesai" : status} />,
  ]);
  return <>
    <PageHead title="Detail peminjaman" subtitle={`Transaksi #${id} · ${text(loan.borrower_name)}`} actions={<><ButtonLink href="/?page=loans">Kembali ke daftar</ButtonLink>{activeItems && !manage && <ButtonLink href={pageHref("loan-manage", { id })} className="button primary">Kelola transaksi</ButtonLink>}</>} />
    <section className="panel"><div className="section-title"><h2>Informasi transaksi</h2><Badge status={status} /></div>
      <dl className="detail-grid"><div><dt>Peminjam</dt><dd><Link href={pageHref("borrower-detail", { id: num(loan.borrower_id) })}>{text(loan.borrower_name)}</Link></dd></div><div><dt>Unit kerja</dt><dd>{text(loan.unit_name || "—")}</dd></div><div><dt>Admin pencatat</dt><dd>{text(loan.admin_name)}</dd></div><div><dt>Tanggal pinjam</dt><dd>{formatDate(text(loan.loan_date))}</dd></div><div><dt>Tenggat kembali</dt><dd>{formatDate(text(loan.due_date))}</dd></div><div><dt>Keperluan</dt><dd>{text(loan.purpose)}</dd></div><div className="full"><dt>Catatan</dt><dd>{text(loan.notes || "—")}</dd></div></dl>
    </section>
    <section className="panel"><h2>Dokumen dalam transaksi</h2><Table headers={["Nomor hak", "Jenis hak", "Lokasi", "Tanggal kembali", "Kondisi kembali", "Status"]} rows={itemRows} empty="Tidak ada item dokumen." /></section>
    {manage && activeItems && <>
      <section className="panel"><h2>Catat pengembalian</h2><p className="muted">Pilih satu atau beberapa dokumen yang dikembalikan. Dokumen lain tetap berstatus dipinjam.</p>
        <Form action="return_items"><input type="hidden" name="loan_id" value={id} /><div className="return-list">
          {items.filter((item) => !item.returned_at).map((item) => <article className="return-item" key={item.item_id}>
            <label className="return-check"><input type="checkbox" name="item_ids[]" value={item.item_id} /><strong>{text(item.category)} · {text(item.right_number)}</strong></label>
            <div className="form-grid"><label>Tanggal kembali<input type="date" name={`return_date[${item.item_id}]`} defaultValue={today()} /></label>
              <label>Kondisi saat kembali<select name={`return_condition[${item.item_id}]`}>{["Baik", "Perlu perbaikan", "Rusak", "Hilang"].map((condition) => <option key={condition}>{condition}</option>)}</select></label>
              <label className="full">Catatan<input name={`return_note[${item.item_id}]`} maxLength={1000} /></label>
            </div>
          </article>)}
        </div><button className="button primary">Simpan pengembalian</button></Form>
      </section>
      {num(loan.extension_count) < maximum
        ? <section className="panel"><h2>Perpanjang tenggat</h2><p className="muted">Perpanjangan {num(loan.extension_count) + 1} dari {maximum}.</p>
          <Form action="extend_loan" className="form-grid"><input type="hidden" name="loan_id" value={id} /><label>Tenggat baru<input type="date" name="due_date" min={new Date(new Date(`${text(loan.due_date).slice(0, 10)}T00:00:00Z`).getTime() + 86400000).toISOString().slice(0, 10)} required /></label><label>Alasan perpanjangan<input name="reason" maxLength={255} required /></label><div className="full"><button className="button primary">Simpan perpanjangan</button></div></Form>
        </section>
        : <Notice message={`Batas perpanjangan ${maximum} kali telah tercapai.`} type="warning" />}
    </>}
    {extensions.length > 0 && <section className="panel"><h2>Riwayat perpanjangan</h2><Table headers={["Tenggat sebelumnya", "Tenggat baru", "Alasan", "Admin"]} rows={extensions.map((e) => [formatDate(text(e.old_due_date)), formatDate(text(e.new_due_date)), text(e.reason), text(e.admin_name)])} /></section>}
  </>;
}

async function Reports({ params }: { params: SearchParams }) {
  const availableReports = ["Ketersediaan BTSU", "BTSU masih dipinjam", "Peminjaman per periode", "Peminjaman per dokumen", "Peminjaman per peminjam", "Peminjaman per unit", "Keterlambatan", "Dokumen hilang", "Aktivitas perubahan"];
  const chosen = first(params.type, availableReports[0]);
  const type = availableReports.includes(chosen) ? chosen : availableReports[0];
  let from = first(params.from);
  let to = first(params.to);
  if (!isDate(from)) from = "";
  if (!isDate(to)) to = "";
  let headers: string[] = [];
  let rows: ReactNode[][] = [];

  if (type === "Aktivitas perubahan") {
    headers = ["Waktu", "Admin", "Aktivitas", "Detail", "Alamat IP"];
    const values: string[] = [];
    const where: string[] = [];
    if (from) { values.push(from); where.push(`a.created_at::date >= $${values.length}`); }
    if (to) { values.push(to); where.push(`a.created_at::date <= $${values.length}`); }
    const data = await query(`SELECT a.created_at,ad.name,a.action,a.detail,a.ip_address FROM audit_logs a LEFT JOIN admins ad ON ad.id=a.admin_id${where.length ? ` WHERE ${where.join(" AND ")}` : ""} ORDER BY a.created_at DESC LIMIT 5000`, values);
    rows = data.map((r) => [new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" }).format(new Date(text(r.created_at))), text(r.name || "Akun dihapus"), text(r.action), text(r.detail), text(r.ip_address)]);
  } else if (type === "Ketersediaan BTSU") {
    headers = ["Kecamatan", "Desa/Kelurahan", "Jenis hak", "Nomor hak", "Lokasi arsip", "Status"];
    const data = await query(`SELECT d.*,c.name AS category_name,${statusSql} AS effective_status FROM documents d JOIN right_categories c ON c.id=d.right_category_id ORDER BY d.district,d.village,d.right_number`);
    rows = data.map((d) => [text(d.district), text(d.village), text(d.category_name), text(d.right_number), `Lantai ${d.floor}, Lemari ${d.cabinet}, Rak ${d.rack}, Album ${d.album || "—"}`, text(d.effective_status)]);
  } else if (type === "Dokumen hilang") {
    headers = ["Kecamatan", "Desa/Kelurahan", "Jenis hak", "Nomor hak", "Lokasi arsip"];
    const data = await query("SELECT d.*,c.name AS category_name FROM documents d JOIN right_categories c ON c.id=d.right_category_id WHERE d.status='Hilang' ORDER BY d.district,d.village,d.right_number");
    rows = data.map((d) => [text(d.district), text(d.village), text(d.category_name), text(d.right_number), `Lantai ${d.floor}, Lemari ${d.cabinet}, Rak ${d.rack}`]);
  } else {
    const where: string[] = [];
    const values: string[] = [];
    if (["BTSU masih dipinjam", "Keterlambatan"].includes(type)) where.push("li.returned_at IS NULL");
    if (type === "Keterlambatan") where.push("l.due_date<CURRENT_DATE");
    if (from) { values.push(from); where.push(`l.loan_date >= $${values.length}`); }
    if (to) { values.push(to); where.push(`l.loan_date <= $${values.length}`); }
    const data = await query(`SELECT l.id,l.loan_date,l.due_date,b.name AS borrower_name,u.name AS unit_name,d.district,d.village,d.right_number,c.name AS category,li.returned_at FROM loans l JOIN borrowers b ON b.id=l.borrower_id LEFT JOIN units u ON u.id=b.unit_id JOIN loan_items li ON li.loan_id=l.id JOIN documents d ON d.id=li.document_id JOIN right_categories c ON c.id=d.right_category_id${where.length ? ` WHERE ${where.join(" AND ")}` : ""} ORDER BY l.loan_date DESC`, values);
    if (type === "BTSU masih dipinjam") {
      headers = ["Kecamatan", "Desa/Kelurahan", "Jenis hak", "Nomor hak", "Nama peminjam", "Unit kerja / seksi", "Tanggal pinjam"];
      rows = data.map((r) => [text(r.district), text(r.village), text(r.category), text(r.right_number), text(r.borrower_name), text(r.unit_name || "—"), formatDate(text(r.loan_date))]);
    } else if (type === "Keterlambatan") {
      headers = ["Nama peminjam", "Nomor hak", "Tanggal pinjam", "Tenggat", "Hari terlambat", "Unit kerja"];
      rows = data.map((r) => [text(r.borrower_name), text(r.right_number), formatDate(text(r.loan_date)), formatDate(text(r.due_date)), String(Math.max(0, Math.floor((Date.now() - new Date(`${text(r.due_date).slice(0, 10)}T00:00:00Z`).getTime()) / 86400000))), text(r.unit_name || "—")]);
    } else {
      headers = ["Tanggal pinjam", "Nama peminjam", "Nomor hak", "Jenis hak", "Unit kerja", "Tanggal kembali", "Status"];
      rows = data.map((r) => [formatDate(text(r.loan_date)), text(r.borrower_name), text(r.right_number), text(r.category), text(r.unit_name || "—"), formatDate(text(r.returned_at)), text(r.returned_at ? "Selesai" : text(r.due_date).slice(0, 10) < today() ? "Terlambat" : "Dipinjam")]);
    }
  }
  return <>
    <PageHead title="Laporan" subtitle="Tinjau dan unduh laporan inventaris serta peminjaman." />
    <section className="panel"><form method="get" className="filter-bar report-filter"><input type="hidden" name="page" value="reports" />
      <label>Jenis laporan<select name="type" defaultValue={type}>{availableReports.map((item) => <option key={item}>{item}</option>)}</select></label>
      <label>Dari tanggal<input type="date" name="from" defaultValue={from} /></label><label>Sampai tanggal<input type="date" name="to" defaultValue={to} /></label>
      <button className="button primary">Tampilkan</button><a className="button" href={csvHref({ type: "report", report: type, from, to })}>Unduh CSV</a><PrintButton />
    </form></section>
    <section className="panel"><div className="section-title"><h2>{type}</h2><span className="muted">{rows.length} baris</span></div><Table headers={headers} rows={rows} empty="Tidak ada data pada laporan ini." /></section>
  </>;
}

async function Settings({ admin }: { admin: AdminSession }) {
  const [admins, units, categories, districts, settings] = await Promise.all([
    query("SELECT id,name,username,role,active,last_login_at FROM admins ORDER BY name"),
    query("SELECT id,name,active FROM units ORDER BY name"),
    query("SELECT id,name,active FROM right_categories ORDER BY name"),
    query("SELECT id,name,active FROM districts ORDER BY name"),
    query("SELECT setting_key,setting_value FROM settings"),
  ]);
  const settingValue = (key: string, fallback: string) => text(settings.find((s) => s.setting_key === key)?.setting_value || fallback);
  return <>
    <PageHead title="Pengaturan" subtitle="Kelola akun admin, master data, dan aturan peminjaman." />
    <section className="panel"><h2>Akun admin</h2>
      <Form action="save_admin" className="form-grid admin-form"><label>Nama<input name="name" maxLength={120} required /></label><label>Username<input name="username" minLength={3} maxLength={60} pattern="[a-zA-Z0-9._-]+" required /></label><label>Kata sandi awal<input type="password" name="password" minLength={12} required autoComplete="new-password" /></label><label>Peran<select name="role"><option>Admin</option><option>Super Admin</option></select></label><div className="full"><button className="button primary">Tambah admin</button></div></Form>
      <Table headers={["Nama", "Username", "Peran", "Status", "Login terakhir", "Aksi"]} rows={admins.map((a) => [
        text(a.name), text(a.username), text(a.role), <Badge key="status" status={num(a.active) ? "Tersedia" : "Nonaktif"} />,
        a.last_login_at ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" }).format(new Date(text(a.last_login_at))) : "Belum pernah",
        <div className="admin-actions" key="actions">{num(a.id) === admin.id
          ? <span className="muted">Akun Anda</span>
          : <><ActionButton action="toggle_admin" fields={{ id: num(a.id) }} label={num(a.active) ? "Nonaktifkan" : "Aktifkan"} className={`button small${num(a.active) ? " danger" : ""}`} />
            <details><summary className="button small">Reset sandi</summary><Form action="reset_admin_password" className="reset-form"><input type="hidden" name="id" value={a.id} /><label>Kata sandi baru<input type="password" name="new_password" minLength={12} required autoComplete="new-password" /></label><button className="button primary small">Simpan sandi</button></Form></details></>}
        </div>,
      ])} />
    </section>
    {[
      { title: "Unit kerja / seksi", type: "unit", data: units },
      { title: "Kategori jenis hak", type: "category", data: categories },
      { title: "Kecamatan Kota Semarang", type: "district", data: districts },
    ].map(({ title, type, data }) => <section className="panel" key={type}><div className="section-title"><h2>{title}</h2></div>
      <Form action="add_master" className="master-form"><input type="hidden" name="master_type" value={type} /><label>Tambah {title.toLocaleLowerCase("id")}<input name="name" maxLength={120} required /></label><button className="button">Tambah</button></Form>
      <Table headers={["Nama", "Status", "Aksi"]} rows={data.map((item) => [text(item.name), <Badge key="status" status={num(item.active) ? "Tersedia" : "Nonaktif"} />, num(item.active) ? <ActionButton key="action" action="deactivate_master" fields={{ master_type: type, id: num(item.id) }} label="Nonaktifkan" className="button small danger" /> : "—"])} />
    </section>)}
    <section className="panel"><h2>Aturan peminjaman</h2><Form action="save_setting" className="form-grid"><label>Durasi standar (hari)<input type="number" min={1} max={365} name="default_loan_days" defaultValue={settingValue("default_loan_days", "14")} required /></label><label>Maksimum perpanjangan<input type="number" min={0} max={10} name="max_extensions" defaultValue={settingValue("max_extensions", "2")} required /></label><div className="full"><button className="button primary">Simpan aturan</button></div></Form></section>
  </>;
}

function PasswordPage() {
  return <>
    <PageHead title="Ubah kata sandi" subtitle="Gunakan minimal 12 karakter untuk kata sandi baru." />
    <Form action="change_password" className="panel form-panel narrow stack"><label>Kata sandi saat ini<input type="password" name="current_password" required autoComplete="current-password" /></label><label>Kata sandi baru<input type="password" name="new_password" minLength={12} required autoComplete="new-password" /></label><label>Ulangi kata sandi baru<input type="password" name="confirm_password" minLength={12} required autoComplete="new-password" /></label><button className="button primary">Simpan kata sandi</button></Form>
  </>;
}

export default async function Page({ searchParams }: { searchParams: Promise<SearchParams> }) {
  const params = await searchParams;
  const page = first(params.page, "dashboard");
  if (["login", "setup", "forgot"].includes(page)) return renderAuthPage(page, params);
  const admin = await requireAdmin();
  if (page === "settings" && admin.role !== "Super Admin") redirect("/?page=dashboard&notice=Tidak%20memiliki%20akses&type=error");
  const notice = first(params.notice);
  const messageType = first(params.type);
  let content: ReactNode;

  switch (page) {
    case "dashboard": content = <Dashboard admin={admin} />; break;
    case "documents": content = await Documents({ params }); break;
    case "document-form": content = await DocumentFormPage({ params }); break;
    case "document-import": content = await DocumentImportPage(); break;
    case "document-detail": content = await DocumentDetail({ id: Number(first(params.id)) || 0 }); break;
    case "borrowers": content = await Borrowers({ params }); break;
    case "borrower-form": content = await BorrowerFormPage({ id: Number(first(params.id)) || 0 }); break;
    case "borrower-detail": content = await BorrowerDetail({ id: Number(first(params.id)) || 0 }); break;
    case "loans": content = await Loans({ params }); break;
    case "loan-form": content = await LoanFormPage(); break;
    case "loan-detail": content = await LoanDetail({ id: Number(first(params.id)) || 0, manage: false }); break;
    case "loan-manage": content = await LoanDetail({ id: Number(first(params.id)) || 0, manage: true }); break;
    case "reports": content = await Reports({ params }); break;
    case "settings": content = await Settings({ admin }); break;
    case "password": content = <PasswordPage />; break;
    default: notFound();
  }
  return <PageShell admin={admin} page={page}><Notice message={notice} type={messageType} />{content}</PageShell>;
}
