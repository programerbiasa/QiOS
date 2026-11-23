<?php
require_once __DIR__ . '/init.php';

try {
    // 1. Total Pendapatan
    $stmt_income = $pdo->query("SELECT COALESCE(SUM(total_harga), 0) AS total_pendapatan FROM pesanan WHERE status = 'SELESAI'");
    $total_pendapatan = (float)$stmt_income->fetch()['total_pendapatan'];

    // 2. Total Produk & Total Stok
    $stmt_prod = $pdo->query("SELECT COUNT(*) AS total_produk, COALESCE(SUM(stok), 0) AS total_stok FROM produk");
    $prod_stat = $stmt_prod->fetch();
    $total_produk = (int)$prod_stat['total_produk'];
    $total_stok = (int)$prod_stat['total_stok'];

    // 3. Total Kategori
    $stmt_kat = $pdo->query("SELECT COUNT(*) AS total_kategori FROM kategori");
    $total_kategori = (int)$stmt_kat->fetch()['total_kategori'];

    // 4. Total Transaksi
    $stmt_order = $pdo->query("SELECT COUNT(*) AS total_pesanan FROM pesanan");
    $total_pesanan = (int)$stmt_order->fetch()['total_pesanan'];

    // 5. Total Users
    $stmt_users = $pdo->query("SELECT COUNT(*) AS total_users FROM users");
    $total_users = (int)$stmt_users->fetch()['total_users'];

    // 6. Top 10 Barang Paling Sering Dibeli (Terlaris)
    $stmt_top = $pdo->query("
        SELECT 
            p.id AS produk_id,
            p.nama_produk,
            p.foto,
            p.harga,
            p.stok,
            k.nama_kategori,
            s.nama_satuan,
            s.kode_satuan,
            COALESCE(SUM(pd.jumlah), 0) AS total_terjual,
            COALESCE(COUNT(pd.id), 0) AS frekuensi_beli,
            COALESCE(SUM(pd.subtotal), 0) AS total_omset
        FROM produk p
        LEFT JOIN pesanan_detail pd ON p.id = pd.produk_id
        LEFT JOIN pesanan ord ON pd.pesanan_id = ord.id AND ord.status = 'SELESAI'
        LEFT JOIN kategori k ON p.kategori_id = k.id
        LEFT JOIN satuan s ON p.satuan_id = s.id
        GROUP BY p.id
        ORDER BY total_terjual DESC, frekuensi_beli DESC, p.nama_produk ASC
        LIMIT 10
    ");
    $raw_top = $stmt_top->fetchAll();

    $base_url = get_base_url();
    $top_products = [];
    $rank = 1;
    foreach ($raw_top as $r) {
        $foto_url = '';
        if (!empty($r['foto'])) {
            $foto_url = $base_url . 'assets/images/products/' . $r['foto'];
        }
        $top_products[] = [
            'rank'           => $rank++,
            'produk_id'      => (int)$r['produk_id'],
            'nama_produk'    => $r['nama_produk'],
            'foto'           => $r['foto'],
            'foto_url'       => $foto_url,
            'harga'          => (float)$r['harga'],
            'stok'           => (int)$r['stok'],
            'nama_kategori'  => $r['nama_kategori'] ?? 'Umum',
            'kode_satuan'    => $r['kode_satuan'] ?? 'pcs',
            'total_terjual'  => (int)$r['total_terjual'],
            'frekuensi_beli' => (int)$r['frekuensi_beli'],
            'total_omset'    => (float)$r['total_omset'],
        ];
    }

    api_response(true, 'Data Dashboard Administrasi Berhasil Dimuat', [
        'stats' => [
            'total_omset'     => $total_pendapatan,
            'total_produk'    => $total_produk,
            'total_stok'      => $total_stok,
            'total_kategori'  => $total_kategori,
            'total_transaksi' => $total_pesanan,
            'total_users'     => $total_users,
        ],
        'top_products' => $top_products,
    ]);

} catch (PDOException $e) {
    api_response(false, 'Gagal mengambil data dashboard: ' . $e->getMessage(), null, 500);
}
