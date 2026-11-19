# BTSU — Tata Kelola Buku Tanah dan Surat Ukur

Aplikasi administrasi peminjaman dokumen kantor berbasis PHP dengan PostgreSQL di Supabase.

## Menyiapkan Supabase

1. Buat proyek Supabase dan salin nilai koneksi PostgreSQL dari **Project Settings → Database**. Untuk PHP di jaringan IPv4, gunakan connection string session pooler jika koneksi Direct tidak tersedia.
2. Jalankan `database/schema_supabase.sql` di Supabase **SQL Editor**.
3. Jika memulai database kosong, jalankan `database/seed_supabase.sql` untuk data master dan kelurahan Semarang. Jangan jalankan seed ini sebelum memindahkan database MySQL yang sudah berisi data.
4. Pastikan ekstensi `pdo_pgsql` aktif pada `php.ini` Laragon (di distribusi yang terdeteksi, barisnya `extension=pdo_pgsql`), lalu atur environment variable untuk proses PHP: `DB_HOST`, `DB_PORT` (biasanya `5432`), `DB_NAME` (biasanya `postgres`), `DB_USER`, `DB_PASS`, dan `DB_SSLMODE` (`require`). Restart Laragon setelah mengubah konfigurasi.
5. Salin proyek ke document root web server lokal (misalnya `C:\laragon\www\btsu`) dan buka `http://btsu.test/setup.php` atau URL lokal proyek. Buat akun Super Admin pertama; kata sandi minimal 12 karakter.
6. Masuk melalui halaman utama aplikasi.

Kredensial database hanya digunakan oleh PHP di server. Jangan menaruh password database atau Supabase service-role key di JavaScript/browser. Halaman penyiapan hanya dapat membuat akun awal selama tabel admin masih kosong.

## Memindahkan data MySQL lama

1. Hentikan sementara akses tulis ke aplikasi lama, buat cadangan database MySQL, dan pastikan skemanya sudah pada versi terbaru.
2. Jalankan `database/schema_supabase.sql` pada proyek Supabase. Pastikan tabel tujuan masih kosong; skrip migrasi menolak menimpa data yang sudah ada.
3. Atur `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, dan `DB_SSLMODE` untuk Supabase. Untuk database MySQL sumber, opsional atur `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_NAME`, `MYSQL_USER`, dan `MYSQL_PASS`; nilai default sumber adalah `127.0.0.1:3306`, database `btsu`, user `root`, tanpa password.
4. Dari terminal Laragon pada folder proyek, jalankan `php database/migrate_mysql_to_supabase.php`. Pastikan PHP CLI mengaktifkan ekstensi `pdo_mysql` dan `pdo_pgsql`. Skrip memindahkan semua tabel dalam satu transaksi, mempertahankan ID, dan tidak mengisi ulang kolom hasil kalkulasi.
5. Periksa jumlah data di Supabase, lalu jalankan aplikasi dengan kredensial `DB_*` Supabase. Simpan cadangan MySQL lama sampai hasil migrasi tervalidasi.

`database/schema.sql`, `database/seed_kelurahan_semarang.sql`, dan dua file `database/migrate_*.sql` lama adalah untuk MySQL sumber, bukan untuk Supabase. Aplikasi sekarang menggunakan PDO PostgreSQL.

## Fitur

- Login admin, hash kata sandi, pembatasan 5 kegagalan masuk per 15 menit, sesi 30 menit, CSRF, dan pembatasan peran.
- Inventaris dokumen, pencarian/filter, kelurahan berdasarkan kecamatan, lokasi penyimpanan dengan input angka, dan status terlambat yang dihitung otomatis dari tenggat aktif.
- Data peminjam yang didaftarkan saat transaksi, ekspor laporan periode ke Excel atau PDF, transaksi multi-dokumen, pengembalian sebagian, serta perpanjangan dengan alasan dan batas jumlah.
- Dashboard, laporan inventaris/peminjaman/keterlambatan/dokumen hilang, ekspor CSV, cetak/simpan sebagai PDF dari browser.
- Pengaturan Super Admin: akun admin, master unit/kategori/kecamatan, durasi pinjam, dan batas perpanjangan.
- Catatan audit untuk aktivitas penting.

Impor CSV menggunakan [database/template_dokumen.csv](database/template_dokumen.csv). Header harus dipertahankan; gunakan nama kecamatan, kelurahan, dan jenis hak yang ada pada master data.

Dashboard menampilkan hitungan jatuh tempo dan keterlambatan secara langsung. Pengiriman ringkasan surel harian/WhatsApp belum dikonfigurasi karena memerlukan layanan dan kredensial kantor.

## Database dan cadangan

Skema Supabase/PostgreSQL berada di `database/schema_supabase.sql`. Untuk instalasi kosong, `database/seed_supabase.sql` menambahkan data master dan kelurahan. Untuk cadangan terjadwal, siapkan `pg_dump` atau gunakan fitur backup Supabase; retensikan cadangan sekurangnya 30 hari, simpan di lokasi terpisah, dan uji proses pemulihannya secara berkala.


## Produksi

Aktifkan HTTPS, atur kredensial database non-default, batasi akses jaringan ke server, dan lindungi cadangan yang berisi data pribadi. Versi ini tidak mengirim surel reset kata sandi; pengguna meminta reset kepada Super Admin.
