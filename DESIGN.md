# DESIGN BRIEF: Aplikasi Peminjaman Dokumen Kantor (Admin-Only)

*Versi 0.1 · Draf · Sumber: `prd.md` v1.0 · Platform: web desktop · Mode: light saja · Standar aksesibilitas: WCAG 2.1 AA*

## Cara membaca dokumen ini

- Dokumen ini hanya mencakup layar, komponen, dan fitur yang ada di PRD. Tidak ada halaman atau komponen tambahan.
- Hal yang tidak ditentukan PRD atau jawaban klarifikasi **tidak diputuskan sendiri**. Hal tersebut ditandai **[OQ-n]** dan dikumpulkan di bagian 10.
- Nilai warna, font, dan ukuran adalah **default sementara**, karena kantor belum memiliki identitas brand. Semuanya ditulis sebagai *token* supaya mudah diganti.

### Masukan dari klarifikasi

| Topik | Jawaban |
|---|---|
| Kesan brand | Formal, rapi, mudah digunakan |
| Identitas brand | Belum ada (tanpa logo atau warna utama) |
| Referensi visual | Tidak ada referensi positif. Yang dihindari: tampilan ramai dan berantakan |
| Perangkat utama | Desktop |
| Aksesibilitas | WCAG 2.1 AA |
| Mode tampilan | Light mode saja |

---

## 1. Tujuan Desain dan Kesan yang Ingin Dibangun

### Tujuan desain

1. **Menjawab tiga pertanyaan inti dalam sekali lihat:** dokumen ini ada di mana, siapa yang meminjam, dan kapan harus kembali.
2. **Mencatat satu peminjaman kurang dari 1 menit** (metrik PRD bagian 12). Form dibuat pendek, urutan isian logis, dan bisa diisi sepenuhnya dengan keyboard.
3. **Membuat status dokumen mudah dibedakan:** Tersedia, Dipinjam, Terlambat, Hilang, Nonaktif.
4. **Mencegah kesalahan**, misalnya meminjam dokumen yang sudah dipinjam atau mengisi tenggat yang lebih awal dari tanggal pinjam.

### Kesan yang dibangun

| Kesan | Diterjemahkan menjadi |
|---|---|
| **Formal** | Palet biru tua dan abu netral, font sans-serif lugas, bahasa baku, tanpa ilustrasi dekoratif atau animasi berlebihan |
| **Rapi** | Grid konsisten, jarak antarelemen seragam (kelipatan 4px), kolom tabel sejajar, satu aksi utama per layar |
| **Mudah digunakan** | Label selalu terlihat, istilah sama dengan PRD, pesan error menjelaskan cara memperbaiki |
| **Tidak ramai** | Warna aksen hanya untuk status dan aksi utama, tanpa bayangan berat, tanpa gradasi, dan jumlah elemen di dashboard dibatasi |

### Prinsip turunan

- **Satu layar, satu tujuan.** Aksi utama hanya satu dan paling menonjol.
- **Warna bermakna.** Warna kuat hanya muncul pada status dan aksi utama. Sisanya netral.
- **Status tidak bergantung pada warna.** Selalu ada teks (dan ikon) di samping warna.
- **Padat tapi lega.** Tabel dibuat nyaman dibaca, bukan dijejalkan.

---

## 2. Palet Warna

Palet netral-formal berbasis biru tua. Karena belum ada identitas brand, **seluruh warna dianggap sementara [OQ-1]**. Rasio kontras di bawah adalah perkiraan hitung manual terhadap latar yang disebut. **Wajib diverifikasi ulang dengan alat uji kontras sebelum diserahkan ke developer.**

### 2.1 Warna utama dan netral

| Token | Hex | Dipakai untuk | Kontras (perkiraan) |
|---|---|---|---|
| `primary-700` | `#1E3A5F` | Tombol utama, sidebar, tautan, judul aktif | 11:1 dengan teks putih |
| `primary-800` | `#162C48` | Hover/pressed tombol utama | 14:1 dengan teks putih |
| `primary-600` | `#2C4F7C` | Item sidebar aktif | 8:1 dengan teks putih |
| `primary-50` | `#E8EEF5` | Hover tombol sekunder, baris tabel terpilih | Latar saja |
| `text-strong` | `#1A202C` | Judul, isi tabel, teks utama | 16:1 di atas putih |
| `text-secondary` | `#4A5568` | Label bantu, caption, keterangan | 7.5:1 di atas putih |
| `text-disabled` | `#718096` | Teks elemen nonaktif | 3.2:1 di atas `#E2E8F0` (dikecualikan WCAG) |
| `border-control` | `#718096` | Garis tepi input, select, checkbox | 4:1 di atas putih (syarat 3:1) |
| `border-divider` | `#E2E8F0` | Pemisah baris tabel, garis kartu (dekoratif) | Bukan elemen interaktif |
| `bg-page` | `#F5F7FA` | Latar halaman | Latar |
| `bg-surface` | `#FFFFFF` | Kartu, tabel, form, dialog | Latar |
| `bg-subtle` | `#EDF0F4` | Header tabel, area baca-saja | Latar |
| `focus-ring` | `#2B6CB0` | Cincin fokus di area terang (putih `#FFFFFF` di sidebar gelap) | 5:1 di atas putih |

### 2.2 Warna semantik (status dan umpan balik)

Tiap pasangan berisi warna teks/ikon dan warna latar badge.

| Token | Teks/ikon | Latar | Dipakai untuk |
|---|---|---|---|
| `status-available` | `#1B6E3C` | `#E6F4EA` | Status **Tersedia**; pesan sukses |
| `status-borrowed` | `#1D4E89` | `#E3EEF9` | Status **Dipinjam**; pesan informasi |
| `status-due-soon` | `#8A5A00` | `#FEF3D6` | Penanda "Jatuh tempo ≤ 3 hari" di dashboard; pesan peringatan |
| `status-overdue` | `#B42318` | `#FDECEA` | Status **Terlambat**; error validasi; aksi destruktif |
| `status-lost` | `#5B2A86` | `#F0E9F8` | Status **Hilang** |
| `status-inactive` | `#4A5568` | `#EDF0F4` | Status **Nonaktif** |

Perkiraan kontras tiap pasangan teks-di-atas-latar: 5,4:1 sampai 9,9:1 (semuanya di atas syarat 4,5:1).

**Aturan pakai warna**

- Warna status hanya dipakai pada badge, ikon status, dan pesan terkait. Tidak dipakai sebagai dekorasi.
- Merah hanya untuk hal yang perlu tindakan: Terlambat, error, dan aksi destruktif.
- Maksimal satu tombol utama (`primary-700`) per layar atau per bagian form.
- Tidak ada dark mode di v1 (jawaban klarifikasi).

---

## 3. Tipografi

**Font:** Inter, dengan cadangan `"Segoe UI", system-ui, Arial, sans-serif`. Pilihan ini default sementara [OQ-2]. Angka di tabel memakai `font-variant-numeric: tabular-nums` supaya sejajar.

| Peran | Ukuran / tinggi baris | Berat | Dipakai untuk |
|---|---|---|---|
| **Heading 1** | 24 / 32 px | 600 | Judul halaman (satu per halaman) |
| **Heading 2** | 20 / 28 px | 600 | Judul bagian, judul kartu besar, judul dialog |
| **Heading 3** | 16 / 24 px | 600 | Sub-bagian dalam form, judul kartu ringkasan |
| **Body** | 16 / 24 px | 400 | Teks paragraf, isi input, pesan |
| **Body kecil (tabel)** | 14 / 20 px | 400 | Isi sel tabel, daftar padat |
| **Label** | 14 / 20 px | 500 | Label field, header kolom tabel, teks tombol |
| **Caption** | 13 / 18 px | 400 | Teks bantu di bawah field, waktu, keterangan |
| **Badge** | 13 / 16 px | 500 | Teks status |
| **Angka ringkasan** | 28 / 36 px | 600 | Angka pada kartu ringkasan dashboard |

Aturan:

- Ukuran terkecil adalah 13px. Tidak ada teks di bawah itu.
- Rata kiri untuk semua teks. Angka dan tanggal di tabel konsisten satu format (format tanggal [OQ-3]).
- Panjang baris paragraf maksimal ±80 karakter.
- Judul tidak memakai huruf kapital semua.

---

## 4. Spacing, Grid, dan Radius Sudut

### Spacing (basis 4px)

| Token | Nilai | Contoh pakai |
|---|---|---|
| `space-1` | 4 px | Jarak ikon-teks |
| `space-2` | 8 px | Jarak label-input, antar-badge |
| `space-3` | 12 px | Padding dalam sel tabel, antar-field rapat |
| `space-4` | 16 px | Padding kartu, antar-field form |
| `space-5` | 24 px | Antar-bagian dalam halaman, gutter grid |
| `space-6` | 32 px | Padding area konten, antar-bagian besar |
| `space-7` | 48 px | Jarak sebelum bagian baru yang terpisah jelas |

### Kerangka halaman dan grid

- **Target desain:** lebar 1440 px. Rentang didukung 1280 sampai 1920 px. Di bawah 1280 px tampilan masih harus terbaca tanpa kehilangan fungsi, tapi tidak dioptimalkan (tablet bukan prioritas, lihat [OQ-4]).
- **Sidebar:** lebar tetap 240 px, warna `primary-700`.
- **Top bar:** tinggi 56 px, berisi nama admin, peran, dan tombol *Keluar*.
- **Area konten:** padding 32 px, lebar maksimal 1200 px, rata kiri.
- **Grid:** 12 kolom, gutter 24 px.
- **Form satu kolom:** lebar maksimal 640 px.
- **Tinggi baris tabel:** 48 px; header tabel 44 px.
- **Tinggi kontrol (input, tombol, select):** 40 px.

### Radius sudut

| Elemen | Radius |
|---|---|
| Badge status | 4 px |
| Input, tombol, select | 6 px |
| Kartu, tabel, dialog | 8 px |

### Elevasi

Kartu dan tabel memakai garis tepi 1 px `border-divider` tanpa bayangan. Bayangan ringan hanya untuk dialog dan dropdown. Tujuannya menjaga tampilan tetap tenang.

---

## 5. Komponen UI dan State

Hanya komponen yang dibutuhkan fitur di PRD. State fokus berlaku untuk semua komponen interaktif: cincin 2 px `focus-ring` dengan offset 2 px (putih pada sidebar).

### 5.1 Tombol

| Varian | Default | Hover | Disabled | Error/Bahaya |
|---|---|---|---|---|
| **Utama** (Simpan, Masuk) | Latar `primary-700`, teks putih | Latar `primary-800` | Latar `#E2E8F0`, teks `text-disabled`, tanpa interaksi | — |
| **Sekunder** (Batal, Ekspor) | Latar putih, garis `border-control`, teks `primary-700` | Latar `primary-50` | Garis dan teks abu pudar | — |
| **Bahaya** (Nonaktifkan, Tandai Hilang) | Latar putih, garis dan teks `status-overdue` | Latar `#FDECEA` | Sama seperti sekunder disabled | Dipakai hanya untuk aksi destruktif |
| **Tautan teks** (Lihat semua) | Teks `primary-700`, bergaris bawah saat hover | Garis bawah | Teks abu pudar | — |

- Saat memproses: teks berubah (misal "Menyimpan…"), tombol dikunci, ada indikator putar kecil. Lebar tombol tidak berubah.
- Tombol disabled harus disertai alasan di teks bantu atau tooltip bila tidak jelas.

### 5.2 Input teks, kata sandi, pencarian, dan textarea

| State | Tampilan |
|---|---|
| **Default** | Latar putih, garis 1 px `border-control`, teks `text-strong`, placeholder `text-secondary` (placeholder bukan pengganti label) |
| **Hover** | Garis `text-secondary` |
| **Fokus** | Garis 2 px `focus-ring` |
| **Disabled** | Latar `bg-subtle`, garis `border-divider`, teks `text-disabled` |
| **Error** | Garis 2 px `status-overdue`, ikon peringatan, pesan error `status-overdue` di bawah field, field ditautkan ke pesan lewat `aria-describedby` |
| **Baca-saja** | Latar `bg-subtle`, garis dihilangkan, teks tetap `text-strong` |

- Field wajib ditandai teks "(wajib)" pada label, bukan hanya tanda bintang.
- Kata sandi: ada tombol tampilkan/sembunyikan dengan label teks yang jelas.
- Pencarian: ikon kaca pembesar di kiri dan tombol hapus isi di kanan.

### 5.3 Select / combobox (peminjam, kategori, lokasi, unit, kondisi)

State sama dengan input teks. Tambahan:

- Daftar opsi: baris 40 px, opsi hover `primary-50`, opsi terpilih diberi tanda centang dan teks tebal.
- Combobox dengan pencarian dipakai untuk **peminjam** dan **dokumen** (data banyak).
- Daftar kosong menampilkan teks "Tidak ada hasil".

### 5.4 Pemilih tanggal (tanggal pinjam, tenggat, tanggal kembali, rentang laporan)

- Field teks dengan ikon kalender; tanggal bisa diketik dan dipilih dari kalender.
- State sama dengan input teks.
- Error khusus: tenggat lebih awal dari tanggal pinjam (aturan bisnis 2).

### 5.5 Checkbox

| State | Tampilan |
|---|---|
| Default | Kotak 20 px, garis `border-control`, latar putih |
| Hover | Garis `primary-700` |
| Terpilih | Latar `primary-700`, centang putih |
| Disabled | Latar `bg-subtle`, garis dan label pudar |
| Error | Garis `status-overdue`, pesan di bawah grup |

Area klik mencakup label. Dipakai untuk memilih dokumen yang dikembalikan dan baris tabel bila perlu.

### 5.6 Badge status

Badge berisi **ikon + teks**, tidak hanya warna. Tinggi 24 px, padding horizontal 8 px.

| Status | Warna | Ikon |
|---|---|---|
| Tersedia | `status-available` | Centang lingkaran |
| Dipinjam | `status-borrowed` | Panah keluar |
| Terlambat | `status-overdue` | Jam / peringatan |
| Hilang | `status-lost` | Tanda tanya lingkaran |
| Nonaktif | `status-inactive` | Garis lingkaran |
| Jatuh tempo (indikator dashboard) | `status-due-soon` | Jam |

Badge tidak interaktif, jadi tidak punya state hover atau disabled.

### 5.7 Tabel data

| Bagian | Default | Hover | Terpilih | Disabled/Nonaktif | Error |
|---|---|---|---|---|---|
| **Header** | Latar `bg-subtle`, label 14/20, tebal 500; kolom yang bisa diurutkan diberi ikon panah | Latar `#E2E8F0` pada kolom yang bisa diurutkan | — | — | — |
| **Baris** | Latar putih, pemisah `border-divider` | Latar `bg-subtle` | Latar `primary-50` | Teks `text-secondary` untuk data Nonaktif (selain badge) | Ikon dan teks error di baris bila gagal diproses |

- Baris yang bisa diklik punya tautan pada kolom utama (kode atau nama) supaya bisa dioperasikan dengan keyboard.
- Baris **Terlambat** diberi badge merah. Latar baris tidak diwarnai, supaya tampilan tidak ramai.
- Kolom aksi dibatasi: satu aksi utama terlihat (misal *Lihat*), aksi lain di menu "Aksi lainnya".
- Pola paginasi atau gulir untuk data hingga 50.000 baris belum ditentukan PRD [OQ-5].

### 5.8 Kartu ringkasan (dashboard)

- Latar putih, garis 1 px `border-divider`, padding 16 px, radius 8 px.
- Isi: judul (Heading 3), angka besar, keterangan caption.
- Kartu **Terlambat** memakai angka berwarna `status-overdue` bila nilainya lebih dari 0. Kartu **Jatuh tempo** memakai `status-due-soon`. Kartu lain tetap netral.
- State: default dan hover (garis `primary-700`, bila kartu dapat diklik ke daftar terkait [OQ-6]) dan fokus.

### 5.9 Navigasi sidebar

| State | Tampilan |
|---|---|
| Default | Teks dan ikon putih pada `primary-700` |
| Hover | Latar `primary-600` pada 50% opasitas |
| Aktif | Latar `primary-600`, penanda garis putih 3 px di kiri |
| Fokus | Cincin putih 2 px |
| Disembunyikan | Menu **Pengaturan** tidak tampil untuk Admin Biasa (PRD 5.8, 5.9) [OQ-7] |

### 5.10 Pesan umpan balik (alert inline dan toast)

| Jenis | Ikon dan warna | Perilaku |
|---|---|---|
| Sukses | `status-available` | Toast di kanan atas, hilang otomatis dalam 6 detik, bisa ditutup |
| Informasi | `status-borrowed` | Alert inline |
| Peringatan | `status-due-soon` | Alert inline |
| Error | `status-overdue` | Alert inline di atas form, **tidak** hilang otomatis, fokus dipindah ke pesan |

Semua pesan dibaca pembaca layar lewat `aria-live` (`polite` untuk sukses, `assertive` untuk error).

### 5.11 Dialog konfirmasi

Dipakai untuk aksi yang sulit dibatalkan: **nonaktifkan** dokumen/peminjam/akun admin dan **tandai hilang**. Dialog ini adalah pola umum, bukan fitur baru, tetapi PRD tidak menyebutnya secara eksplisit [OQ-8].

- Lebar 480 px, radius 8 px, latar luar gelap 40%, fokus terkunci di dalam dialog, tombol `Esc` menutup.
- Isi: judul (Heading 2), satu kalimat dampak, dua tombol (*Batal* sekunder, aksi bahaya di kanan).
- Fokus awal pada tombol *Batal*.

---

## 6. Layout Tiap Halaman Utama

Semua halaman (kecuali Login) memakai kerangka: **sidebar kiri + top bar + area konten**. Setiap halaman diawali Heading 1 dan satu tombol utama di kanan atas header halaman (bila ada). Menu sidebar: Dashboard, Dokumen, Peminjam, Peminjaman, Laporan, Pengaturan.

### 6.1 Login dan lupa kata sandi (layar 1)

- Tanpa sidebar. Kartu di tengah halaman, lebar 400 px, di atas `bg-page`.
- Isi: judul "Masuk", field email/username, field kata sandi, tombol utama *Masuk*, tautan *Lupa kata sandi?*.
- **Tidak ada tautan daftar** (PRD 5.1: tidak ada pendaftaran publik).
- Layar *Lupa kata sandi*: satu field + tombol utama + tautan *Kembali ke halaman masuk*. Mekanisme reset belum ditentukan [OQ-9].
- Slot logo dikosongkan sampai identitas ada [OQ-1].

### 6.2 Dashboard (layar 2)

```
┌───────────────────────────────────────────────────────┐
│ H1 Dashboard                                          │
├────────────┬────────────┬────────────┬────────────────┤
│ Total      │ Sedang     │ Terlambat  │ Jatuh tempo    │
│ dokumen    │ dipinjam   │            │ ≤ 3 hari       │
├────────────┴────────────┴────────────┴────────────────┤
│ Daftar keterlambatan (8 kol)     │ Aktivitas terbaru  │
│ tabel maks. 5 baris              │ (4 kol)            │
│──────────────────────────────────│ daftar maks.       │
│ Peminjaman aktif (8 kol)         │ 8 item             │
│ tabel maks. 5 baris              │                    │
└───────────────────────────────────────────────────────┘
```

- Empat kartu ringkasan sama lebar, satu baris.
- **Daftar keterlambatan diletakkan di atas peminjaman aktif** karena paling butuh tindakan.
- Tiap tabel dibatasi 5 baris dengan tautan *Lihat semua* ke layar 7.
- Aktivitas terbaru berupa daftar vertikal sederhana: ikon, kalimat singkat, waktu (caption).
- Pengingat dalam aplikasi (PRD 5.6) diwakili kartu *Jatuh tempo* dan daftar keterlambatan. Penempatan lain belum ditentukan [OQ-10].

### 6.3 Daftar dokumen dan detail dokumen (layar 3)

**Daftar**

- Header: H1 *Dokumen*, tombol utama *Tambah Dokumen*, tombol sekunder *Impor* (P1).
- Bar filter satu baris: pencarian (kode/judul), filter kategori, lokasi, status, tautan *Atur ulang*.
- Tabel: Kode, Judul, Kategori, Lokasi, Status (badge), Aksi.
- Paginasi/gulir di bawah tabel [OQ-5].

**Detail**

- Header: kode dokumen (H1), judul, badge status; tombol *Ubah* (sekunder) dan *Nonaktifkan* (bahaya) di kanan.
- Isi satu kartu, dua kolom: atribut dokumen (kode, judul, kategori, nomor/tanggal dokumen, lokasi simpan, jumlah salinan, kondisi, catatan) sebagai pasangan label-nilai.
- Riwayat peminjaman per dokumen, serta letak aksi *Tandai Hilang*, belum ditentukan PRD [OQ-11].

### 6.4 Form tambah/ubah dokumen (layar 4)

- Satu kolom, lebar maksimal 640 px, di dalam kartu.
- Urutan field: Kode dokumen (wajib, unik) → Judul (wajib) → Kategori → Nomor dokumen → Tanggal dokumen → Lokasi simpan (lemari/rak/map) → Jumlah salinan → Kondisi → Catatan.
- Bar aksi di bawah form: *Simpan* (utama), *Batal* (sekunder). Posisi lengket (sticky) di bawah layar bila form panjang.
- Mode ubah: judul "Ubah Dokumen", field terisi, status ditampilkan baca-saja.
- Daftar pilihan **Kondisi** dan hubungan **Jumlah salinan** dengan aturan "satu dokumen satu transaksi aktif" belum jelas [OQ-12].

### 6.5 Daftar peminjam dan detail peminjam (layar 5)

**Daftar:** H1 *Peminjam*, tombol utama *Tambah Peminjam*, pencarian, tabel (Nama, ID/NIP, Unit, Kontak, Status, Aksi).

**Detail:** kartu info peminjam (nama, ID/NIP, unit, kontak, email, status) dengan tombol *Ubah* dan *Nonaktifkan*, diikuti tabel **Riwayat peminjaman** peminjam tersebut (PRD 5.3).

**Form tambah/ubah peminjam:** satu kolom, field sesuai PRD 5.3 (nama wajib; ID/NIP dan email opsional; unit; kontak). Data pribadi dibuat seminimal mungkin (PRD bagian 10).

### 6.6 Form peminjaman baru (layar 6)

```
┌──────────────────────────────────┬────────────────────┐
│ H1 Peminjaman Baru               │                    │
│ ① Peminjam   [combobox]          │ Dokumen dipilih (n)│
│ ② Dokumen    [combobox + tambah] │ - kode · judul  ✕  │
│ ③ Tanggal pinjam  ④ Tenggat      │ - kode · judul  ✕  │
│ ⑤ Keperluan                      │                    │
│ ⑥ Catatan                        │                    │
│ [Simpan Peminjaman]  [Batal]     │                    │
└──────────────────────────────────┴────────────────────┘
        8 kolom                         4 kolom
```

- Urutan field mengikuti alur PRD bagian 7 supaya bisa diselesaikan dengan keyboard.
- Combobox dokumen hanya menampilkan dokumen berstatus **Tersedia**. Dokumen Dipinjam, Terlambat, Hilang, dan Nonaktif tidak bisa dipilih (aturan bisnis 1 dan 6).
- Panel kanan mendaftar dokumen yang dipilih (mendukung beberapa dokumen), masing-masing dengan tombol hapus.
- Tenggat awal terisi dari durasi pinjam standar bila ada [OQ-13].
- Opsi *Tambah peminjam baru* dari dalam form (di tempat atau pindah halaman) belum ditentukan [OQ-14].
- Setelah berhasil: toast sukses dan tawaran cetak bukti (P1).

### 6.7 Daftar peminjaman aktif dan riwayat (layar 7)

- Header: H1 *Peminjaman*, tombol utama *Peminjaman Baru*.
- Filter: pencarian (peminjam/kode dokumen), status (**Aktif** sebagai bawaan, Terlambat, Selesai, Semua), rentang tanggal.
- Tabel: Peminjam, Dokumen (ringkas, "+n lainnya"), Tanggal pinjam, Tenggat, Status (badge), Aksi (*Kembalikan*, *Perpanjang*).
- Apakah "aktif" dan "riwayat" dipisah menjadi dua tampilan atau satu daftar dengan filter belum ditentukan [OQ-15].

### 6.8 Form pengembalian dan perpanjangan (layar 8)

- Bagian atas: ringkasan peminjaman baca-saja (peminjam, tanggal pinjam, tenggat, jumlah perpanjangan "n dari 2").
- **Bagian Pengembalian:** tabel dokumen dalam peminjaman, tiap baris berisi checkbox, tanggal kembali, kondisi saat kembali, catatan. Tombol utama *Simpan Pengembalian*. Dokumen yang tidak dicentang tetap berstatus Dipinjam (PRD bagian 7).
- **Bagian Perpanjangan:** tenggat baru, alasan (wajib), tombol *Simpan Perpanjangan*. Bila batas perpanjangan tercapai, bagian ini dinonaktifkan dengan penjelasan.
- Dua tombol simpan berada di bagian masing-masing agar tidak membingungkan.
- Apakah form ini halaman penuh, panel samping, atau dialog belum ditentukan, begitu juga akses ke detail peminjaman [OQ-16].

### 6.9 Laporan dan ekspor (layar 9)

- Header: H1 *Laporan*.
- Bar pengaturan satu baris: jenis laporan (per periode, per dokumen, per peminjam, per unit, keterlambatan, dokumen hilang), rentang tanggal, filter tambahan sesuai jenis, tombol utama *Tampilkan*.
- Area hasil: tabel pratinjau.
- Tombol ekspor di kanan atas hasil: *Excel/CSV*, *PDF* (sekunder). Dinonaktifkan sampai laporan ditampilkan.

### 6.10 Pengaturan: akun admin dan master data (layar 10)

- Hanya terlihat oleh Super Admin [OQ-7].
- Bagian dibedakan lewat judul bagian (Heading 2) dalam satu halaman, tanpa komponen navigasi tambahan: **Akun Admin**, **Kategori Dokumen**, **Lokasi Simpan**, **Unit/Divisi**.
- **Akun Admin:** tabel (Nama, Email, Peran, Status, Login terakhir, Aksi) + tombol *Tambah Admin*. Aksi: ubah, nonaktifkan.
- **Master data:** tabel sederhana (Nama, Aksi) dengan tombol *Tambah* per bagian.
- Form tambah/ubah memakai dialog satu kolom [OQ-16].
- Letak aturan default (durasi pinjam, maks. perpanjangan, PRD 5.9) belum ditentukan [OQ-17].

---

## 7. Empty State, Loading, dan Pesan Error

Nada bahasa: baku, singkat, menjelaskan **apa yang terjadi** dan **apa yang bisa dilakukan**. Tidak menyalahkan pengguna.

### 7.1 Empty state

Format: ikon sederhana (garis, netral), satu kalimat judul, satu kalimat penjelasan, dan satu tombol bila ada aksi relevan.

| Konteks | Teks |
|---|---|
| Daftar dokumen kosong | "Belum ada dokumen." / "Tambahkan dokumen pertama atau impor dari CSV/Excel." Tombol: *Tambah Dokumen* |
| Daftar peminjam kosong | "Belum ada peminjam." / "Tambahkan peminjam untuk mulai mencatat peminjaman." Tombol: *Tambah Peminjam* |
| Hasil pencarian/filter kosong | "Tidak ada hasil yang cocok." / "Coba ubah kata kunci atau atur ulang filter." Tombol: *Atur ulang filter* |
| Dashboard: tanpa keterlambatan | "Tidak ada dokumen terlambat." (nada netral-positif, tanpa ilustrasi) |
| Dashboard: tanpa peminjaman aktif | "Tidak ada peminjaman aktif saat ini." |
| Dashboard: aktivitas kosong | "Belum ada aktivitas." |
| Riwayat peminjam kosong | "Peminjam ini belum pernah meminjam dokumen." |
| Laporan tanpa data | "Tidak ada data pada periode ini." / "Ubah rentang tanggal atau jenis laporan." |
| Laporan kosong | "Belum ada catatan aktivitas." |
| Pilihan dokumen tidak ada yang tersedia | "Tidak ada dokumen yang tersedia untuk dipinjam." |

### 7.2 Loading

| Situasi | Pola |
|---|---|
| Tabel dimuat | *Skeleton* baris abu (5–8 baris), tanpa spinner penuh layar |
| Kartu dashboard | *Skeleton* pada angka, judul tetap tampil |
| Aksi simpan/kirim | Tombol berubah ("Menyimpan…"), terkunci, ada indikator kecil; form tidak digelapkan |
| Pencarian | Indikator kecil di dalam field, hasil lama tetap tampil sampai hasil baru siap |
| Ekspor laporan | Tombol ekspor "Menyiapkan berkas…", toast sukses saat selesai |
| Lebih dari 10 detik | Tampilkan pesan "Proses memakan waktu lebih lama dari biasanya." |

Target performa dari PRD: halaman utama < 2 detik, pencarian < 1 detik. Skeleton hanya muncul bila muat lebih dari ±300 ms, supaya tidak berkedip.

### 7.3 Pesan error

**Validasi form** (inline di bawah field)

| Kondisi | Pesan |
|---|---|
| Field wajib kosong | "[Nama field] wajib diisi." |
| Kode dokumen sudah ada | "Kode dokumen sudah dipakai. Gunakan kode lain." |
| Tenggat sebelum tanggal pinjam | "Tenggat tidak boleh lebih awal dari tanggal pinjam." |
| Format email salah | "Format email tidak valid." |
| Tidak ada dokumen dipilih | "Pilih minimal satu dokumen." |
| Alasan perpanjangan kosong | "Alasan perpanjangan wajib diisi." |

**Aturan bisnis** (alert inline di atas form atau dialog)

| Kondisi | Pesan |
|---|---|
| Dokumen sedang dipinjam | "Dokumen ini sedang dipinjam dan belum dapat dipinjam lagi." |
| Dokumen berstatus Hilang | "Dokumen ini berstatus Hilang. Perbarui statusnya sebelum dipinjam." |
| Batas perpanjangan tercapai | "Batas perpanjangan sudah tercapai (2 dari 2)." |
| Hapus data yang punya riwayat | "Data ini punya riwayat transaksi sehingga tidak dapat dihapus, hanya dinonaktifkan." |

**Autentikasi dan akses**

| Kondisi | Pesan |
|---|---|
| Login salah | "Email/username atau kata sandi salah." (tidak menyebut mana yang salah) |
| Akun terkunci sementara | "Terlalu banyak percobaan masuk. Coba lagi setelah [durasi]." [OQ-19] |
| Sesi akan berakhir | "Sesi Anda akan berakhir dalam 2 menit karena tidak aktif." Tombol: *Tetap masuk* |
| Sesi berakhir | "Sesi berakhir. Silakan masuk kembali." (diarahkan ke Login) |
| Tidak punya akses | "Anda tidak memiliki akses ke halaman ini." Tautan: *Kembali ke Dashboard* |

**Sistem**

| Kondisi | Pesan |
|---|---|
| Gagal menyimpan | "Data belum tersimpan. Periksa koneksi lalu coba lagi." Tombol: *Coba lagi* |
| Gagal memuat | "Data tidak dapat dimuat." Tombol: *Muat ulang* |
| Halaman tidak ditemukan | "Halaman tidak ditemukan." Tautan: *Kembali ke Dashboard* |
| Gangguan server | "Terjadi gangguan pada sistem. Coba lagi beberapa saat lagi." |
| Ekspor gagal | "Berkas gagal dibuat. Coba lagi." |

Aturan umum pesan: tidak menampilkan kode teknis mentah kepada pengguna; pesan error form tidak hilang sampai diperbaiki; data yang sudah diketik tidak boleh hilang saat error.

---

## 8. Aturan Aksesibilitas (WCAG 2.1 AA)

### Kontras

- Teks normal (di bawah 18 px, atau di bawah 14 px tebal): **minimal 4,5:1**.
- Teks besar (≥ 24 px, atau ≥ 18,5 px tebal): minimal 3:1.
- Komponen UI dan batas input, ikon bermakna, cincin fokus: **minimal 3:1** terhadap latar sebelahnya.
- Semua pasangan warna di bagian 2 harus diverifikasi dengan alat uji kontras sebelum dibangun.

### Warna bukan satu-satunya penanda

- Status selalu = warna + ikon + teks.
- Error selalu = warna + ikon + teks pesan. Field error tidak hanya berganti warna garis.
- Kolom yang bisa diurutkan menunjukkan arah dengan ikon, bukan warna.

### Ukuran target interaksi

Aplikasi dipakai di desktop dengan mouse dan keyboard, jadi 44 px (acuan sentuh) tidak diterapkan.

| Elemen | Ukuran minimal |
|---|---|
| Tombol, input, select | Tinggi 40 px (tidak boleh di bawah 32 px) |
| Ikon tombol (hapus, tutup, kalender) | Area klik 32 × 32 px, walau ikonnya 16–20 px |
| Checkbox | Kotak 20 px, area klik termasuk label (tinggi 32 px) |
| Tautan dalam tabel | Area klik setinggi baris atau minimal 24 px |
| Jarak antar-target | Minimal 8 px |

Bila nanti tablet dengan sentuhan dipakai, ukuran ini harus dinaikkan ke 44 px [OQ-4].

### Keyboard dan fokus

- Seluruh fungsi dapat dioperasikan dengan keyboard, dengan urutan tab sesuai urutan visual.
- Fokus selalu terlihat (cincin 2 px, kontras ≥ 3:1) dan tidak pernah disembunyikan.
- Dialog mengunci fokus, mengembalikan fokus ke pemicu saat ditutup, dan bisa ditutup dengan `Esc`.
- Sediakan tautan *Lewati ke konten utama* di awal halaman.
- Pintasan keyboard tidak boleh bentrok dengan pintasan peramban atau pembaca layar.

### Struktur dan pembaca layar

- Atribut bahasa halaman `lang="id"`.
- Satu H1 per halaman, hierarki heading berurutan, landmark jelas (`nav`, `main`, `header`).
- Setiap field punya label terhubung. Placeholder tidak pernah menjadi satu-satunya label.
- Pesan error dihubungkan ke field (`aria-describedby`) dan diumumkan (`aria-live`).
- Tabel memakai `<th scope>`; judul tabel disediakan sebagai caption atau label.
- Tombol berikon saja wajib punya nama aksesibel (misal "Hapus dokumen dari daftar").
- Badge status dibaca sebagai teks status, bukan ikon.

### Waktu dan tampilan

- Sesi otomatis berakhir setelah 30 menit tidak aktif (PRD 5.1). Sesuai WCAG 2.2.1, pengguna **diperingatkan sebelum sesi berakhir** dan dapat memperpanjangnya [OQ-19].
- Tampilan tetap dapat dipakai pada zoom 200% tanpa teks terpotong atau tumpang tindih. Tabel boleh digulir horizontal pada zoom tinggi.
- Tidak ada konten berkedip atau animasi otomatis. Transisi dibatasi ≤ 150 ms dan menghormati pengaturan `prefers-reduced-motion`.
- Toast yang hilang otomatis memberi waktu cukup (≥ 6 detik) dan pesan penting tidak bergantung pada toast saja.

---

## 9. Do & Don't

| Do | Don't |
|---|---|
| Gunakan satu tombol utama per layar | Menaruh beberapa tombol berwarna solid yang bersaing |
| Pakai warna hanya untuk status dan aksi utama | Memakai warna berbeda hanya untuk hiasan |
| Tampilkan status dengan badge ikon + teks | Hanya mengandalkan warna untuk membedakan status |
| Pakai istilah persis dari PRD (Tersedia, Dipinjam, Terlambat, Hilang, Nonaktif) | Mengganti atau menambah istilah status sendiri |
| Beri ruang lega pada tabel dan form (spacing kelipatan 4px) | Menjejalkan banyak kolom/elemen dalam satu layar |
| Batasi dashboard (4 kartu, tabel maks. 5 baris) | Menambah grafik, widget, atau kartu di luar PRD |
| Beri label permanen di atas setiap field | Menjadikan placeholder sebagai label |
| Tulis pesan error yang menjelaskan cara memperbaiki | Menulis pesan umum seperti "Terjadi kesalahan" saja |
| Konfirmasi aksi nonaktifkan dan tandai hilang | Menjalankan aksi destruktif tanpa konfirmasi |
| Pertahankan data yang sudah diketik saat terjadi error | Mengosongkan form setelah gagal menyimpan |
| Gunakan kerangka yang sama di semua halaman | Membuat tata letak berbeda tiap halaman |
| Sembunyikan menu yang bukan hak akses pengguna | Menampilkan menu yang berujung "akses ditolak" |
| Pakai font, ukuran, dan radius dari token | Membuat gaya baru untuk satu halaman saja |
| Biarkan tabel laporan baca-saja | Memberi tombol ubah atau hapus di laporan |
| Perlakukan peminjam sebagai catatan kontak yang dicatat oleh admin | Menambah login, pendaftaran, atau portal untuk peminjam (di luar cakupan v1) |
| Pakai bayangan hanya untuk dialog dan dropdown | Memakai bayangan, gradasi, atau ilustrasi dekoratif di mana-mana |

---

## 10. Open Questions

Belum ada keputusan untuk poin berikut. Sampai dijawab, desainer memakai default yang tertulis dan menandainya di desain.

| ID | Pertanyaan | Default sementara |
|---|---|---|
| **OQ-1** | Apakah ada identitas brand (logo, warna utama) yang akan diikuti? | Palet biru tua netral di bagian 2; slot logo dikosongkan |
| **OQ-2** | Apakah font Inter boleh dipakai (lisensi, kemampuan memuat font eksternal di jaringan kantor)? | Inter dengan cadangan font sistem |
| **OQ-3** | Format tanggal dan angka yang dipakai (misal 17 Nov 2026 atau 17/11/2026)? Apakah perlu zona waktu tertentu? | Format teks pendek, mis. "17 Nov 2026" |
| **OQ-4** | Apakah tablet (PRD bagian 10: "desktop dan tablet") tetap perlu didukung walau prioritas pertama desktop? Jika ya, apakah dipakai dengan sentuhan? | Hanya desktop yang dirancang; tablet belum dirancang |
| **OQ-5** | Bagaimana daftar besar (hingga 50.000 dokumen) ditampilkan: paginasi bernomor, "muat lebih banyak", atau gulir tak terbatas? | Belum dirancang; tabel ditandai "pola paging menyusul" |
| **OQ-6** | Apakah kartu ringkasan dashboard boleh diklik menuju daftar terkait? | Tidak dapat diklik |
| **OQ-7** | Apakah menu yang tidak diizinkan disembunyikan, atau tampil nonaktif? Apakah Admin Biasa bisa mengakses *Laporan*? | Disembunyikan; Laporan bisa diakses Admin Biasa (sesuai US-07) |
| **OQ-8** | Apakah dialog konfirmasi untuk aksi nonaktifkan/tandai hilang diterima sebagai bagian dari desain, mengingat PRD tidak menyebutnya? | Dipakai (pola pencegah kesalahan) |
| **OQ-9** | Bagaimana mekanisme *reset* dan *ganti* kata sandi (tautan email, atau dibantu Super Admin)? Di mana admin mengganti kata sandi sendiri? PRD tidak memasukkannya ke daftar layar | Hanya layar "Lupa kata sandi" dasar yang digambar |
| **OQ-10** | Di mana pengingat dalam aplikasi (PRD 5.6) ditampilkan di luar dashboard? Apakah perlu ikon notifikasi di top bar? | Hanya lewat dashboard; tidak ada ikon notifikasi |
| **OQ-11** | Apakah halaman detail dokumen menampilkan riwayat peminjaman dokumen itu? Di mana aksi *Tandai Hilang/Rusak* diletakkan? | Detail dokumen hanya atribut; aksi Tandai Hilang belum ditempatkan |
| **OQ-12** | Apa saja pilihan **Kondisi** dokumen? Bagaimana **Jumlah salinan** lebih dari 1 selaras dengan aturan satu dokumen satu transaksi aktif? | Kondisi berupa select tanpa isi pasti; jumlah salinan field angka |
| **OQ-13** | Berapa durasi pinjam standar, dan apakah tenggat otomatis terisi darinya? | Tenggat diisi manual |
| **OQ-14** | Apakah *Tambah peminjam baru* dilakukan di dalam form peminjaman (dialog) atau berpindah halaman? | Tautan ke form peminjam, lalu kembali |
| **OQ-15** | Apakah "peminjaman aktif" dan "riwayat" satu daftar dengan filter, atau dua tampilan terpisah? | Satu daftar, filter status bawaan "Aktif" |
| **OQ-16** | Apakah form pengembalian/perpanjangan, serta form di Pengaturan, berupa halaman penuh, panel, atau dialog? Apakah ada halaman detail peminjaman (tidak ada di daftar layar PRD)? | Form pengembalian/perpanjangan sebagai satu halaman dari baris peminjaman; form Pengaturan sebagai dialog |
| **OQ-17** | Di mana aturan default (durasi pinjam, maks. perpanjangan) diatur? Layar 10 hanya menyebut akun admin dan master data | Belum ditempatkan |
| **OQ-18** | Filter apa yang dibutuhkan di laporan (admin, tanggal, jenis aksi)? Bagaimana nilai sebelum/sesudah ditampilkan? | Tabel dasar dengan ringkasan satu baris |
| **OQ-19** | Berapa lama penguncian setelah login gagal beruntun? Apakah peringatan sebelum sesi 30 menit berakhir diterima? | Peringatan 2 menit sebelum sesi berakhir; durasi kunci ditulis "[durasi]" |
| **OQ-20** | Bagaimana tampilan bukti peminjaman/pengembalian (cetak atau PDF, P1)? Layout-nya tidak ada di daftar layar | Belum dirancang |
| **OQ-21** | Apakah perlu pemindaian barcode/QR untuk kode dokumen (PRD bagian 15, pertanyaan 4)? Jika ya, ada pengaruh pada form dan pencarian | Tidak dirancang |
| **OQ-22** | Apakah 2FA diperlukan sejak MVP (PRD bagian 15, pertanyaan 8)? Jika ya, ada layar tambahan di Login | Tidak dirancang |
| **OQ-23** | Apakah ada referensi visual positif yang disukai? Saat ini hanya ada arahan menghindari tampilan ramai | Arah gaya mengikuti kesan "formal, rapi, mudah digunakan" |

---

*Akhir dokumen.*
