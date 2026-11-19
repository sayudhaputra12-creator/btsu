# BTSU — Tata Kelola Buku Tanah dan Surat Ukur

Aplikasi administrasi Buku Tanah dan Surat Ukur berbasis Next.js yang dapat dijalankan di Vercel. PostgreSQL di Supabase digunakan sebagai database; seluruh akses database, autentikasi, dan aksi tulis hanya berjalan di server.

## Fitur

- Inisialisasi akun Super Admin, login, pembatasan percobaan masuk, sesi 30 menit, dan peran admin.
- Inventaris dokumen, pencarian/filter, master wilayah, impor CSV, dan riwayat peminjaman.
- Data peminjam, peminjaman multi-dokumen, pengembalian sebagian, dan perpanjangan tenggat.
- Dashboard, laporan inventaris/peminjaman/keterlambatan, ekspor CSV, dan cetak ke PDF melalui browser.
- Pengaturan admin, master data, aturan peminjaman, serta catatan audit.

## Menyiapkan Supabase

1. Buat proyek Supabase.
2. Buka **SQL Editor**, jalankan `database/schema_supabase.sql`, lalu jalankan `database/seed_supabase.sql` untuk data master dan kelurahan Semarang. Jangan jalankan seed bila data master sudah diisi atau sedang dipindahkan dari database lama.
3. Dari **Project Settings → Database**, salin koneksi PostgreSQL. Untuk Vercel, gunakan connection string Supabase **Session Pooler** atau **Transaction Pooler** yang tersedia di proyek tersebut. Pastikan SSL diaktifkan (`sslmode=require`).
4. Jangan menaruh kredensial database atau service-role key pada variabel `NEXT_PUBLIC_*`.

### Memindahkan data MySQL lama

Sebelum migrasi, buat cadangan MySQL, hentikan sementara perubahan data, dan pastikan skema sumber sudah terbaru.

1. Jalankan `database/schema_supabase.sql` pada proyek Supabase dan pastikan tabel tujuan masih kosong.
2. Pada lingkungan PHP yang dapat menjangkau MySQL dan Supabase, atur `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, dan `DB_SSLMODE` untuk Supabase. Untuk MySQL sumber, opsional atur `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_NAME`, `MYSQL_USER`, dan `MYSQL_PASS`; default sumber adalah `127.0.0.1:3306`, database `btsu`, user `root`, tanpa password.
3. Pastikan PHP CLI mengaktifkan ekstensi `pdo_mysql` dan `pdo_pgsql`, lalu jalankan `php database/migrate_mysql_to_supabase.php`.
4. Verifikasi jumlah data dan login aplikasi dengan database Supabase sebelum menonaktifkan sumber lama. Skrip menolak menimpa data tujuan yang sudah ada.

## Menjalankan lokal

Persyaratan: Node.js **20.9 atau lebih baru**.

```powershell
npm ci
Copy-Item .env.example .env.local
```

Isi `.env.local`:

```dotenv
DATABASE_URL=postgresql://USER:PASSWORD@HOST:PORT/postgres?sslmode=require
SESSION_SECRET=isi-dengan-random-secret-minimal-32-karakter
```

Buat secret acak menggunakan Node.js:

```powershell
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

Lalu jalankan:

```powershell
npm run dev
```

Buka `http://localhost:3000`. Jika tabel admin masih kosong, aplikasi meminta Anda membuat akun Super Admin pertama. Gunakan kata sandi minimal 12 karakter.

## Deploy ke Vercel

1. Push/unggah repository ini ke GitHub dan impor repository melalui **Vercel → Add New → Project**.
2. Pilih root direktori repository. Vercel mendeteksi Next.js dan menjalankan `npm run build`.
3. Pada **Project Settings → Environment Variables**, tambahkan:
   - `DATABASE_URL`: connection string PostgreSQL Supabase dengan SSL.
   - `SESSION_SECRET`: string acak sekurangnya 32 karakter; gunakan nilai berbeda untuk Production, Preview, dan Development.
4. Aktifkan variabel untuk environment yang diperlukan, lalu deploy atau redeploy.
5. Buka domain Vercel. Bila belum ada admin, buat akun Super Admin pertama. Domain kustom dapat dipasang dari **Project → Settings → Domains**.

Template variabel tersedia di `.env.example`. Jangan commit `.env.local` atau mengirim kredensial ke browser.

## Catatan operasional

- Vercel menjalankan Next.js/Node.js; file PHP lama dan server Laragon tidak dijalankan di Vercel. Folder skrip migrasi PHP dipertahankan untuk memindahkan data lama.
- Backup dan pemulihan database dikelola melalui Supabase atau `pg_dump`; simpan backup secara terpisah dan uji pemulihannya.
- Pengguna yang lupa kata sandi perlu meminta reset kepada Super Admin. Pengiriman email reset dan notifikasi harian belum dikonfigurasi.
