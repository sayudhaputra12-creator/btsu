"use server";

import bcrypt from "bcryptjs";
import { parse } from "csv-parse/sync";
import { cookies, headers } from "next/headers";
import { redirect } from "next/navigation";
import { createSession, requireAdmin, requireSuperAdmin, sessionCookie, type AdminSession } from "./lib/auth";
import { query, transaction } from "./lib/db";

class UserError extends Error {}

function value(form: FormData, key: string, maximum = 500): string {
  const text = String(form.get(key) ?? "").trim();
  if (text.length > maximum) throw new UserError(`Isian ${key} terlalu panjang.`);
  return text;
}

function integer(form: FormData, key: string, fallback = 0): number {
  const raw = String(form.get(key) ?? "");
  if (!raw && fallback) return fallback;
  const number = Number(raw);
  if (!Number.isSafeInteger(number) || number < 0) throw new UserError(`Isian ${key} tidak valid.`);
  return number;
}

function validDate(date: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return false;
  const parsed = new Date(`${date}T00:00:00Z`);
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === date;
}

function today(): string {
  return new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Jakarta" });
}

function redirectTo(path: string, message: string, type = "success"): never {
  const separator = path.includes("?") ? "&" : "?";
  redirect(`${path}${separator}notice=${encodeURIComponent(message)}&type=${encodeURIComponent(type)}`);
}

function returnPath(form: FormData, fallback: string): string {
  const path = String(form.get("return_to") ?? "");
  return path.startsWith("/") && !path.startsWith("//") ? path : fallback;
}

async function audit(adminId: number | null, action: string, detail = "") {
  const ip = (await headers()).get("x-forwarded-for")?.split(",")[0]?.trim().slice(0, 45) || null;
  await query(
    "INSERT INTO audit_logs (admin_id, action, detail, ip_address) VALUES ($1, $2, $3, $4)",
    [adminId, action, detail.slice(0, 500), ip],
  );
}

async function setting(key: string, fallback: string): Promise<string> {
  const rows = await query<{ setting_value: string }>("SELECT setting_value FROM settings WHERE setting_key = $1", [key]);
  return rows[0]?.setting_value || fallback;
}

function databaseError(error: unknown): string {
  if (error instanceof UserError) return error.message;
  const code = (error as { code?: string })?.code;
  if (code === "23505") return "Data tersebut sudah terdaftar.";
  console.error(error);
  return "Perubahan tidak dapat disimpan. Periksa input dan koneksi database.";
}

function normalizeUsername(text: string) {
  return text.toLocaleLowerCase("id");
}

export async function dispatch(form: FormData) {
  const action = String(form.get("action") ?? "");
  let destination = returnPath(form, "/?page=dashboard");
  let message = "Perubahan berhasil disimpan.";
  let messageType = "success";
  let signedInAdmin: AdminSession | null = null;

  if (action !== "setup" && action !== "login") {
    signedInAdmin = await requireAdmin();
    if (["save_admin", "toggle_admin", "reset_admin_password", "save_setting", "add_master", "deactivate_master"].includes(action)) {
      await requireSuperAdmin();
    }
  }

  try {
    if (action === "setup") {
      const admins = await query<{ count: string }>("SELECT COUNT(*)::text AS count FROM admins");
      if (Number(admins[0]?.count || 0) > 0) throw new UserError("Akun admin sudah tersedia. Silakan masuk.");
      const name = value(form, "name", 120);
      const username = normalizeUsername(value(form, "username", 60));
      const password = String(form.get("password") ?? "");
      if (!name || !/^[a-z0-9._-]{3,60}$/.test(username)) throw new UserError("Nama wajib diisi dan username harus 3–60 karakter.");
      if (password.length < 12) throw new UserError("Kata sandi minimal 12 karakter.");
      await query(
        "INSERT INTO admins (name, username, password_hash, role) VALUES ($1, $2, $3, 'Super Admin')",
        [name, username, await bcrypt.hash(password, 12)],
      );
      await audit(null, "Inisialisasi akun Super Admin", username);
      destination = "/?page=login";
      message = "Akun Super Admin berhasil dibuat. Silakan masuk.";
    } else if (action === "login") {
      destination = "/?page=login";
      const username = normalizeUsername(value(form, "username", 60));
      const password = String(form.get("password") ?? "");
      if (!username || !password) throw new UserError("Username dan kata sandi wajib diisi.");
      const attempts = await query<{ count: string }>(
        "SELECT COUNT(*)::text AS count FROM login_attempts WHERE username = $1 AND failed_at >= CURRENT_TIMESTAMP - INTERVAL '15 minutes'",
        [username],
      );
      if (Number(attempts[0]?.count || 0) >= 5) throw new UserError("Terlalu banyak percobaan masuk. Coba kembali 15 menit lagi.");
      const rows = await query<{ id: number; name: string; username: string; role: "Admin" | "Super Admin"; active: number; password_hash: string }>(
        "SELECT id, name, username, role, active, password_hash FROM admins WHERE username = $1 AND active = 1 LIMIT 1",
        [username],
      );
      const admin = rows[0];
      const hash = admin?.password_hash?.replace(/^\$2y\$/, "$2b$");
      if (!admin || !hash || !(await bcrypt.compare(password, hash))) {
        await query("INSERT INTO login_attempts (username) VALUES ($1)", [username]);
        throw new UserError("Username atau kata sandi tidak sesuai.");
      }
      await query("DELETE FROM login_attempts WHERE username = $1", [username]);
      await query("UPDATE admins SET last_login_at = CURRENT_TIMESTAMP WHERE id = $1", [admin.id]);
      (await cookies()).set(sessionCookie, await createSession(admin), {
        httpOnly: true,
        secure: process.env.NODE_ENV === "production",
        sameSite: "lax",
        path: "/",
        maxAge: 30 * 60,
      });
      await audit(Number(admin.id), "Masuk", admin.username);
      destination = "/?page=dashboard";
      message = "Selamat datang.";
    } else if (action === "logout") {
      const admin = signedInAdmin!;
      await audit(admin.id, "Keluar");
      (await cookies()).delete(sessionCookie);
      destination = "/?page=login";
      message = "Anda telah keluar dari sistem.";
    } else {
      const admin = signedInAdmin!;
      switch (action) {
        case "save_document": {
          destination = "/?page=documents";
          const id = integer(form, "id");
          const district = value(form, "district", 100);
          const village = value(form, "village", 120);
          const category = integer(form, "right_category_id");
          const number = value(form, "right_number", 80);
          const date = value(form, "document_date", 10);
          const floor = integer(form, "floor");
          const cabinet = integer(form, "cabinet");
          const rackNumber = value(form, "rack_number", 20);
          const side = value(form, "rack_side", 1).toLowerCase();
          const condition = value(form, "condition_state", 40);
          const album = value(form, "album", 80);
          const notes = value(form, "notes", 5000);
          if (!district || !village || !number || !category) throw new UserError("Kecamatan, kelurahan, jenis hak, dan nomor hak wajib diisi.");
          if (!/^[1-9]\d*$/.test(rackNumber) || !["a", "b"].includes(side) || floor < 1 || cabinet < 1) {
            throw new UserError("Lantai, lemari, dan nomor rak harus angka positif; sisi rak harus A atau B.");
          }
          if (!["Baik", "Perlu perbaikan", "Rusak"].includes(condition)) throw new UserError("Kondisi dokumen tidak valid.");
          if (date && !validDate(date)) throw new UserError("Tanggal dokumen tidak valid.");
          const categoryRows = await query("SELECT id FROM right_categories WHERE id = $1 AND active = 1", [category]);
          if (!categoryRows.length) throw new UserError("Kategori jenis hak tidak tersedia.");
          const villageRows = await query(
            "SELECT COUNT(*)::int AS count FROM villages v JOIN districts d ON d.id = v.district_id WHERE d.active = 1 AND v.active = 1 AND d.name = $1 AND v.name = $2",
            [district, village],
          );
          const validVillage = Number((villageRows[0] as { count: number }).count) > 0;
          if (!validVillage && id) {
            const existing = await query<{ district: string; village: string }>("SELECT district, village FROM documents WHERE id = $1", [id]);
            if (existing[0]?.district.toLocaleLowerCase("id") === district.toLocaleLowerCase("id") &&
                existing[0]?.village.toLocaleLowerCase("id") === village.toLocaleLowerCase("id")) {
              // Preserve an unchanged legacy village that is no longer in the active master list.
            } else {
              throw new UserError("Kelurahan harus dipilih sesuai kecamatan.");
            }
          } else if (!validVillage) {
            throw new UserError("Kelurahan harus dipilih sesuai kecamatan.");
          }
          const rack = `${rackNumber}${side}`;
          if (id) {
            await query(
              "UPDATE documents SET district=$1, village=$2, right_category_id=$3, right_number=$4, document_date=$5, floor=$6, cabinet=$7, rack=$8, album=$9, condition_state=$10, notes=$11 WHERE id=$12",
              [district, village, category, number, date || null, floor, cabinet, rack, album || null, condition, notes || null, id],
            );
            await audit(admin.id, "Ubah dokumen", number);
          } else {
            await query(
              "INSERT INTO documents (district, village, right_category_id, right_number, document_date, floor, cabinet, rack, album, condition_state, notes) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)",
              [district, village, category, number, date || null, floor, cabinet, rack, album || null, condition, notes || null],
            );
            await audit(admin.id, "Tambah dokumen", number);
          }
          message = "Data dokumen berhasil disimpan.";
          break;
        }
        case "set_document_status": {
          const id = integer(form, "id");
          const status = value(form, "status", 20);
          destination = `/?page=document-detail&id=${id}`;
          if (!["Tersedia", "Hilang", "Nonaktif"].includes(status)) throw new UserError("Status dokumen tidak valid.");
          await transaction(async (client) => {
            const doc = await client.query("SELECT id FROM documents WHERE id=$1 FOR UPDATE", [id]);
            if (!doc.rowCount) throw new UserError("Dokumen tidak ditemukan.");
            const active = await client.query("SELECT COUNT(*)::int AS count FROM loan_items WHERE document_id=$1 AND returned_at IS NULL", [id]);
            if (Number(active.rows[0].count) > 0) throw new UserError("Dokumen masih dipinjam. Catat pengembaliannya terlebih dahulu.");
            await client.query("UPDATE documents SET status=$1 WHERE id=$2", [status, id]);
          });
          await audit(admin.id, "Ubah status dokumen", `Dokumen #${id}: ${status}`);
          message = "Status dokumen diperbarui.";
          break;
        }
        case "save_borrower": {
          destination = "/?page=borrowers";
          const id = integer(form, "id");
          const name = value(form, "name", 160);
          const employeeId = value(form, "employee_id", 60);
          const unitId = integer(form, "unit_id");
          const phone = value(form, "phone", 40);
          const email = value(form, "email", 190);
          if (!id) throw new UserError("Peminjam didaftarkan melalui formulir Peminjaman Baru.");
          if (!name) throw new UserError("Nama peminjam wajib diisi.");
          if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new UserError("Format email tidak valid.");
          if (unitId && !(await query("SELECT id FROM units WHERE id=$1 AND active=1", [unitId])).length) throw new UserError("Unit kerja tidak tersedia.");
          await query("UPDATE borrowers SET name=$1, employee_id=$2, unit_id=$3, phone=$4, email=$5 WHERE id=$6", [
            name, employeeId || null, unitId || null, phone || null, email || null, id,
          ]);
          await audit(admin.id, "Ubah peminjam", name);
          message = "Data peminjam berhasil disimpan.";
          break;
        }
        case "toggle_borrower": {
          const id = integer(form, "id");
          destination = `/?page=borrower-detail&id=${id}`;
          await query("UPDATE borrowers SET active = 1 - active WHERE id=$1", [id]);
          await audit(admin.id, "Ubah status peminjam", `Peminjam #${id}`);
          message = "Status peminjam diperbarui.";
          break;
        }
        case "delete_borrower": {
          const id = integer(form, "id");
          destination = "/?page=borrowers";
          const history = await query<{ count: number }>("SELECT COUNT(*)::int AS count FROM loans WHERE borrower_id=$1", [id]);
          if (Number(history[0].count) > 0) throw new UserError("Peminjam yang memiliki riwayat tidak dapat dihapus. Nonaktifkan sebagai gantinya.");
          await query("DELETE FROM borrowers WHERE id=$1", [id]);
          await audit(admin.id, "Hapus peminjam", `Peminjam #${id}`);
          message = "Data peminjam berhasil dihapus.";
          break;
        }
        case "create_loan": {
          destination = "/?page=loans";
          const borrowerName = value(form, "name", 160);
          const employeeId = value(form, "employee_id", 60);
          const unitId = integer(form, "unit_id");
          const phone = value(form, "phone", 40);
          const email = value(form, "email", 190);
          const loanDate = value(form, "loan_date", 10);
          const dueDate = value(form, "due_date", 10);
          const purpose = value(form, "purpose", 255);
          const notes = value(form, "notes", 5000);
          const documentIds = [...new Set(form.getAll("document_ids[]").map((id) => Number(id)).filter((id) => Number.isSafeInteger(id) && id > 0))];
          if (!borrowerName || !purpose) throw new UserError("Nama peminjam dan keperluan wajib diisi.");
          if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new UserError("Format email peminjam tidak valid.");
          if (!documentIds.length || documentIds.length > 100) throw new UserError("Pilih minimal satu dan maksimal 100 dokumen.");
          if (!validDate(loanDate) || !validDate(dueDate) || dueDate < loanDate) throw new UserError("Tanggal peminjaman atau tenggat tidak valid.");
          const loanId = await transaction(async (client) => {
            if (unitId && !(await client.query("SELECT id FROM units WHERE id=$1 AND active=1", [unitId])).rowCount) {
              throw new UserError("Unit kerja peminjam tidak tersedia.");
            }
            let borrowerId = 0;
            if (employeeId) {
              borrowerId = Number((await client.query("SELECT id FROM borrowers WHERE employee_id=$1 FOR UPDATE", [employeeId])).rows[0]?.id || 0);
            }
            if (borrowerId) {
              await client.query("UPDATE borrowers SET name=$1, unit_id=$2, phone=$3, email=$4, active=1 WHERE id=$5", [
                borrowerName, unitId || null, phone || null, email || null, borrowerId,
              ]);
            } else {
              const borrower = await client.query(
                "INSERT INTO borrowers (name, employee_id, unit_id, phone, email) VALUES ($1,$2,$3,$4,$5) RETURNING id",
                [borrowerName, employeeId || null, unitId || null, phone || null, email || null],
              );
              borrowerId = Number(borrower.rows[0].id);
            }
            const docs = await client.query("SELECT id, status FROM documents WHERE id = ANY($1::bigint[]) ORDER BY id FOR UPDATE", [documentIds]);
            if (docs.rowCount !== documentIds.length || docs.rows.some((doc) => doc.status !== "Tersedia")) {
              throw new UserError("Sebagian dokumen tidak ditemukan atau tidak tersedia.");
            }
            const loan = await client.query(
              "INSERT INTO loans (borrower_id, admin_id, loan_date, due_date, purpose, notes) VALUES ($1,$2,$3,$4,$5,$6) RETURNING id",
              [borrowerId, admin.id, loanDate, dueDate, purpose, notes || null],
            );
            const id = Number(loan.rows[0].id);
            for (const docId of documentIds) {
              await client.query("INSERT INTO loan_items (loan_id, document_id) VALUES ($1,$2)", [id, docId]);
              await client.query("UPDATE documents SET status='Dipinjam' WHERE id=$1", [docId]);
            }
            return id;
          });
          destination = `/?page=loan-detail&id=${loanId}`;
          await audit(admin.id, "Peminjaman baru", `Transaksi #${loanId}`);
          message = `Peminjaman #${loanId} berhasil dicatat.`;
          break;
        }
        case "return_items": {
          const loanId = integer(form, "loan_id");
          destination = `/?page=loan-detail&id=${loanId}`;
          const ids = [...new Set(form.getAll("item_ids[]").map((id) => Number(id)).filter((id) => Number.isSafeInteger(id) && id > 0))];
          if (!ids.length) throw new UserError("Pilih setidaknya satu dokumen yang dikembalikan.");
          await transaction(async (client) => {
            const loan = await client.query("SELECT loan_date FROM loans WHERE id=$1 FOR UPDATE", [loanId]);
            if (!loan.rowCount) throw new UserError("Transaksi tidak ditemukan.");
            const items = await client.query(
              "SELECT id, document_id FROM loan_items WHERE loan_id=$1 AND returned_at IS NULL AND id=ANY($2::bigint[]) FOR UPDATE",
              [loanId, ids],
            );
            if (items.rowCount !== ids.length) throw new UserError("Item pengembalian tidak valid atau telah diproses.");
            for (const item of items.rows) {
              const date = value(form, `return_date[${item.id}]`, 10) || today();
              const condition = value(form, `return_condition[${item.id}]`, 40) || "Baik";
              const note = value(form, `return_note[${item.id}]`, 1000);
              if (!validDate(date) || date < String(loan.rows[0].loan_date).slice(0, 10) || date > today()) {
                throw new UserError("Tanggal kembali harus di antara tanggal pinjam dan hari ini.");
              }
              if (!["Baik", "Perlu perbaikan", "Rusak", "Hilang"].includes(condition)) throw new UserError("Kondisi dokumen saat kembali tidak valid.");
              await client.query("UPDATE loan_items SET returned_at=$1, return_condition=$2, return_note=$3 WHERE id=$4", [date, condition, note || null, item.id]);
              await client.query("UPDATE documents SET status=$1, condition_state=$2 WHERE id=$3", [
                condition === "Hilang" ? "Hilang" : "Tersedia",
                condition === "Hilang" ? "Rusak" : condition,
                item.document_id,
              ]);
            }
          });
          await audit(admin.id, "Pengembalian dokumen", `Transaksi #${loanId}`);
          message = "Pengembalian berhasil dicatat.";
          break;
        }
        case "extend_loan": {
          const id = integer(form, "loan_id");
          destination = `/?page=loan-detail&id=${id}`;
          const dueDate = value(form, "due_date", 10);
          const reason = value(form, "reason", 255);
          const maximum = Number(await setting("max_extensions", "2"));
          await transaction(async (client) => {
            const loan = await client.query("SELECT due_date FROM loans WHERE id=$1 FOR UPDATE", [id]);
            if (!loan.rowCount) throw new UserError("Transaksi tidak ditemukan.");
            const count = await client.query("SELECT COUNT(*)::int AS count FROM loan_extensions WHERE loan_id=$1", [id]);
            if (Number(count.rows[0].count) >= maximum) throw new UserError(`Batas perpanjangan (${maximum} kali) telah tercapai.`);
            if (!validDate(dueDate) || dueDate <= String(loan.rows[0].due_date).slice(0, 10) || !reason) {
              throw new UserError("Tenggat baru harus setelah tenggat saat ini dan alasan wajib diisi.");
            }
            const active = await client.query("SELECT COUNT(*)::int AS count FROM loan_items WHERE loan_id=$1 AND returned_at IS NULL", [id]);
            if (Number(active.rows[0].count) === 0) throw new UserError("Transaksi yang sudah selesai tidak dapat diperpanjang.");
            await client.query("INSERT INTO loan_extensions (loan_id,admin_id,old_due_date,new_due_date,reason) VALUES ($1,$2,$3,$4,$5)", [
              id, admin.id, loan.rows[0].due_date, dueDate, reason,
            ]);
            await client.query("UPDATE loans SET due_date=$1 WHERE id=$2", [dueDate, id]);
          });
          await audit(admin.id, "Perpanjangan peminjaman", `Transaksi #${id}: ${dueDate}`);
          message = "Tenggat peminjaman berhasil diperpanjang.";
          break;
        }
        case "save_admin": {
          destination = "/?page=settings";
          const id = integer(form, "id");
          const name = value(form, "name", 120);
          const username = normalizeUsername(value(form, "username", 60));
          const role = value(form, "role", 20);
          const password = String(form.get("password") ?? "");
          if (!name || !/^[a-z0-9._-]{3,60}$/.test(username)) throw new UserError("Nama dan username tidak valid.");
          if (!["Admin", "Super Admin"].includes(role)) throw new UserError("Peran admin tidak valid.");
          if (id === admin.id && role !== admin.role) throw new UserError("Peran akun yang sedang digunakan tidak dapat diubah.");
          if (!id && password.length < 12) throw new UserError("Kata sandi admin baru minimal 12 karakter.");
          if (id && password && password.length < 12) throw new UserError("Kata sandi minimal 12 karakter.");
          if (id) {
            await query("UPDATE admins SET name=$1, username=$2, role=$3 WHERE id=$4", [name, username, role, id]);
            if (password) {
              await query("UPDATE admins SET password_hash=$1 WHERE id=$2", [await bcrypt.hash(password, 12), id]);
            }
          } else {
            await query("INSERT INTO admins (name,username,password_hash,role) VALUES ($1,$2,$3,$4)", [
              name, username, await bcrypt.hash(password, 12), role,
            ]);
          }
          await audit(admin.id, id ? "Ubah akun admin" : "Tambah akun admin", username);
          message = "Data admin berhasil disimpan.";
          break;
        }
        case "toggle_admin": {
          const id = integer(form, "id");
          destination = "/?page=settings";
          if (id === admin.id) throw new UserError("Akun yang sedang digunakan tidak dapat dinonaktifkan.");
          await transaction(async (client) => {
            const rows = await client.query("SELECT role, active FROM admins WHERE id=$1 FOR UPDATE", [id]);
            if (!rows.rowCount) throw new UserError("Akun admin tidak ditemukan.");
            if (rows.rows[0].role === "Super Admin" && Number(rows.rows[0].active) === 1) {
              const other = await client.query("SELECT COUNT(*)::int AS count FROM admins WHERE role='Super Admin' AND active=1 AND id<>$1", [id]);
              if (Number(other.rows[0].count) === 0) throw new UserError("Harus ada minimal satu akun Super Admin aktif.");
            }
            await client.query("UPDATE admins SET active=1-active WHERE id=$1", [id]);
          });
          await audit(admin.id, "Ubah status akun admin", `Admin #${id}`);
          message = "Status akun admin diperbarui.";
          break;
        }
        case "reset_admin_password": {
          const id = integer(form, "id");
          destination = "/?page=settings";
          const password = String(form.get("new_password") ?? "");
          if (id === admin.id) throw new UserError("Gunakan menu Ubah kata sandi untuk akun Anda sendiri.");
          if (password.length < 12) throw new UserError("Kata sandi baru minimal 12 karakter.");
          const target = await query<{ username: string }>("SELECT username FROM admins WHERE id=$1", [id]);
          if (!target[0]) throw new UserError("Akun admin tidak ditemukan.");
          await query("UPDATE admins SET password_hash=$1 WHERE id=$2", [await bcrypt.hash(password, 12), id]);
          await audit(admin.id, "Reset kata sandi admin", target[0].username);
          message = "Kata sandi akun admin berhasil direset.";
          break;
        }
        case "save_setting": {
          destination = "/?page=settings";
          const days = integer(form, "default_loan_days");
          const max = integer(form, "max_extensions");
          if (days < 1 || days > 365 || max > 10) throw new UserError("Durasi harus 1–365 hari dan maksimum perpanjangan 0–10 kali.");
          await query(
            "INSERT INTO settings (setting_key,setting_value) VALUES ('default_loan_days',$1),('max_extensions',$2) ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value",
            [String(days), String(max)],
          );
          await audit(admin.id, "Ubah aturan peminjaman", `${days} hari, maksimal ${max} perpanjangan`);
          message = "Aturan peminjaman berhasil disimpan.";
          break;
        }
        case "add_master": {
          destination = "/?page=settings";
          const type = value(form, "master_type", 20);
          const name = value(form, "name", 120);
          const tables: Record<string, string> = { unit: "units", category: "right_categories", district: "districts" };
          if (!tables[type] || !name) throw new UserError("Data master tidak valid.");
          await query(`INSERT INTO ${tables[type]} (name) VALUES ($1)`, [name]);
          await audit(admin.id, "Tambah master data", `${type}: ${name}`);
          message = "Data master berhasil ditambahkan.";
          break;
        }
        case "deactivate_master": {
          destination = "/?page=settings";
          const type = value(form, "master_type", 20);
          const id = integer(form, "id");
          const table = ({ unit: "units", category: "right_categories", district: "districts" } as Record<string, string>)[type];
          if (!table) throw new UserError("Data master tidak valid.");
          if (type === "category") {
            const used = await query<{ count: number }>("SELECT COUNT(*)::int AS count FROM documents WHERE right_category_id=$1", [id]);
            if (Number(used[0].count) > 0) throw new UserError("Kategori yang telah digunakan dokumen tidak dapat dinonaktifkan.");
          }
          if (type === "district") {
            const used = await query<{ count: number }>("SELECT COUNT(*)::int AS count FROM documents WHERE district=(SELECT name FROM districts WHERE id=$1)", [id]);
            if (Number(used[0].count) > 0) throw new UserError("Kecamatan yang telah digunakan dokumen tidak dapat dinonaktifkan.");
          }
          await query(`UPDATE ${table} SET active=0 WHERE id=$1`, [id]);
          await audit(admin.id, "Nonaktifkan master data", `${type} #${id}`);
          message = "Data master dinonaktifkan.";
          break;
        }
        case "change_password": {
          destination = "/?page=password";
          const current = String(form.get("current_password") ?? "");
          const password = String(form.get("new_password") ?? "");
          const confirm = String(form.get("confirm_password") ?? "");
          const rows = await query<{ password_hash: string }>("SELECT password_hash FROM admins WHERE id=$1 AND active=1", [admin.id]);
          if (!rows[0] || !(await bcrypt.compare(current, rows[0].password_hash.replace(/^\$2y\$/, "$2b$")))) {
            throw new UserError("Kata sandi saat ini tidak sesuai.");
          }
          if (password.length < 12 || password !== confirm) throw new UserError("Kata sandi baru minimal 12 karakter dan kedua isian harus sama.");
          await query("UPDATE admins SET password_hash=$1 WHERE id=$2", [await bcrypt.hash(password, 12), admin.id]);
          await audit(admin.id, "Ubah kata sandi");
          message = "Kata sandi berhasil diubah.";
          break;
        }
        case "import_documents": {
          destination = "/?page=documents";
          const file = form.get("csv_file");
          if (!(file instanceof File) || file.size > 5 * 1024 * 1024 || !file.name.toLowerCase().endsWith(".csv")) {
            throw new UserError("Pilih file CSV berukuran maksimal 5 MB.");
          }
          const records = parse(await file.text(), { bom: true, skip_empty_lines: true, relax_column_count: false }) as string[][];
          const expected = ["district", "village", "right_category", "right_number", "document_date", "floor", "cabinet", "rack", "album", "condition_state", "notes"];
          if (!records.length || records[0].map((cell) => cell.trim()).join("\0") !== expected.join("\0")) {
            throw new UserError("Urutan kolom tidak sesuai. Unduh dan gunakan template CSV yang disediakan.");
          }
          if (records.length - 1 > 10_000) throw new UserError("Impor dibatasi maksimal 10.000 baris.");
          const [districtRows, categoryRows, villageRows] = await Promise.all([
            query<{ id: number; name: string }>("SELECT id,name FROM districts WHERE active=1"),
            query<{ id: number; name: string }>("SELECT id,name FROM right_categories WHERE active=1"),
            query<{ district: string; village: string }>("SELECT d.name AS district,v.name AS village FROM villages v JOIN districts d ON d.id=v.district_id WHERE d.active=1 AND v.active=1"),
          ]);
          const districts = new Map(districtRows.map((row) => [row.name.toLowerCase(), row.name]));
          const categories = new Map(categoryRows.map((row) => [row.name.toLowerCase(), Number(row.id)]));
          const villages = new Set(villageRows.map((row) => `${row.district.toLowerCase()}\0${row.village.toLowerCase()}`));
          let imported = 0;
          const errors: string[] = [];
          await transaction(async (client) => {
            for (let index = 1; index < records.length; index++) {
              const row = records[index].map((cell) => cell.trim());
              const line = index + 1;
              if (row.length !== expected.length) {
                errors.push(`Baris ${line}: jumlah kolom tidak sesuai.`);
                continue;
              }
              const [district, village, category, number, date, floorText, cabinetText, rack, album, condition, notes] = row;
              const districtName = districts.get(district.toLowerCase());
              const categoryId = categories.get(category.toLowerCase());
              const floor = Number(floorText);
              const cabinet = Number(cabinetText);
              const rackMatch = /^([1-9]\d*)([ab])$/i.exec(rack);
              if (!districtName || !villages.has(`${district.toLowerCase()}\0${village.toLowerCase()}`) || !categoryId || !number ||
                  !Number.isSafeInteger(floor) || floor < 1 || !Number.isSafeInteger(cabinet) || cabinet < 1 ||
                  !rackMatch || !["Baik", "Perlu perbaikan", "Rusak"].includes(condition) || (date && !validDate(date))) {
                errors.push(`Baris ${line}: nilai wilayah, kategori, nomor, tanggal, lokasi, atau kondisi tidak valid.`);
                continue;
              }
              const inserted = await client.query(
                "INSERT INTO documents (district,village,right_category_id,right_number,document_date,floor,cabinet,rack,album,condition_state,notes) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11) ON CONFLICT (district,village,right_category_id,right_number) DO NOTHING RETURNING id",
                [districtName, village, categoryId, number, date || null, floor, cabinet, rack.toLowerCase(), album || null, condition, notes || null],
              );
              if (inserted.rowCount) imported++;
              else errors.push(`Baris ${line}: nomor hak duplikat pada desa dan jenis hak tersebut.`);
            }
          });
          message = `${imported} dokumen diimpor.${errors.length ? ` ${errors.length} baris bermasalah: ${errors.slice(0, 5).join(" ")}${errors.length > 5 ? " dan lainnya." : ""}` : ""}`;
          messageType = errors.length ? "warning" : "success";
          await audit(admin.id, "Impor dokumen CSV", message);
          break;
        }
        default:
          throw new UserError("Aksi tidak dikenali.");
      }
    }
  } catch (error) {
    message = databaseError(error);
    messageType = "error";
  }
  redirectTo(destination, message, messageType);
}
