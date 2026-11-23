<?php
// Endpoint polling notifikasi transaksi kasir baru untuk Admin Panel
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/database.php';

$last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

try {
    // Jika last_id == 0, inisialisasi baseline ID terakhir agar tidak spam notifikasi transaksi lama
    if ($last_id <= 0) {
        $stmt_max = $pdo->query("SELECT COALESCE(MAX(id), 0) AS max_id FROM pesanan");
        $max_id = (int)$stmt_max->fetch()['max_id'];

        echo json_encode([
            'success'   => true,
            'has_new'   => false,
            'latest_id' => $max_id,
            'orders'    => []
        ]);
        exit;
    }

    // Ambil pesanan baru yang ID-nya lebih besar dari last_id
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.kode_pesanan,
            p.nama_pembeli,
            p.total_harga,
            p.uang_bayar,
            p.kembalian,
            p.created_at,
            COALESCE(u.nama_lengkap, 'Petugas Kasir') AS nama_kasir,
            (SELECT COUNT(*) FROM pesanan_detail WHERE pesanan_id = p.id) AS total_items
        FROM pesanan p
        LEFT JOIN users u ON p.user_id = u.id
        WHERE p.id > ? AND p.status = 'SELESAI'
        ORDER BY p.id ASC
        LIMIT 10
    ");
    $stmt->execute([$last_id]);
    $new_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted_orders = [];
    $max_found_id = $last_id;

    foreach ($new_orders as $o) {
        $max_found_id = max($max_found_id, (int)$o['id']);
        $formatted_orders[] = [
            'id'           => (int)$o['id'],
            'kode_pesanan' => $o['kode_pesanan'],
            'nama_kasir'   => $o['nama_kasir'],
            'nama_pembeli' => $o['nama_pembeli'],
            'total_harga'  => (float)$o['total_harga'],
            'total_items'  => (int)$o['total_items'],
            'waktu'        => date('H:i:s', strtotime($o['created_at'])) . ' WIB',
        ];
    }

    echo json_encode([
        'success'   => true,
        'has_new'   => count($formatted_orders) > 0,
        'latest_id' => $max_found_id,
        'orders'    => $formatted_orders
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
