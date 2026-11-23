<?php
require_once __DIR__ . '/init.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    api_response(false, 'Metode HTTP tidak diizinkan. Gunakan GET.', null, 405);
}

try {
    $stmt = $pdo->query("
        SELECT 
            k.id, 
            k.nama_kategori, 
            k.deskripsi,
            COUNT(p.id) AS total_produk
        FROM kategori k
        LEFT JOIN produk p ON k.id = p.kategori_id
        GROUP BY k.id
        ORDER BY k.nama_kategori ASC
    ");
    $kategori = $stmt->fetchAll();

    foreach ($kategori as &$kat) {
        $kat['id'] = (int)$kat['id'];
        $kat['total_produk'] = (int)$kat['total_produk'];
    }

    api_response(true, 'Data kategori berhasil diambil', $kategori);
} catch (PDOException $e) {
    api_response(false, 'Database Error: ' . $e->getMessage(), null, 500);
}
