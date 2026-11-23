# Sistem POS Kasir & Kios Digital Cendekia (Dark Mode Edition)

Aplikasi Web **Point of Sale (POS) Kasir & Manajemen Kios** berbasis PHP Native + MariaDB/MySQL dengan antarmuka bertema gelap (*Dark Mode* profesional), cepat, dan responsif.

---

## 🚀 Alur Kerja Sistem (Role-Based Workflow)

1. **Gerbang Awal:** Mengakses alamat website (`http://172.16.5.11/`) langsung mengarahkan ke halaman **LOGIN**.
2. **Jika Login sebagai Kasir / Petugas:**
   - Langsung diarahkan ke antarmuka **Aplikasi Kasir POS (`/kasir/index.php`)**.
   - **Sebelah Kiri:** Katalog Menu Barang dengan pencarian *real-time* dan tombol filter kategori (Makanan, Minuman, Snack, dll).
   - **Sebelah Kanan:** Keranjang belanja interaktif, nomor nota transaksi, nama pelanggan.
   - **Auto Total Cerdas Profesional:**
     - Total tagihan dihitung otomatis secara instan.
     - Tombol cepat nominal uang tunai (*Uang Pas, +10.000, +20.000, Rp 50.000, Rp 100.000, Rp 200.000*).
     - Input uang bayar dengan **perhitungan kembalian otomatis *real-time*** (indikator warna hijau jika cukup, merah jika kurang).
     - Tombol *Bayar & Cetak Struk* (shortcut `F9`), otomatis mengurangi stok di database secara atomik dan menampilkan struk siap cetak (`window.print()`).
3. **Jika Login sebagai Administrator:**
   - Diarahkan ke **Panel Admin (`/admin/index.php`)**.
   - **Dashboard Admin:** Statistik omset, total barang, total kategori, total nota transaksi, dan tabel penjualan terbaru.
   - **Menu Hak Akses & Pengguna (`/admin/users.php`):** Kelola akun login (Admin vs Kasir), tambah petugas, ganti password dengan Bcrypt hash, ubah role.
   - **Manajemen Barang (`/admin/produk.php`):** CRUD barang lengkap dengan dropdown kategori dan validasi harga/stok non-negatif.
   - **Manajemen Kategori (`/admin/kategori.php`):** CRUD kategori barang.
   - **Laporan Transaksi (`/admin/transaksi.php`):** Rekapitulasi penjualan dengan filter tanggal, filter kasir yang bertugas, modal rincian item, dan cetak laporan.

---


