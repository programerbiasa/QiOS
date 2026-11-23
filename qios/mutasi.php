<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_kasir_or_admin();

$user_active = current_user();
$logo_rel_root = function_exists('get_app_logo_path') ? get_app_logo_path() : 'assets/images/logo.png';
$logo_v = file_exists(__DIR__ . '/../' . $logo_rel_root) ? filemtime(__DIR__ . '/../' . $logo_rel_root) : time();
$logo_url_kasir = function_exists('get_app_logo') ? get_app_logo('../') : ('../' . $logo_rel_root . '?v=' . $logo_v);

// ==========================================
// FILTER PENCARIAN & PERIODE MUTASI
// ==========================================
$periode   = trim($_GET['periode'] ?? 'today');
$search    = trim($_GET['search'] ?? '');
$pelanggan = trim($_GET['pelanggan'] ?? '');
$kasir_id  = trim($_GET['kasir_id'] ?? '');

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$d7_ago    = date('Y-m-d', strtotime('-6 days'));
$m_start   = date('Y-m-01');

$date_start = trim($_GET['date_start'] ?? '');
$date_end   = trim($_GET['date_end'] ?? '');

if ($periode === 'today') {
    $date_start = $today;
    $date_end   = $today;
} elseif ($periode === 'yesterday') {
    $date_start = $yesterday;
    $date_end   = $yesterday;
} elseif ($periode === '7days') {
    $date_start = $d7_ago;
    $date_end   = $today;
} elseif ($periode === 'month') {
    $date_start = $m_start;
    $date_end   = $today;
} elseif ($periode === 'all') {
    $date_start = '';
    $date_end   = '';
} else {
    // Custom date jika diinput manual
    $periode = 'custom';
}

// Query Utama Pesanan
$sql = "
    SELECT p.*, u.nama_lengkap AS nama_kasir 
    FROM pesanan p 
    LEFT JOIN users u ON p.user_id = u.id 
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (p.kode_pesanan LIKE ? OR p.nama_pembeli LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($pelanggan !== '') {
    $sql .= " AND p.nama_pembeli = ?";
    $params[] = $pelanggan;
}

if ($kasir_id !== '') {
    $sql .= " AND p.user_id = ?";
    $params[] = (int)$kasir_id;
}

if ($date_start !== '') {
    $sql .= " AND DATE(p.created_at) >= ?";
    $params[] = $date_start;
}

if ($date_end !== '') {
    $sql .= " AND DATE(p.created_at) <= ?";
    $params[] = $date_end;
}

$sql .= " ORDER BY p.created_at DESC, p.id DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $mutasi_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil detail item per pesanan
    $order_ids = array_column($mutasi_list, 'id');
    $details_by_order = [];

    if (!empty($order_ids)) {
        $in_clause = implode(',', array_fill(0, count($order_ids), '?'));
        $stmt_det = $pdo->prepare("SELECT * FROM pesanan_detail WHERE pesanan_id IN ($in_clause) ORDER BY id ASC");
        $stmt_det->execute($order_ids);
        $all_details = $stmt_det->fetchAll(PDO::FETCH_ASSOC);

        foreach ($all_details as $d) {
            $details_by_order[$d['pesanan_id']][] = $d;
        }
    }

    // Hitung ringkasan statistik
    $total_transaksi = count($mutasi_list);
    $total_omset     = 0;
    $total_tunai     = 0;
    $total_kembali   = 0;

    foreach ($mutasi_list as $m) {
        if (($m['status'] ?? 'SELESAI') === 'SELESAI') {
            $total_omset   += (float)$m['total_harga'];
            $total_tunai   += (float)$m['uang_bayar'];
            $total_kembali += (float)$m['kembalian'];
        }
    }

    // Ambil daftar kasir untuk filter (jika admin/superadmin)
    $list_kasir = [];
    if (in_array($user_active['role'], ['admin', 'superadmin'])) {
        $stmt_k = $pdo->query("SELECT id, nama_lengkap FROM users WHERE role = 'kasir' ORDER BY nama_lengkap ASC");
        $list_kasir = $stmt_k->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $mutasi_list = [];
    $details_by_order = [];
    $total_transaksi = 0;
    $total_omset = 0;
    $total_tunai = 0;
    $total_kembali = 0;
    $error_msg = $e->getMessage();
}

$auto_cetak_kode = trim($_GET['cetak'] ?? '');
$theme_info = function_exists('get_current_theme_data') ? get_current_theme_data() : ['mode' => 'dark'];
$theme_key  = function_exists('get_app_theme') ? get_app_theme() : 'midnight';
?>
<!DOCTYPE html>
<html lang="id" data-bs-theme="<?= $theme_info['mode'] ?>" data-theme="<?= e($theme_key) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mutasi Penjualan Kasir - <?= e(get_app_name()) ?></title>
    <!-- Bootstrap 5.3 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-darker: #090d16;
            --bg-card: #111827;
            --border-color: #334155;
            --accent-cyan: #38bdf8;
            --accent-green: #10b981;
        }
        body {
            background-color: var(--bg-darker);
            color: #f1f5f9;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            font-size: 0.84rem;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .mutasi-topbar {
            background-color: #0f172a;
            border-bottom: 1px solid var(--border-color);
            padding: 8px 16px;
        }
        .stat-card {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px 16px;
            transition: transform 0.15s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: #475569;
        }
        .table-mutasi tbody tr {
            cursor: pointer;
            transition: background-color 0.12s;
        }
        .table-mutasi tbody tr:hover {
            background-color: rgba(56, 189, 248, 0.08) !important;
        }
        .struk-preview-box {
            background-color: #ffffff;
            color: #000000;
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            line-height: 1.35;
            width: 100%;
            max-width: 340px;
            margin: 0 auto;
            padding: 14px 16px 20px 16px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.25);
        }
        .dashed-divider {
            border-top: 1px dashed #000000;
            margin: 6px 0;
        }
        .paper-feed-spacer {
            height: 12mm;
            display: block;
        }
        @media print {
            body * {
                visibility: hidden;
            }
            #strukPrintArea, #strukPrintArea * {
                visibility: visible;
            }
            #strukPrintArea {
                position: absolute;
                left: 0;
                top: 0;
                width: 58mm;
                max-width: 100%;
                margin: 0;
                padding: 2mm 3mm;
            }
        }
    </style>
    <?= function_exists('render_theme_styles') ? render_theme_styles() : '' ?>
</head>
<body>

<!-- TOPBAR MUTASI KASIR -->
<header class="mutasi-topbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
        <img src="<?= e($logo_url_kasir) ?>" alt="Logo" style="height: 32px; width: 32px; object-fit: contain;" class="rounded bg-dark p-1">
        <div>
            <div class="fw-bold text-white lh-1 fs-6">
                <?= e(get_app_name()) ?> 
                <span class="badge bg-info text-dark text-uppercase ms-1" style="font-size: 0.65rem;">Mutasi Kasir</span>
            </div>
            <small class="text-secondary" style="font-size: 0.72rem;">Riwayat Penjualan & Cetak Ulang Struk (Read-Only)</small>
        </div>
    </div>

    <!-- Tombol Aksi Kanan -->
    <div class="d-flex align-items-center gap-2">
        <div class="badge bg-dark border border-secondary px-2 py-1 text-info font-monospace d-none d-md-inline-block" id="liveClock" style="font-size: 0.75rem;">
            <?= date('H:i:s') ?> WITA
        </div>

        <!-- Tombol Kembali ke Kasir POS -->
        <a href="index.php" class="btn btn-primary btn-sm rounded-pill px-3 py-1 fw-bold shadow-sm d-flex align-items-center gap-1" style="font-size: 0.78rem;" title="Kembali ke Layar Kasir POS [F1]">
            <i class="bi bi-cart3"></i>
            <span>Kasir POS [F1]</span>
        </a>

        <!-- Tombol Pilih Tema -->
        <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-2 py-1 text-light d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalPilihTema" title="Pilih Tema Warna (Terang, Kalem, Lembut, Gelap)">
            <i class="bi bi-palette2 text-warning"></i>
            <span class="d-none d-md-inline" style="font-size: 0.75rem;">Tema</span>
        </button>

        <?php if (in_array($user_active['role'], ['admin', 'superadmin'])): ?>
            <a href="../admin/index.php" class="btn btn-outline-warning btn-sm rounded-pill px-2 py-1 fw-semibold" style="font-size: 0.75rem;" title="Panel Admin">
                <i class="bi bi-speedometer2"></i> Admin
            </a>
        <?php endif; ?>

        <!-- Identitas Kasir -->
        <div class="d-flex align-items-center bg-dark border border-secondary px-2 py-1 rounded-pill">
            <i class="bi bi-person-circle text-success me-1"></i>
            <span class="fw-bold text-white" style="font-size: 0.75rem;"><?= e($user_active['nama_lengkap']) ?></span>
        </div>

        <a href="../logout.php" class="btn btn-outline-danger btn-sm rounded-circle p-1" style="width: 26px; height: 26px; display: flex; align-items: center; justify-content: center;" title="Keluar / Logout" onclick="return confirm('Keluar dari sesi kasir?');">
            <i class="bi bi-power" style="font-size: 0.8rem;"></i>
        </a>
    </div>
</header>

<main class="container-fluid py-3 px-3 flex-grow-1">

    <?php if (isset($error_msg)): ?>
        <div class="alert alert-danger shadow-sm py-2 px-3 mb-3 small">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error_msg) ?>
        </div>
    <?php endif; ?>

    <!-- FILTER PENCARIAN & PERIODE -->
    <div class="card border-secondary p-3 mb-3 rounded-4" style="background-color: var(--bg-card);">
        <form action="mutasi.php" method="GET" class="row g-2 align-items-center">
            <!-- Quick Periode Pills -->
            <div class="col-12 pb-1">
                <div class="d-flex gap-1 overflow-x-auto">
                    <a href="mutasi.php?periode=today" class="btn btn-sm <?= ($periode === 'today') ? 'btn-info text-dark fw-bold' : 'btn-outline-secondary text-light' ?> rounded-pill px-3 py-0" style="font-size: 0.75rem;">
                        Hari Ini
                    </a>
                    <a href="mutasi.php?periode=yesterday" class="btn btn-sm <?= ($periode === 'yesterday') ? 'btn-info text-dark fw-bold' : 'btn-outline-secondary text-light' ?> rounded-pill px-3 py-0" style="font-size: 0.75rem;">
                        Kemarin
                    </a>
                    <a href="mutasi.php?periode=7days" class="btn btn-sm <?= ($periode === '7days') ? 'btn-info text-dark fw-bold' : 'btn-outline-secondary text-light' ?> rounded-pill px-3 py-0" style="font-size: 0.75rem;">
                        7 Hari Terakhir
                    </a>
                    <a href="mutasi.php?periode=month" class="btn btn-sm <?= ($periode === 'month') ? 'btn-info text-dark fw-bold' : 'btn-outline-secondary text-light' ?> rounded-pill px-3 py-0" style="font-size: 0.75rem;">
                        Bulan Ini
                    </a>
                    <a href="mutasi.php?periode=all" class="btn btn-sm <?= ($periode === 'all') ? 'btn-info text-dark fw-bold' : 'btn-outline-secondary text-light' ?> rounded-pill px-3 py-0" style="font-size: 0.75rem;">
                        Semua Periode
                    </a>
                </div>
            </div>

            <input type="hidden" name="periode" value="<?= e($periode) ?>">

            <!-- Cari Kata Kunci -->
            <div class="col-md-3">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control bg-dark border-secondary text-white" placeholder="Cari Kode TRX / Pelanggan..." value="<?= e($search) ?>">
                </div>
            </div>

            <!-- Filter Pelanggan -->
            <div class="col-md-2 col-6">
                <select name="pelanggan" class="form-select form-select-sm bg-dark border-secondary text-white">
                    <option value="">Semua Pelanggan</option>
                    <option value="Umum" <?= ($pelanggan === 'Umum') ? 'selected' : '' ?>>Umum</option>
                    <option value="Siswa" <?= ($pelanggan === 'Siswa') ? 'selected' : '' ?>>Siswa</option>
                    <option value="Guru" <?= ($pelanggan === 'Guru') ? 'selected' : '' ?>>Guru</option>
                </select>
            </div>

            <!-- Tanggal Dari -->
            <div class="col-md-2 col-6">
                <input type="date" name="date_start" class="form-control form-control-sm bg-dark border-secondary text-white" value="<?= e($date_start) ?>" title="Tanggal Mulai">
            </div>

            <!-- Tanggal Sampai -->
            <div class="col-md-2 col-6">
                <input type="date" name="date_end" class="form-control form-control-sm bg-dark border-secondary text-white" value="<?= e($date_end) ?>" title="Tanggal Sampai">
            </div>

            <!-- Tombol Submit -->
            <div class="col-md-3 col-6 d-flex gap-1">
                <button type="submit" class="btn btn-info btn-sm w-100 rounded-3 text-dark fw-bold">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="mutasi.php" class="btn btn-outline-secondary btn-sm rounded-3" title="Reset Filter">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- TABEL DAFTAR MUTASI PENJUALAN (READ ONLY) -->
    <div class="card border-secondary shadow rounded-4 overflow-hidden mb-4" style="background-color: var(--bg-card);">
        <div class="card-header bg-dark border-secondary py-2 px-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-white d-flex align-items-center gap-2">
                <i class="bi bi-clock-history text-info"></i>
                <span>Daftar Mutasi Transaksi</span>
                <span class="badge bg-secondary text-light font-monospace" style="font-size: 0.7rem;"><?= count($mutasi_list) ?> Baris</span>
            </h6>
            <div class="text-secondary small" style="font-size: 0.72rem;">
                <i class="bi bi-info-circle me-1"></i>Klik baris transaksi atau tombol printer untuk cetak struk
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-dark align-middle mb-0 table-mutasi" style="font-size: 0.78rem;">
                <thead class="table-secondary" style="font-size: 0.75rem;">
                    <tr>
                        <th scope="col" style="width: 45px;" class="text-center">No</th>
                        <th scope="col" style="width: 170px;">Kode Nota</th>
                        <th scope="col" style="width: 140px;">Waktu</th>
                        <th scope="col" style="width: 120px;">Kasir</th>
                        <th scope="col" style="width: 90px;">Pelanggan</th>
                        <th scope="col">Ringkasan Item Belanja</th>
                        <th scope="col" class="text-end" style="width: 120px;">Total Tagihan</th>
                        <th scope="col" class="text-center" style="width: 90px;">Status</th>
                        <th scope="col" class="text-center" style="width: 110px;">Cetak</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mutasi_list)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-secondary">
                                <i class="bi bi-inbox fs-2 d-block text-muted mb-2"></i>
                                Tidak ada data mutasi penjualan untuk periode atau filter yang dipilih.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $no = 1;
                        foreach ($mutasi_list as $trx): 
                            $items = $details_by_order[$trx['id']] ?? [];
                            $total_item_count = 0;
                            $summary_text = [];
                            foreach ($items as $it) {
                                $total_item_count += (int)$it['jumlah'];
                                $summary_text[] = e($it['nama_produk']) . ' (x' . (int)$it['jumlah'] . ')';
                            }
                            $summary_display = implode(', ', array_slice($summary_text, 0, 3));
                            if (count($summary_text) > 3) {
                                $summary_display .= ', +' . (count($summary_text) - 3) . ' lainnya';
                            }
                        ?>
                            <tr onclick='openStrukModal(<?= json_encode($trx, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($items, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <td class="text-center text-secondary"><?= $no++ ?></td>
                                <td>
                                    <span class="badge bg-dark border border-secondary text-info font-monospace py-1 px-2" style="font-size: 0.75rem;">
                                        <?= e($trx['kode_pesanan']) ?>
                                    </span>
                                </td>
                                <td class="text-secondary font-monospace">
                                    <?= date('d/m/Y H:i', strtotime($trx['created_at'])) ?>
                                </td>
                                <td>
                                    <span class="text-light fw-semibold"><?= e($trx['nama_kasir'] ?? 'Kasir') ?></span>
                                </td>
                                <td>
                                    <?php 
                                    $p_badge = 'bg-secondary';
                                    if ($trx['nama_pembeli'] === 'Siswa') $p_badge = 'bg-primary';
                                    elseif ($trx['nama_pembeli'] === 'Guru') $p_badge = 'bg-warning text-dark';
                                    ?>
                                    <span class="badge <?= $p_badge ?> rounded-pill px-2" style="font-size: 0.7rem;">
                                        <?= e($trx['nama_pembeli']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="text-white text-truncate" style="max-width: 320px;" title="<?= e(implode(', ', $summary_text)) ?>">
                                        <span class="badge bg-dark text-info me-1"><?= $total_item_count ?> pcs</span>
                                        <?= $summary_display ?>
                                    </div>
                                </td>
                                <td class="text-end fw-bold font-monospace text-success">
                                    <?= rupiah($trx['total_harga']) ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($trx['status'] === 'SELESAI'): ?>
                                        <span class="badge bg-success bg-opacity-25 text-success rounded-pill px-2">Selesai</span>
                                    <?php elseif ($trx['status'] === 'BATAL'): ?>
                                        <span class="badge bg-danger bg-opacity-25 text-danger rounded-pill px-2">Batal</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning bg-opacity-25 text-warning rounded-pill px-2"><?= e($trx['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" onclick="event.stopPropagation();">
                                    <button type="button" class="btn btn-outline-info btn-sm rounded-pill px-2 py-0 fw-semibold d-inline-flex align-items-center gap-1 shadow-sm" 
                                            onclick='openStrukModal(<?= json_encode($trx, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($items, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                            title="Buka & Cetak Struk Thermal 58mm">
                                        <i class="bi bi-printer-fill text-warning"></i>
                                        <span>Cetak</span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- ======================================================== -->
<!-- MODAL STRUK SIAP CETAK (THERMAL 58MM) -->
<!-- ======================================================== -->
<div class="modal fade" id="modalStrukThermal" tabindex="-1" style="background: rgba(0,0,0,0.85);" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
        <div class="modal-content text-dark border-0 shadow-lg rounded-4 overflow-hidden bg-white">
            <div class="modal-header bg-light py-2 px-3 border-bottom d-flex justify-content-between align-items-center no-print">
                <span class="fw-bold small text-dark d-flex align-items-center gap-1">
                    <i class="bi bi-printer-fill text-primary"></i> 
                    <span>Struk Penjualan (Thermal 58mm)</span>
                </span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>

            <div class="modal-body p-3" id="strukPrintArea" style="width: 100%; max-width: 340px; margin: 0 auto;">
                <div class="text-center mb-2">
                    <img src="<?= e($logo_url_kasir) ?>" alt="Logo" style="height: 38px; display: block; margin: 0 auto 4px;" class="mb-1">
                    <div class="fw-bold text-dark" style="font-size: 13px; letter-spacing: 0.5px;"><?= strtoupper(e(get_app_name())) ?></div>
                    <div class="small text-muted" style="font-size: 9.5px;">Sistem POS Kasir Cerdas</div>
                    <div class="text-secondary" style="font-size: 9px;" id="strukWaktu">-</div>
                </div>

                <div class="dashed-divider my-2"></div>

                <div class="small font-monospace" style="font-size: 10px; line-height: 1.35;">
                    <div class="d-flex justify-content-between">
                        <span>No.TRX:</span>
                        <strong id="strukKode">-</strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Kasir :</span>
                        <span id="strukKasir">-</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Plggn :</span>
                        <span id="strukPembeli">-</span>
                    </div>
                </div>

                <div class="dashed-divider my-2"></div>

                <!-- Daftar Item Rapi 2 Baris untuk Kertas 58mm -->
                <div class="font-monospace" id="strukItemsContainer" style="font-size: 10.5px;">
                    <!-- Injected by JavaScript -->
                </div>

                <div class="dashed-divider my-2"></div>

                <!-- Rincian Total -->
                <div class="font-monospace" style="font-size: 11px; line-height: 1.4;">
                    <div class="d-flex justify-content-between fw-bold" style="font-size: 12px;">
                        <span>TOTAL:</span>
                        <span id="strukTotal">Rp 0</span>
                    </div>
                    <div class="d-flex justify-content-between text-secondary" style="font-size: 10.5px;">
                        <span>TUNAI:</span>
                        <span id="strukTunai">Rp 0</span>
                    </div>
                    <div class="d-flex justify-content-between fw-bold text-dark" style="font-size: 11.5px;">
                        <span>KEMBALI:</span>
                        <span id="strukKembali">Rp 0</span>
                    </div>
                </div>

                <div class="dashed-divider my-2"></div>

                <div class="text-center small font-monospace" style="font-size: 9.5px; line-height: 1.3;">
                    <div>*** TERIMA KASIH ***</div>
                    <div>Barang yang sudah dibeli</div>
                    <div>tidak dapat ditukar/dikembalikan</div>
                </div>

                <!-- Jarak potong kertas (feed spacer) agar teks tidak terpotong pisau gerigi printer thermal -->
                <div class="paper-feed-spacer"></div>
            </div>

            <div class="modal-footer bg-light p-2 justify-content-center no-print gap-2">
                <button type="button" class="btn btn-dark btn-sm rounded-pill px-4 fw-bold shadow-sm d-flex align-items-center gap-2" id="btnCetakModal" onclick="cetakStrukThermal()">
                    <i class="bi bi-printer-fill text-warning"></i>
                    <span>Cetak Struk (58mm)</span>
                </button>
                <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">
                    Tutup
                </button>
            </div>
            <div class="px-3 pb-2 text-center no-print" style="font-size: 0.72rem; color: #64748b;">
                <i class="bi bi-info-circle me-1"></i>Format thermal 58mm (PT-210) siap cetak langsung.
            </div>
        </div>
    </div>
</div>

<!-- SCRIPTS & PRINTING ENGINE -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Format Rupiah
function formatRupiahJs(number) {
    return 'Rp ' + (new Intl.NumberFormat('id-ID').format(Math.round(number)));
}

// Buka Modal Struk Siap Cetak
function openStrukModal(trx, items) {
    document.getElementById('strukKode').innerText = trx.kode_pesanan || '-';
    document.getElementById('strukKasir').innerText = trx.nama_kasir || 'Kasir';
    document.getElementById('strukPembeli').innerText = trx.nama_pembeli || 'Umum';
    document.getElementById('strukWaktu').innerText = trx.created_at || '';

    // Render Items
    const container = document.getElementById('strukItemsContainer');
    container.innerHTML = '';

    if (items && items.length > 0) {
        items.forEach(it => {
            const row = document.createElement('div');
            row.className = 'mb-1';
            row.innerHTML = `
                <div class="fw-bold text-dark text-truncate" style="max-width: 100%;">${it.nama_produk}</div>
                <div class="d-flex justify-content-between text-secondary" style="font-size: 10px;">
                    <span>${it.jumlah} x ${formatRupiahJs(it.harga_satuan)}</span>
                    <span class="text-dark fw-bold">${formatRupiahJs(it.subtotal)}</span>
                </div>
            `;
            container.appendChild(row);
        });
    } else {
        container.innerHTML = '<div class="text-muted text-center py-1">Tidak ada rincian item</div>';
    }

    // Render Total, Tunai, Kembali
    document.getElementById('strukTotal').innerText = formatRupiahJs(trx.total_harga || 0);
    document.getElementById('strukTunai').innerText = formatRupiahJs(trx.uang_bayar || trx.total_harga || 0);
    document.getElementById('strukKembali').innerText = formatRupiahJs(trx.kembalian || 0);

    const modalEl = document.getElementById('modalStrukThermal');
    const modalObj = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalObj.show();

    // Focus tombol cetak untuk kenyamanan kasir
    setTimeout(() => {
        const btn = document.getElementById('btnCetakModal');
        if (btn) btn.focus();
    }, 300);
}

// Fungsi Cetak Struk Khusus Thermal 58mm (Menggunakan Iframe Terisolasi)
function cetakStrukThermal() {
    const strukEl = document.getElementById('strukPrintArea');
    if (!strukEl) {
        window.print();
        return;
    }
    const printContent = strukEl.innerHTML;
    let iframe = document.getElementById('printStrukFrame');
    if (iframe) {
        iframe.remove();
    }
    iframe = document.createElement('iframe');
    iframe.id = 'printStrukFrame';
    iframe.style.position = 'fixed';
    iframe.style.top = '-9999px';
    iframe.style.left = '-9999px';
    iframe.style.width = '300px';
    iframe.style.height = '600px';
    iframe.style.border = 'none';
    document.body.appendChild(iframe);

    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Struk Transaksi - Mutasi</title>
            <style>
                @page {
                    margin: 0;
                    size: auto;
                }
                * {
                    box-sizing: border-box;
                    margin: 0;
                    padding: 0;
                }
                body {
                    font-family: 'Courier New', Courier, monospace;
                    font-size: 11px;
                    color: #000000;
                    background: #ffffff;
                    width: 58mm;
                    max-width: 100%;
                    padding: 3mm 4mm 10mm 4mm;
                    margin: 0 auto;
                    line-height: 1.3;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .text-center { text-align: center; }
                .text-start { text-align: left; }
                .text-end { text-align: right; }
                .fw-bold { font-weight: bold; }
                .text-truncate {
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                .d-flex {
                    display: flex;
                    align-items: center;
                }
                .justify-content-between {
                    justify-content: space-between;
                }
                .dashed-divider {
                    border-top: 1px dashed #000;
                    margin: 5px 0;
                }
                .receipt-item {
                    margin-bottom: 4px;
                }
                img {
                    max-height: 38px;
                    display: block;
                    margin: 0 auto 3px;
                }
                .paper-feed-spacer {
                    height: 12mm;
                }
            </style>
        </head>
        <body>
            ${printContent}
        </body>
        </html>
    `);
    doc.close();

    setTimeout(() => {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }, 250);
}

// Pintasan Keyboard:
// F1 -> Kembali ke Kasir POS
// Esc -> Tutup modal
window.addEventListener('keydown', function(e) {
    if (e.key === 'F1') {
        e.preventDefault();
        window.location.href = 'index.php';
    }
});

// Update live clock
function updateLiveClock() {
    const el = document.getElementById('liveClock');
    if (el) {
        const now = new Date();
        const h = String(now.getHours()).padStart(2, '0');
        const m = String(now.getMinutes()).padStart(2, '0');
        const s = String(now.getSeconds()).padStart(2, '0');
        el.innerText = `${h}:${m}:${s} WITA`;
    }
}
setInterval(updateLiveClock, 1000);

<?php if ($auto_cetak_kode !== ''): ?>
// Auto-open modal struk jika ada parameter ?cetak=KODE_PESANAN
document.addEventListener('DOMContentLoaded', function() {
    const targetKode = <?= json_encode($auto_cetak_kode) ?>;
    <?php foreach ($mutasi_list as $t): ?>
        if (targetKode === <?= json_encode($t['kode_pesanan']) ?>) {
            openStrukModal(<?= json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($details_by_order[$t['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
        }
    <?php endforeach; ?>
});
<?php endif; ?>
</script>
<?= function_exists('render_theme_modal') ? render_theme_modal() : '' ?>
</body>
</html>
