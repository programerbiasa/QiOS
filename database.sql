-- =======================================================
-- SKRIP DATABASE KIOS & KASIR DIGITAL CENDEKIA (kios_db)
-- Host Server: 172.16.5.11 / localhost
-- User: root | Password: pastijuara1
-- =======================================================

CREATE DATABASE IF NOT EXISTS `kios_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `kios_db`;

-- -------------------------------------------------------
-- 1. TABEL: users (Hak Akses & Pengguna: Admin & Kasir)
-- -------------------------------------------------------
DROP TABLE IF EXISTS `chat_messages`;
DROP TABLE IF EXISTS `pesanan_detail`;
DROP TABLE IF EXISTS `pesanan`;
DROP TABLE IF EXISTS `produk`;
DROP TABLE IF EXISTS `satuan`;
DROP TABLE IF EXISTS `kategori`;
DROP TABLE IF EXISTS `admin_users`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `password_plain` VARCHAR(255) NULL,
    `nama_lengkap` VARCHAR(100) NOT NULL,
    `role` ENUM('superadmin', 'admin', 'kasir') NOT NULL DEFAULT 'kasir',
    `active_session_token` VARCHAR(255) NULL,
    `active_device_name` VARCHAR(150) NULL,
    `last_active_at` DATETIME NULL,
    `last_seen` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Akun Default (Superadmin, Admin & Petugas Kasir):
-- Super Admin 1: cendekia / pastijuara1
-- Super Admin 2: admin / admin
-- Kasir 1: kasir / kasir123
-- Kasir 2: petugas / pastijuara1
INSERT INTO `users` (`username`, `password`, `password_plain`, `nama_lengkap`, `role`) VALUES
('cendekia', '$2y$10$GhYwaSQri5txSUQwcLamBOkFOojoA7H4xT.roRUyMOt3dyK5k6DIS', 'pastijuara1', 'Administrator Cendekia', 'superadmin'),
('admin', '$2y$10$QXq748PHI/KhOM0jqbVQF.VXaUlX0YElw8xrPJT1sw.Msl8qiyfn.', 'admin', 'Master Admin', 'superadmin'),
('kasir', '$2y$10$RIVJSj7F0Xht97lQOg1bSevVBHsEFZ.j.AWHvyTky5guuMUcGuihC', 'kasir123', 'Petugas Kasir 1', 'kasir'),
('petugas', '$2y$10$GhYwaSQri5txSUQwcLamBOkFOojoA7H4xT.roRUyMOt3dyK5k6DIS', 'pastijuara1', 'Petugas Kasir 2', 'kasir');

-- -------------------------------------------------------
-- 2. TABEL: kategori (Kategori Barang)
-- -------------------------------------------------------
CREATE TABLE `kategori` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nama_kategori` VARCHAR(100) NOT NULL UNIQUE,
    `deskripsi` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `kategori` (`id`, `nama_kategori`, `deskripsi`) VALUES
(1, 'Makanan', 'Aneka makanan berat, roti, dan mie instan'),
(2, 'Minuman', 'Air mineral, kopi, teh, dan minuman segar'),
(3, 'Snack', 'Makanan ringan, biskuit, dan keripik'),
(4, 'Lain-lain', 'Kebutuhan umum dan perlengkapan harian');

-- -------------------------------------------------------
-- 3. TABEL: satuan (Master Satuan Barang / UoM)
-- -------------------------------------------------------
CREATE TABLE `satuan` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nama_satuan` VARCHAR(100) NOT NULL UNIQUE,
    `kode_satuan` VARCHAR(20) NOT NULL,
    `deskripsi` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `satuan` (`id`, `nama_satuan`, `kode_satuan`, `deskripsi`) VALUES
(1, 'Pieces', 'Pcs', 'Satuan umum per satuan/biji'),
(2, 'Botol', 'Btl', 'Kemasan botol cairan/minuman'),
(3, 'Bungkus', 'Bks', 'Kemasan sachet/bungkus/kantong'),
(4, 'Dus / Karton', 'Dus', 'Kemasan kardus atau karton besar'),
(5, 'Pak / Pack', 'Pak', 'Kemasan paket atau pack isi beberapa item'),
(6, 'Porsi / Cup', 'Cup', 'Porsi sajian makanan atau minuman langsung santap'),
(7, 'Kilogram', 'Kg', 'Satuan berat kilogram');

-- -------------------------------------------------------
-- 4. TABEL: produk (Daftar Barang)
-- -------------------------------------------------------
CREATE TABLE `produk` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `kategori_id` INT NULL,
    `satuan_id` INT NULL,
    `nama_produk` VARCHAR(150) NOT NULL,
    `barcode` VARCHAR(100) NULL,
    `harga` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `stok` INT NOT NULL DEFAULT 0,
    `deskripsi` TEXT NULL,
    `foto` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`kategori_id`) REFERENCES `kategori`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`satuan_id`) REFERENCES `satuan`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `produk` (`kategori_id`, `satuan_id`, `nama_produk`, `barcode`, `harga`, `stok`, `deskripsi`, `foto`) VALUES
(2, 2, 'Air Mineral Botol 600ml', '8991234001', 4000.00, 50, 'Air mineral segar pegunungan alami', ''),
(2, 2, 'Kopi Susu Gula Aren 250ml', '8991234002', 12000.00, 35, 'Kopi arabika dengan paduan susu segar dan gula aren premium', ''),
(1, 3, 'Roti Cokelat Keju Spesial', '8991234003', 9500.00, 25, 'Roti empuk dengan isian cokelat lumer dan keju cheddar gurih', ''),
(3, 3, 'Keripik Singkong Balado 150g', '8991234004', 8000.00, 40, 'Keripik singkong renyah dengan bumbu balado pedas manis', ''),
(1, 6, 'Mie Instan Cup Goreng', '8991234005', 7000.00, 30, 'Mie instan praktis siap santap dengan bumbu sedap', ''),
(3, 3, 'Biskuit Cokelat Sandwich 120g', '8991234006', 8500.00, 20, 'Biskuit renyah dengan krim cokelat lezat di tengahnya', ''),
(2, 2, 'Teh Melati Kotak 200ml', '8991234007', 5000.00, 45, 'Minuman teh wangi melati pelepas dahaga yang menyegarkan', ''),
(3, 1, 'Permen Mint Segar 50g', '8991234008', 4500.00, 60, 'Permen penyegar nafas dengan sensasi dingin menthol', ''),
(4, 1, 'Tisu Saku Lembut', '8991234009', 3000.00, 80, 'Tisu saku higienis dan lembut di kulit', '');

-- -------------------------------------------------------
-- 5. TABEL: pesanan (Header Transaksi Kasir)
-- -------------------------------------------------------
CREATE TABLE `pesanan` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `kode_pesanan` VARCHAR(50) NOT NULL UNIQUE,
    `user_id` INT NULL,
    `nama_pembeli` VARCHAR(100) NOT NULL DEFAULT 'Pelanggan Umum',
    `total_harga` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `uang_bayar` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `kembalian` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `status` ENUM('SELESAI', 'PENDING', 'BATAL') DEFAULT 'SELESAI',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- 6. TABEL: pesanan_detail (Rincian Item yang Dibeli)
-- -------------------------------------------------------
CREATE TABLE `pesanan_detail` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pesanan_id` INT NOT NULL,
    `produk_id` INT NULL,
    `nama_produk` VARCHAR(150) NOT NULL,
    `harga_satuan` DECIMAL(12, 2) NOT NULL,
    `jumlah` INT NOT NULL,
    `subtotal` DECIMAL(12, 2) NOT NULL,
    FOREIGN KEY (`pesanan_id`) REFERENCES `pesanan`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`produk_id`) REFERENCES `produk`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contoh data transaksi awal
INSERT INTO `pesanan` (`id`, `kode_pesanan`, `user_id`, `nama_pembeli`, `total_harga`, `uang_bayar`, `kembalian`, `status`, `created_at`) VALUES
(1, 'TRX-20261101-001', 3, 'Budi Santoso', 21500.00, 50000.00, 28500.00, 'SELESAI', NOW() - INTERVAL 2 DAY),
(2, 'TRX-20261101-002', 3, 'Pelanggan Umum', 19000.00, 20000.00, 1000.00, 'SELESAI', NOW() - INTERVAL 1 DAY);

INSERT INTO `pesanan_detail` (`pesanan_id`, `produk_id`, `nama_produk`, `harga_satuan`, `jumlah`, `subtotal`) VALUES
(1, 1, 'Air Mineral Botol 600ml', 4000.00, 2, 8000.00),
(1, 3, 'Roti Cokelat Keju Spesial', 9500.00, 1, 9500.00),
(1, 8, 'Permen Mint Segar 50g', 4500.00, 1, 4500.00),
(2, 2, 'Kopi Susu Gula Aren 250ml', 12000.00, 1, 12000.00),
(2, 5, 'Mie Instan Cup Goreng', 7000.00, 1, 7000.00);

-- -------------------------------------------------------
-- 7. TABEL: chat_messages (Komunikasi Antar Pengguna)
-- -------------------------------------------------------
CREATE TABLE `chat_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `sender_name` VARCHAR(100) NOT NULL,
    `sender_role` VARCHAR(50) NOT NULL,
    `message` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- 8. TABEL: pengaturan (Konfigurasi & Pengaturan Sistem)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pengaturan` (
    `kunci` VARCHAR(50) NOT NULL PRIMARY KEY,
    `nilai` TEXT NULL,
    `keterangan` VARCHAR(255) NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `pengaturan` (`kunci`, `nilai`, `keterangan`) VALUES
('nama_aplikasi', 'QiOS Cendekia', 'Nama aplikasi yang ditampilkan di header, kasir, struk, dan sistem'),
('tema_aplikasi', 'midnight', 'Tema visual sistem QiOS Cendekia')
ON DUPLICATE KEY UPDATE `kunci`=`kunci`;
