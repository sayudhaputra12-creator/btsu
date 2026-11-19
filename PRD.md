# PRD: Aplikasi Peminjaman Dokumen Kantor (Admin-Only)

*Versi 1.0 · Draf awal · Pemilik: Tim Produk · Pengguna sistem: Admin Kantor*

Tata Kelola Buku Tanah dan Surat ukur

## 1. Ringkasan

Aplikasi ini membantu admin kantor mencatat, melacak, dan mengelola peminjaman dokumen fisik maupun arsip kantor. Seluruh sistem **hanya dapat diakses dan dikelola oleh admin**. Peminjam (karyawan atau pihak lain) tidak memiliki akun; admin yang mencatat peminjaman atas nama mereka.

Tujuan utamanya adalah menjawab tiga pertanyaan dengan cepat: *dokumen ini ada di mana, siapa yang meminjam, dan kapan harus kembali.*

## 2. Latar Belakang dan Masalah

Pencatatan peminjaman dokumen yang dilakukan manual (buku besar atau spreadsheet) menimbulkan masalah berikut:

- Dokumen sulit dilacak dan sering hilang atau terlambat kembali.
- Tidak ada riwayat peminjaman yang rapi dan dapat dicari.
- Admin tidak mendapat pengingat otomatis saat tenggat mendekat.
- Sulit membuat laporan rutin untuk pimpinan.

## 3. Tujuan dan Non-Tujuan

### Tujuan

1. Mencatat peminjaman dan pengembalian dokumen dalam waktu kurang dari 1 menit per transaksi.
2. Menampilkan status setiap dokumen secara real-time (tersedia, dipinjam, terlambat, hilang).
3. Mengurangi dokumen terlambat melalui pengingat dan daftar keterlambatan.
4. Menyediakan riwayat dan laporan yang dapat diekspor.
5. Menjamin akses hanya untuk admin yang terautentikasi.

### Non-Tujuan (di luar cakupan v1)

- Portal mandiri atau login untuk peminjam.
- Dokumen digital (unggah, pratinjau, atau penyimpanan berkas elektronik).
- Alur persetujuan multi-level.
- Integrasi dengan sistem HR atau ERP.

## 4. Persona Pengguna

| Peran | Deskripsi | Kebutuhan utama |
|---|---|---|
| **Admin Biasa** | Staf administrasi yang melayani peminjaman harian | Catat pinjam/kembali cepat, cari dokumen, lihat keterlambatan |
| **Super Admin** | Admin senior atau penanggung jawab arsip | Semua kemampuan Admin Biasa, plus kelola akun admin, dan master data |

*Asumsi: terdapat dua tingkat peran admin. Jika hanya satu tingkat yang dibutuhkan, peran dapat disederhanakan.*

## 5. Ruang Lingkup Fitur

### 5.1 Autentikasi dan Akses (Prioritas: P0)

- Login dengan username dan kata sandi.
- Seluruh halaman dan endpoint wajib login; pengguna tidak terautentikasi diarahkan ke halaman login.
- Tidak ada pendaftaran publik; akun admin hanya dibuat oleh Super Admin.
- Sesi otomatis berakhir setelah tidak aktif (default 30 menit).
- Pembatasan percobaan login gagal (misalnya 5 kali, lalu kunci sementara).
- Fitur ganti dan reset kata sandi.

### 5.2 Manajemen Dokumen (P0)

- Tambah, ubah, dan nonaktifkan data dokumen.
- Atribut: Provinsi, Kabupaten/Kota, Kecamatan, Desa/Kelurahan, Jenis Hak, Nomer Hak, tanggal dokumen, lokasi simpan (lantai/lemari/rak/album), kondisi, catatan.
- Status dokumen: **Tersedia**, **Dipinjam**, **Terlambat**, **Hilang**, **Nonaktif**.
- Pencarian dan filter berdasarkan Nomer Hak, Jenis Hak, Desa/Kelurahan.
- Impor data dokumen massal dari CSV/Excel (P1).

### 5.3 Manajemen Peminjam (P0)

- Data riwayat peminjam disimpan sebagai catatan kontak, bukan akun: nama, NIP/ID (opsional), unit kerja (seksi), nomor kontak, email (opsional).
- Tambah, ubah, dan hapus peminjam.
- Lihat riwayat peminjaman per peminjam.

### 5.4 Transaksi Peminjaman (P0)

- Buat peminjaman: Pilih dokumen berdasarkan(Desa/Kelurahan, Jenis hak, Nomor Hak), input data peminjam, tanggal pinjam, tenggat kembali, keperluan, catatan.
- Dukungan beberapa dokumen dalam satu transaksi.
- Dokumen berstatus Dipinjam tidak dapat dipinjam ulang.
- Pencatatan pengembalian: tanggal kembali, kondisi saat kembali, catatan.
- Perpanjangan tenggat (dengan alasan dan batas jumlah perpanjangan).
- Penandaan dokumen hilang atau rusak.
- Bukti peminjaman/pengembalian yang dapat dicetak atau diunduh sebagai Excel/CSV/PDF (P1).

### 5.5 Dashboard (P0)

- Ringkasan: total dokumen, sedang dipinjam, terlambat, jatuh tempo dalam 3 hari.
- Daftar peminjaman aktif dan daftar keterlambatan.
- Aktivitas terbaru.

### 5.6 Notifikasi dan Pengingat (P1)

- Pengingat di dalam aplikasi untuk dokumen yang jatuh tempo dan terlambat.
- Pengingat email ke admin (ringkasan harian).
- Opsional: kirim pengingat ke peminjam melalui email/WhatsApp secara manual dari aplikasi.

### 5.7 Laporan dan Ekspor (P1)

- Laporan ketersediaan dokumen Buku Tanah Surat Ukur(BTSU): (kec, desa/kel, jenis hak, nomor hak, lokasi arsip (almari/rak)).
- Laporan peminjaman per periode, per dokumen, per peminjam, dan per unit.
- Laporan keterlambatan dan dokumen hilang.
- Laporan BTSU yg masih di pinjam: (kec, desa/kel, jenis hak, nomor hak, nama peminjam, unit kerja/seksi, tgl pinjam).
- Ekspor ke Excel/CSV dan PDF.

### 5.8 Pengaturan (P1)

- Kelola akun admin dan peran (Super Admin).
- Master data: atribut dokumen, lokasi simpan, unit/divisi (P0).
- Aturan default: durasi pinjam standar, maksimum perpanjangan.
- Admin: hanya mengakses transaksi simpan pinjam.
- Super Admin: Kelola akun admin, Transaksi simpan pinjam.

### 5.9 Input Dokumen (P0)
- Field: provinsi, kabupaten/kota, kecamatan, desa/kelurahan.
- Field: Tipe hak, Nomor hak.
- Kategori Hak: Hak Milik, Hak Guna Usaha, Hak Guna Bangunan, Hak Pakai, Hak Pengelolaan, Hak Wakaf, Hak Milik Satuan Rumah Susun.
- Provinsi khusus Jawa Tengah.
- Kabupaten/Kota khusus Semarang.
- Kategori Kecamatan pilih.
- Kategori Desa/Kelurahan pilih. 

### 5.10 Tempat Dokumen (P0)
- Field: Lantai, Lemari, Rak, Album.
- Kategori: Lantai 1-10.
- Kategori: Lemari 1-10 (Tiap lantai nomer lemari lanjut).
- Kategori: Rak 1a-4b (Tiap rak ada 2 sisi(a&b)).
- Kategori: Album (text field).

## 6. User Stories

| ID | Sebagai | Saya ingin | Agar |
|---|---|---|---|
| US-01 | Admin | login dengan akun saya | hanya saya yang dapat mengakses sistem |
| US-02 | Admin | mencari dokumen berdasarkan kode atau judul | cepat mengetahui lokasi dan statusnya |
| US-03 | Admin | mencatat peminjaman atas nama karyawan | ada bukti dan tenggat yang jelas |
| US-04 | Admin | mencatat pengembalian dokumen | status dokumen kembali tersedia |
| US-05 | Admin | melihat daftar dokumen terlambat | dapat menindaklanjuti peminjam |
| US-06 | Admin | memperpanjang tenggat peminjaman | peminjam dapat memakai dokumen lebih lama dengan tercatat |
| US-07 | Admin | mengekspor laporan bulanan | menyerahkannya kepada pimpinan |
| US-08 | Super Admin | menambah atau menonaktifkan akun admin | akses tetap terkendali |
| US-09 | Super Admin | melihat laporan | dapat menelusuri perubahan data |

## 7. Alur Utama

### Alur Peminjaman

1. Admin login dan membuka menu *Peminjaman Baru*.
2. Admin menginput peminjam.
3. Admin memilih satu atau lebih dokumen berstatus Tersedia.
4. Admin mengisi tanggal pinjam, tenggat, dan keperluan, lalu menyimpan.
5. Sistem mengubah status dokumen menjadi Dipinjam dan membuat bukti peminjaman.

### Alur Pengembalian

1. Admin membuka peminjaman aktif (via pencarian atau dashboard).
2. Admin memilih dokumen yang dikembalikan dan mengisi kondisi serta tanggal kembali.
3. Sistem mengubah status menjadi Tersedia dan menutup transaksi (atau menyisakan dokumen lain yang belum kembali).

### Siklus Status Dokumen

Tersedia → Dipinjam → Tersedia (dikembalikan). Dipinjam → Terlambat (otomatis saat melewati tenggat) → Tersedia (dikembalikan) atau Hilang. Dokumen Tersedia dapat dinonaktifkan.

## 8. Aturan Bisnis

1. Satu dokumen fisik hanya dapat dipinjam oleh satu transaksi aktif pada satu waktu.
2. Tenggat kembali tidak boleh lebih awal dari tanggal pinjam.
3. Status *Terlambat* diberikan otomatis oleh sistem ketika tanggal hari ini melewati tenggat dan dokumen belum kembali.
4. Perpanjangan dibatasi maksimum 2 kali per transaksi (dapat dikonfigurasi).
5. Dokumen atau peminjam yang memiliki riwayat transaksi tidak dapat dihapus permanen, hanya dinonaktifkan.
6. Dokumen berstatus Hilang tidak dapat dipinjam sampai statusnya diperbarui.
7. Setiap perubahan data penting dicatat dalam laporan.

## 9. Model Data (Ringkas)

| Entitas | Atribut utama |
|---|---|
| **Admin** | id, nama, username, kata sandi (hash), peran, status aktif, login terakhir |
| **Dokumen** | id, provinsi(Jawa Tengah), kabupaten/kota(Semarang), kecamatan, desa/kelurahan, jenis hak, nomer hak, lokasi simpan, kondisi, status, catatan |
| **Peminjam** | id, nama, ID/NIP, unit, kontak, status aktif |
| **Peminjaman** | id, peminjam, admin pencatat, tanggal pinjam, tenggat, keperluan, status |
| **Item Peminjaman** | id, peminjaman, dokumen, tanggal kembali, kondisi kembali, jumlah perpanjangan |
| **Kategori / Lokasi / Unit** | id, nama |
| **Lokasi** | id, lantai, lemari, rak, album |

## 10. Kebutuhan Non-Fungsional

| Aspek | Kebutuhan |
|---|---|
| **Keamanan** | Kata sandi di-hash (bcrypt/argon2); HTTPS; proteksi CSRF/XSS/SQL injection; kontrol akses berbasis peran di sisi server |
| **Performa** | Halaman utama dimuat di bawah 2 detik; pencarian dokumen di bawah 1 detik untuk 50.000 data |
| **Ketersediaan** | Target 99% pada jam kerja |
| **Cadangan data** | Backup otomatis harian, retensi minimal 30 hari |
| **Kegunaan** | Antarmuka sederhana berbahasa Indonesia; responsif untuk desktop dan tablet |
| **Skalabilitas** | Mendukung hingga puluhan admin dan puluhan ribu dokumen |
| **Kepatuhan** | Data pribadi peminjam hanya minimal yang diperlukan dan dilindungi sesuai kebijakan kantor |

## 11. Daftar Layar

1. Login dan lupa kata sandi
2. Dashboard
3. Daftar dokumen dan detail dokumen
4. Form tambah/ubah dokumen
5. Riwayat peminjam dan detail peminjam
6. Form peminjaman baru
7. Daftar peminjaman aktif dan riwayat
8. Form pengembalian dan perpanjangan
9. Laporan dan ekspor
10. Pengaturan: akun admin, master data

## 12. Metrik Keberhasilan

| Metrik | Target |
|---|---|
| Waktu mencatat satu peminjaman | kurang dari 1 menit |
| Persentase dokumen terlambat | turun minimal 50% dalam 3 bulan |
| Dokumen yang statusnya akurat dengan kondisi fisik | di atas 95% |
| Waktu menyiapkan laporan bulanan | dari jam menjadi kurang dari 5 menit |
| Admin aktif memakai sistem mingguan | 100% |

## 13. Rencana Rilis

| Fase | Cakupan | Perkiraan |
|---|---|---|
| **MVP** | Autentikasi, manajemen dokumen & peminjam, peminjaman/pengembalian, dashboard | 6–8 minggu |
| **Rilis 2** | Notifikasi, laporan & ekspor, bukti PDF, impor massal | 3–4 minggu |
| **Rilis 3** | Pengaturan lanjutan, penyempurnaan UX | 2–3 minggu |

## 14. Risiko dan Mitigasi

| Risiko | Mitigasi |
|---|---|
| Data dokumen awal belum lengkap | Sediakan impor CSV dan template; lakukan inventarisasi bertahap |
| Admin lupa mencatat pengembalian | Dashboard keterlambatan dan pengingat harian |
| Kebocoran akun admin | Kata sandi kuat, batas percobaan login, sesi kedaluwarsa, opsi 2FA di rilis berikutnya |
| Data peminjam sensitif | Simpan data minimal, batasi akses hanya admin, |
| Adopsi rendah | Pelatihan singkat dan antarmuka sederhana |

## 15. Asumsi dan Pertanyaan Terbuka

1. Apakah dokumen yang dipinjam hanya fisik, atau perlu mendukung berkas digital?
2. Apakah perlu dua tingkat peran admin (Admin dan Super Admin), atau cukup satu?
3. Berapa jumlah dokumen dan admin perkiraan saat peluncuran?
4. Apakah diperlukan pemindaian barcode/QR untuk kode dokumen?
5. Saluran pengingat apa yang dipakai (email, WhatsApp, atau keduanya)?
6. Apakah ada kebijakan denda atau sanksi keterlambatan yang harus dicatat sistem?
7. Platform yang diinginkan: web saja, atau juga aplikasi mobile?
8. Apakah perlu 2FA sejak MVP?

## 16. Kriteria Penerimaan MVP

- Pengguna tanpa login tidak dapat mengakses halaman atau data apa pun.
- Admin dapat menambah dokumen dan peminjam, mencatat peminjaman, dan mencatat pengembalian tanpa error.
- Status dokumen berubah otomatis dan konsisten (termasuk Terlambat).
- Dokumen yang sedang dipinjam tidak dapat dipinjam ganda.
- Dashboard menampilkan angka yang sesuai dengan data transaksi.
- Pencarian dan filter dokumen berjalan sesuai spesifikasi performa.


