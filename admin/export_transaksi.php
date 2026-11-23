<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_admin();
require_once __DIR__ . '/includes/ReportExporter.php';

$format      = strtolower(trim($_GET['format'] ?? 'pdf'));
$orientation = strtolower(trim($_GET['orientation'] ?? 'portrait'));
if (!in_array($orientation, ['portrait', 'landscape'], true)) {
    $orientation = 'portrait';
}

// Filter identik dengan halaman Laporan Transaksi
$search           = trim($_GET['search'] ?? '');
$pelanggan_filter = trim($_GET['pelanggan'] ?? '');
$date_start       = trim($_GET['date_start'] ?? '');
$date_end         = trim($_GET['date_end'] ?? '');
$kasir_id         = trim($_GET['kasir_id'] ?? '');

$sql = "
    SELECT 
        pd.id AS detail_id,
        pd.pesanan_id,
        pd.nama_produk,
        pd.harga_satuan,
        pd.jumlah,
        pd.subtotal,
        p.kode_pesanan,
        p.nama_pembeli,
        p.created_at AS tanggal_transaksi,
        u.nama_lengkap AS nama_kasir,
        s.kode_satuan
    FROM pesanan_detail pd
    INNER JOIN pesanan p ON pd.pesanan_id = p.id
    LEFT JOIN users u ON p.user_id = u.id
    LEFT JOIN produk pr ON pd.produk_id = pr.id
    LEFT JOIN satuan s ON pr.satuan_id = s.id
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (p.kode_pesanan LIKE ? OR p.nama_pembeli LIKE ? OR pd.nama_produk LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($pelanggan_filter !== '') {
    $sql .= " AND p.nama_pembeli = ?";
    $params[] = $pelanggan_filter;
}

if ($date_start !== '') {
    $sql .= " AND DATE(p.created_at) >= ?";
    $params[] = $date_start;
}

if ($date_end !== '') {
    $sql .= " AND DATE(p.created_at) <= ?";
    $params[] = $date_end;
}

if ($kasir_id !== '') {
    $sql .= " AND p.user_id = ?";
    $params[] = (int)$kasir_id;
}

$sql .= " ORDER BY p.created_at DESC, pd.id ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $item_list = $stmt->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    die('Gagal mengambil data rincian penjualan: ' . $e->getMessage());
}

$rows = [];
foreach ($item_list as $it) {
    $rows[] = [
        'tanggal_transaksi' => date('d/m/Y H:i', strtotime($it['tanggal_transaksi'])),
        'kode_nota'         => $it['kode_pesanan'] ?? '',
        'nama_barang'       => $it['nama_produk'] ?? 'Barang',
        'jumlah'            => (int)($it['jumlah'] ?? 1),
        'satuan'            => !empty($it['kode_satuan']) ? $it['kode_satuan'] : 'Pcs',
        'harga_jual'        => (float)($it['harga_satuan'] ?? 0),
        'total_harga_jual'  => (float)($it['subtotal'] ?? 0),
        'kasir'             => $it['nama_kasir'] ?? 'Kasir',
        'pelanggan'         => $it['nama_pembeli'] ?? 'Umum',
    ];
}

$filter_notes = [];
if ($date_start !== '' && $date_end !== '') {
    $filter_notes[] = 'Periode: ' . date('d/m/Y', strtotime($date_start)) . ' s/d ' . date('d/m/Y', strtotime($date_end));
} elseif ($date_start !== '') {
    $filter_notes[] = 'Mulai: ' . date('d/m/Y', strtotime($date_start));
} elseif ($date_end !== '') {
    $filter_notes[] = 'Sampai: ' . date('d/m/Y', strtotime($date_end));
}
if ($pelanggan_filter !== '') {
    $filter_notes[] = 'Pelanggan: ' . $pelanggan_filter;
}
$report_subtitle = 'Laporan Rincian Penjualan Barang' . (!empty($filter_notes) ? ' (' . implode(' | ', $filter_notes) . ')' : '');

$admin_user   = current_user();
$app_name     = function_exists('get_app_name') ? get_app_name() : 'QiOS Cendekia';
$yayasan_name = function_exists('get_setting') ? get_setting('nama_yayasan', '') : '';
$logo_rel     = function_exists('get_app_logo_path') ? get_app_logo_path() : 'assets/images/logo.png';
$logo_abs     = __DIR__ . '/../' . $logo_rel;

$meta = [
    'appName'     => $app_name,
    'yayasanName' => $yayasan_name,
    'reportDate'  => SimplePdfReport::formatIndoDate(date('Y-m-d')),
    'subtitle'    => $report_subtitle,
    'adminName'   => $admin_user['nama_lengkap'] ?? 'Administrator',
    'adminRole'   => ucfirst($admin_user['role'] ?? 'Administrator'),
    'logoAbsPath' => $logo_abs,
];

$stamp = date('Ymd_His');

if ($format === 'xlsx' || $format === 'excel') {
    $bin = SimpleXlsxReport::generate($rows, $meta, $orientation);
    $filename = "Laporan_Penjualan_Barang_{$stamp}.xlsx";
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $bin;
    exit;
}

// Default: PDF
$bin = SimplePdfReport::generate($rows, $meta, $orientation);
$filename = "Laporan_Penjualan_Barang_{$orientation}_{$stamp}.pdf";
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($bin));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo $bin;
exit;
