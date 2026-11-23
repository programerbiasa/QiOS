<?php
require_once __DIR__ . '/init.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    api_response(false, 'Metode HTTP tidak diizinkan. Gunakan GET.', null, 405);
}

try {
    $stmt = $pdo->query("
        SELECT 
            s.id, 
            s.nama_satuan, 
            s.kode_satuan, 
            s.deskripsi,
            COUNT(p.id) AS total_produk
        FROM satuan s
        LEFT JOIN produk p ON s.id = p.satuan_id
        GROUP BY s.id
        ORDER BY s.nama_satuan ASC
    ");
    $satuan = $stmt->fetchAll();

    foreach ($satuan as &$sat) {
        $sat['id'] = (int)$sat['id'];
        $sat['total_produk'] = (int)$sat['total_produk'];
    }

    api_response(true, 'Data satuan berhasil diambil', $satuan);
} catch (PDOException $e) {
    api_response(false, 'Database Error: ' . $e->getMessage(), null, 500);
}
