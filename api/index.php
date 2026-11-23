<?php
require_once __DIR__ . '/init.php';

$base_url = get_base_url();

api_response(true, 'Kios & POS Cendekia REST API Server Aktif', [
    'version'   => '1.0.0',
    'status'    => 'ready',
    'base_url'  => $base_url,
    'endpoints' => [
        'AUTH' => [
            'POST api/auth.php' => 'Login kasir / admin (Body: username, password)'
        ],
        'PRODUK' => [
            'GET api/produk.php'         => 'Daftar semua barang (Query: search, kat_id, in_stock)',
            'GET api/produk.php?id={id}' => 'Detail satu barang berdasarkan ID',
            'POST api/produk.php'        => 'Tambah barang baru (Admin)'
        ],
        'KATEGORI' => [
            'GET api/kategori.php' => 'Daftar semua kategori barang beserta jumlah item'
        ],
        'SATUAN' => [
            'GET api/satuan.php' => 'Daftar semua satuan barang'
        ],
        'TRANSAKSI' => [
            'POST api/transaksi.php' => 'Checkout pesanan kasir baru (Body: user_id, nama_pembeli, uang_bayar, items)',
            'GET api/transaksi.php'  => 'Riwayat transaksi pesanan terakhir'
        ],
        'CHAT' => [
            'GET api/chat.php?action=fetch' => 'Ambil pesan chat aktif antar petugas',
            'POST api/chat.php?action=send' => 'Kirim pesan chat (Body: message)'
        ],
        'PENGATURAN' => [
            'GET api/pengaturan.php' => 'Informasi pengaturan sistem & identitas nama aplikasi'
        ]
    ]
]);
