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

// Jika akun adalah Admin/Superadmin dan tidak sedang memilih mode kasir khusus (?mode=kasir),
// arahkan otomatis ke Halaman Dashboard Administrasi
if (!empty($user_active) && in_array($user_active['role'], ['admin', 'superadmin']) && !isset($_GET['mode'])) {
    header('Location: ../admin/index.php');
    exit;
}
// ==========================================
// HANDLE PROSES CHECKOUT TRANSAKSI (POST)
// ==========================================
$transaksi_sukses = null;
$error_transaksi = '';
// ==========================================
// HANDLE PEMBATALAN TRANSAKSI (PEMBELI INGIN TAMBAH BARANG)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batal_tambah_belanja') {
    $batal_id = (int)($_POST['pesanan_id'] ?? 0);
    if ($batal_id > 0) {
        try {
            $pdo->beginTransaction();
            // 1. Ambil detail item dari pesanan_detail
            $stmt_det = $pdo->prepare("
                SELECT pd.*, p.stok, s.kode_satuan 
                FROM pesanan_detail pd 
                LEFT JOIN produk p ON pd.produk_id = p.id 
                LEFT JOIN satuan s ON p.satuan_id = s.id 
                WHERE pd.pesanan_id = ?
            ");
            $stmt_det->execute([$batal_id]);
            $items_batal = $stmt_det->fetchAll();
            $cart_restore = [];
            // 2. Kembalikan stok masing-masing produk ke database
            $stmt_re_stok = $pdo->prepare("UPDATE produk SET stok = stok + ? WHERE id = ?");
            foreach ($items_batal as $ib) {
                if (!empty($ib['produk_id'])) {
                    $stmt_re_stok->execute([$ib['jumlah'], $ib['produk_id']]);
                }
                // Siapkan data keranjang untuk di-load kembali di kasir
                $cart_restore[] = [
                    'id'      => (int)$ib['produk_id'],
                    'nama'    => $ib['nama_produk'],
                    'harga'   => (float)$ib['harga_satuan'],
                    'qty'     => (int)$ib['jumlah'],
                    'stokMax' => (int)($ib['stok'] ?? 0) + (int)$ib['jumlah'],
                    'satuan'  => $ib['kode_satuan'] ?: 'pcs'
                ];
            }
            // 3. Ambil data pesanan untuk nama_pembeli
            $stmt_p = $pdo->prepare("SELECT nama_pembeli FROM pesanan WHERE id = ?");
            $stmt_p->execute([$batal_id]);
            $p_info = $stmt_p->fetch();
            $restore_pembeli = $p_info['nama_pembeli'] ?? 'Umum';
            // 4. Hapus pesanan (cascade ke pesanan_detail)
            $pdo->prepare("DELETE FROM pesanan WHERE id = ?")->execute([$batal_id]);
            $pdo->commit();
            // Simpan ke session untuk di-load otomatis ke JS cart
            $_SESSION['restore_cart'] = $cart_restore;
            $_SESSION['restore_pembeli'] = $restore_pembeli;
            $_SESSION['flash_success'] = "Transaksi dibatalkan. Seluruh barang telah dikembalikan ke keranjang kasir untuk penambahan belanja.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = "Gagal membatalkan transaksi: " . $e->getMessage();
        }
    }
    header('Location: index.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bayar_transaksi') {
    $nama_pembeli = trim($_POST['nama_pembeli'] ?? 'Umum');
    if ($nama_pembeli === '') $nama_pembeli = 'Umum';
    $cart_data_raw = $_POST['cart_json'] ?? '[]';
    $cart_items    = json_decode($cart_data_raw, true);
    $uang_bayar    = (float)($_POST['uang_bayar'] ?? 0);
    if (empty($cart_items) || !is_array($cart_items)) {
        $error_transaksi = 'Keranjang belanja masih kosong!';
    } else {
        try {
            $pdo->beginTransaction();
            $total_belanja = 0;
            $items_validated = [];
            // 1. Verifikasi stok setiap item secara akurat
            foreach ($cart_items as $item) {
                $pid = (int)($item['id'] ?? 0);
                $qty = (int)($item['qty'] ?? 0);
                if ($pid <= 0 || $qty <= 0) continue;
                $stmt = $pdo->prepare("SELECT id, nama_produk, harga, stok FROM produk WHERE id = ? FOR UPDATE");
                $stmt->execute([$pid]);
                $p = $stmt->fetch();
                if (!$p) {
                    throw new Exception("Produk ID {$pid} tidak ditemukan di database.");
                }
                if ((int)$p['stok'] < $qty) {
                    $satuan_info = !empty($p['kode_satuan']) ? $p['kode_satuan'] : 'pcs';
                    throw new Exception("Stok untuk '{$p['nama_produk']}' tidak cukup. Sisa stok: {$p['stok']} {$satuan_info}.");
                }
                $subtotal = $qty * (float)$p['harga'];
                $total_belanja += $subtotal;
                $items_validated[] = [
                    'produk_id' => $p['id'],
                    'nama_produk' => $p['nama_produk'],
                    'harga' => (float)$p['harga'],
                    'jumlah' => $qty,
                    'subtotal' => $subtotal,
                    'stok_baru' => (int)$p['stok'] - $qty
                ];
            }
            if (empty($items_validated)) {
                throw new Exception("Tidak ada item valid dalam pesanan.");
            }
            // Validasi Uang Bayar
            if ($uang_bayar < $total_belanja) {
                throw new Exception("Uang pembayaran kurang! Total: " . rupiah($total_belanja) . ", Uang Diterima: " . rupiah($uang_bayar));
            }
            $kembalian = $uang_bayar - $total_belanja;
            $kode_pesanan = 'TRX-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
            // 2. Simpan Header Pesanan
            $stmt_order = $pdo->prepare("
                INSERT INTO pesanan (kode_pesanan, user_id, nama_pembeli, total_harga, uang_bayar, kembalian, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'SELESAI', NOW())
            ");
            $stmt_order->execute([
                $kode_pesanan,
                $user_active['id'],
                $nama_pembeli,
                $total_belanja,
                $uang_bayar,
                $kembalian
            ]);
            $pesanan_id = $pdo->lastInsertId();
            // 3. Simpan Detail Pesanan & Kurangi Stok
            $stmt_detail = $pdo->prepare("
                INSERT INTO pesanan_detail (pesanan_id, produk_id, nama_produk, harga_satuan, jumlah, subtotal)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt_up_stock = $pdo->prepare("UPDATE produk SET stok = ? WHERE id = ?");
            foreach ($items_validated as $iv) {
                $stmt_detail->execute([
                    $pesanan_id,
                    $iv['produk_id'],
                    $iv['nama_produk'],
                    $iv['harga'],
                    $iv['jumlah'],
                    $iv['subtotal']
                ]);
                $stmt_up_stock->execute([$iv['stok_baru'], $iv['produk_id']]);
            }
            $pdo->commit();
            // Set data sukses untuk struk
            $transaksi_sukses = [
                'pesanan_id'   => $pesanan_id,
                'kode_pesanan' => $kode_pesanan,
                'nama_pembeli' => $nama_pembeli,
                'kasir'        => $user_active['nama_lengkap'],
                'total_harga'  => $total_belanja,
                'uang_bayar'   => $uang_bayar,
                'kembalian'    => $kembalian,
                'waktu'        => date('d/m/Y H:i:s'),
                'items'        => $items_validated
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_transaksi = $e->getMessage();
        }
    }
}
// Ambil Kategori dan Daftar Produk dari Database
try {
    $stmt_kat = $pdo->query("SELECT * FROM kategori ORDER BY nama_kategori ASC");
    $kategori_list = $stmt_kat->fetchAll();
    $stmt_prod = $pdo->query("
        SELECT p.*, k.nama_kategori, s.nama_satuan, s.kode_satuan 
        FROM produk p
        LEFT JOIN kategori k ON p.kategori_id = k.id
        LEFT JOIN satuan s ON p.satuan_id = s.id
        ORDER BY p.stok > 0 DESC, p.nama_produk ASC
    ");
    $produk_list = $stmt_prod->fetchAll();
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
$theme_info = function_exists('get_current_theme_data') ? get_current_theme_data() : ['mode' => 'dark'];
$theme_key  = function_exists('get_app_theme') ? get_app_theme() : 'midnight';
?>
<!DOCTYPE html>
<html lang="id" data-bs-theme="<?= $theme_info['mode'] ?>" data-theme="<?= e($theme_key) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplikasi Kasir POS - <?= e(get_app_name()) ?></title>
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../assets/images/favicon.ico">
    <!-- Bootstrap 5.3 Dark -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-darker: #090d16;
            --bg-card: #111827;
            --bg-card-header: #1e293b;
            --border-color: #334155;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --accent-cyan: #38bdf8;
        }
        body {
            background-color: var(--bg-darker);
            color: #f1f5f9;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            font-size: 0.84rem; /* Lebih ringkas & luas */
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            zoom: 0.92; /* Skala compact luas proporsional */
        }
        /* Topbar Kasir Ringkas */
        .kasir-topbar {
            background-color: #0f172a;
            border-bottom: 1px solid var(--border-color);
            padding: 5px 14px;
        }
        /* Grid Produk Kiri Compact & Luas */
        .pos-product-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 9px;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
            user-select: none;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .pos-product-card:hover:not(.out-of-stock) {
            transform: translateY(-2px);
            border-color: #38bdf8;
            box-shadow: 0 4px 12px rgba(56, 189, 248, 0.2);
        }
        .pos-product-card:active:not(.out-of-stock) {
            transform: scale(0.98);
        }
        .pos-product-card.out-of-stock {
            opacity: 0.45;
            cursor: not-allowed;
            border-color: #ef4444;
        }
        /* Ukuran Gambar Produk Lebih Ramping */
        .product-icon-wrap {
            height: 64px;
            background: #1e293b;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .product-icon-wrap img {
            width: 52px;
            height: 52px;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            border-radius: 8px;
        }
        .product-title {
            font-size: 0.77rem;
            line-height: 1.25;
            font-weight: 600;
        }
        .product-price-tag {
            font-size: 0.82rem;
            font-weight: 700;
            color: #10b981;
            font-family: monospace;
        }
        /* Cart Panel Kanan Compact */
        .pos-cart-panel {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            height: calc(100vh - 65px);
            position: sticky;
            top: 55px;
        }
        .cart-items-scroll {
            flex: 1;
            overflow-y: auto;
            max-height: calc(100vh - 410px);
            min-height: 140px;
        }
        /* Total Section Cerdas Compact */
        .total-display-box {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 8px 12px;
        }
        .grand-total-amount {
            font-size: 1.65rem;
            font-weight: 800;
            color: #38bdf8;
            letter-spacing: -0.5px;
            font-family: monospace;
            line-height: 1.1;
        }
        .change-amount {
            font-size: 1.15rem;
            font-weight: 700;
            font-family: monospace;
        }
        .btn-quick-cash {
            background-color: #1e293b;
            border: 1px solid var(--border-color);
            color: #cbd5e1;
            font-size: 0.74rem;
            font-weight: 600;
            border-radius: 6px;
            padding: 4px 2px;
            transition: all 0.12s;
        }
        .btn-quick-cash:hover {
            background-color: #334155;
            color: #ffffff;
            border-color: #94a3b8;
        }
        /* Input Manual Jumlah (Qty) di Keranjang Kasir */
        .cart-qty-input {
            width: 38px !important;
            height: 23px !important;
            background-color: #0f172a !important;
            border: 1px solid #334155 !important;
            color: #ffffff !important;
            font-size: 0.75rem !important;
            font-weight: 700 !important;
            text-align: center !important;
            padding: 0 !important;
            border-radius: 4px !important;
            transition: all 0.15s ease-in-out;
        }
        .cart-qty-input:focus {
            border-color: #38bdf8 !important;
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.3) !important;
            background-color: #1e293b !important;
            outline: none !important;
        }
        .cart-qty-input::-webkit-inner-spin-button,
        .cart-qty-input::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        .cart-qty-input {
            -moz-appearance: textfield;
        }
        /* ======================================================== */
        /* PENGATURAN CETAK STRUK KHUSUS PRINTER THERMAL 58MM & STANDAR */
        /* ======================================================== */
        @media print {
            @page {
                margin: 0mm;
                size: auto;
            }
            html, body {
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                color: #000000 !important;
                font-family: 'Courier New', Courier, monospace !important;
                zoom: 1 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            /* Sembunyikan semua elemen UI layar yang tidak perlu */
            .kasir-topbar,
            .kasir-main,
            .no-print,
            .modal-backdrop,
            .modal-header,
            .modal-footer {
                display: none !important;
            }
            /* Tampilkan modal struk secara langsung */
            .modal {
                position: static !important;
                display: block !important;
                overflow: visible !important;
                background: transparent !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .modal-dialog {
                margin: 0 auto !important;
                padding: 0 !important;
                width: 58mm !important;
                max-width: 58mm !important;
                transform: none !important;
            }
            .modal-content {
                border: none !important;
                box-shadow: none !important;
                background: #ffffff !important;
                color: #000000 !important;
                border-radius: 0 !important;
                overflow: visible !important;
            }
            .modal-body {
                padding: 4mm 4mm 10mm 4mm !important;
                margin: 0 auto !important;
                width: 58mm !important;
                max-width: 58mm !important;
                background: #ffffff !important;
                color: #000000 !important;
                display: block !important;
            }
            #strukPrintArea {
                display: block !important;
                width: 58mm !important;
                max-width: 58mm !important;
                margin: 0 auto !important;
                padding: 0 !important;
                color: #000000 !important;
                background: #ffffff !important;
                font-family: 'Courier New', Courier, monospace !important;
                font-size: 11px !important;
                line-height: 1.3 !important;
            }
            #strukPrintArea * {
                color: #000000 !important;
            }
            #strukPrintArea .dashed-divider {
                border-top: 1px dashed #000000 !important;
                margin: 4px 0 !important;
            }
            #strukPrintArea .paper-feed-spacer {
                height: 12mm !important;
                display: block !important;
            }
        }
    </style>
    <?= function_exists('render_theme_styles') ? render_theme_styles() : '' ?>
</head>
<body>
<!-- TOPBAR KASIR RINGKAS -->
<header class="kasir-topbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
        <img src="<?= e($logo_url_kasir) ?>" alt="Logo" style="height: 30px; width: 30px; object-fit: contain;" class="rounded bg-dark p-1">
        <div>
            <div class="fw-bold text-white lh-1 fs-6"><?= e(get_app_name()) ?> <span class="badge bg-primary text-uppercase" style="font-size: 0.6rem;">POS Kasir</span></div>
        </div>
    </div>
    <!-- Live Jam & Pengatur Skala Layar -->
    <div class="d-flex align-items-center gap-2">
        <!-- Pengatur Skala Kepadatan Layar (Zoom Controller) -->
        <div class="btn-group btn-group-sm bg-dark border border-secondary rounded-pill p-0" title="Atur Kepadatan Tampilan">
            <button type="button" class="btn btn-dark text-secondary px-2 py-0 border-0" onclick="adjustZoom(-0.05)" title="Perkecil Tampilan (Lebih Luas)">A-</button>
            <span class="btn btn-dark text-white px-2 py-0 border-0 disabled small fw-semibold" id="zoomPercentText">92%</span>
            <button type="button" class="btn btn-dark text-secondary px-2 py-0 border-0" onclick="adjustZoom(0.05)" title="Perbesar Tampilan">A+</button>
        </div>
        <div class="badge bg-dark border border-secondary px-2 py-1 text-info font-monospace d-none d-md-inline-block" id="liveClock" style="font-size: 0.75rem;">
            00:00:00 WITA
        </div>
    </div>
    <!-- Petugas Kasir Info & Aksi -->
    <div class="d-flex align-items-center gap-2">
        <!-- Tombol Mutasi Penjualan (Riwayat & Cetak Ulang Struk) -->
        <a href="mutasi.php" class="btn btn-outline-info btn-sm rounded-pill px-3 py-1 fw-bold d-flex align-items-center gap-1 shadow-sm" style="font-size: 0.75rem;" title="Lihat Mutasi Penjualan & Cetak Ulang Struk [F7]">
            <i class="bi bi-clock-history"></i>
            <span>Mutasi [F7]</span>
        </a>

        <!-- Tombol Pilih Tema -->
        <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-2 py-1 text-light d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalPilihTema" title="Pilih Tema Warna (Terang, Kalem, Lembut, Gelap)">
            <i class="bi bi-palette2 text-warning"></i>
            <span class="d-none d-md-inline" style="font-size: 0.75rem;">Tema</span>
        </button>
        <?php if (in_array($user_active['role'], ['admin', 'superadmin'])): ?>
            <a href="../admin/index.php" class="btn btn-outline-warning btn-sm rounded-pill px-2 py-0 fw-semibold" style="font-size: 0.75rem;">
                <i class="bi bi-speedometer2 me-1"></i> Admin
            </a>
        <?php endif; ?>
        <div class="d-flex align-items-center bg-dark border border-secondary px-2 py-1 rounded-pill">
            <i class="bi bi-person-circle text-success me-1" style="font-size: 0.85rem;"></i>
            <div class="text-start">
                <div class="fw-bold lh-1 text-white" style="font-size: 0.75rem;"><?= e($user_active['nama_lengkap']) ?></div>
            </div>
        </div>
        <a href="../logout.php" class="btn btn-outline-danger btn-sm rounded-circle p-1" style="width: 26px; height: 26px; display: flex; align-items: center; justify-content: center;" title="Keluar / Logout" onclick="return confirm('Keluar dari sesi kasir?');">
            <i class="bi bi-power" style="font-size: 0.8rem;"></i>
        </a>
    </div>
</header>
<!-- MAIN CONTAINER POS COMPACT & LUAS -->
<main class="container-fluid py-2 px-2 flex-grow-1">
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow py-2 px-3 mb-2 small fw-semibold" role="alert">
            <i class="bi bi-check-circle-fill me-1"></i> <?= e($_SESSION['flash_success']) ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow py-2 px-3 mb-2 small fw-semibold" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($_SESSION['flash_error']) ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>
    <?php if (!empty($error_transaksi)): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow py-1 px-3 mb-2 small" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1"></i><strong>Gagal:</strong> <?= e($error_transaksi) ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <div class="row g-2">
        <!-- ======================================================== -->
        <!-- SEBELAH KIRI: MENU BARANG / KATALOG POS (LEBIH LUAS) -->
        <!-- ======================================================== -->
        <div class="col-lg-8 col-xl-8 col-xxl-9">
            <!-- Filter & Search Bar Ringkas -->
            <div class="card border-0 p-2 mb-2" style="background-color: var(--bg-card); border: 1px solid var(--border-color) !important; border-radius: 10px;">
                <div class="row g-2 align-items-center">
                    <div class="col-md-5">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-dark border-secondary text-secondary py-0"><i class="bi bi-search"></i></span>
                            <input type="text" id="posSearch" class="form-control bg-dark border-secondary text-white py-1" placeholder="[F2] Cari nama barang / barcode..." autocomplete="off">
                            <button class="btn btn-outline-secondary py-0" type="button" onclick="clearSearch()"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <!-- Kategori Pills Ringkas -->
                        <div class="d-flex gap-1 overflow-x-auto pb-0" id="categoryPills">
                            <button type="button" class="btn btn-sm btn-primary rounded-pill px-2 py-0 kat-btn active" style="font-size: 0.72rem;" data-kat="all" onclick="filterCategory('all', this)">
                                Semua
                            </button>
                            <?php foreach ($kategori_list as $kat): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0 kat-btn text-nowrap" style="font-size: 0.72rem;" data-kat="<?= (int)$kat['id'] ?>" onclick="filterCategory(<?= (int)$kat['id'] ?>, this)">
                                    <?= e($kat['nama_kategori']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Product Grid: Muat Lebih Banyak Produk di Layar (2 s/d 6 Kolom) -->
            <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-xl-5 row-cols-xxl-6 g-2" id="productGrid" style="max-height: calc(100vh - 125px); overflow-y: auto; padding-right: 2px;">
                <?php foreach ($produk_list as $p): ?>
                    <?php 
                        $habis = ((int)$p['stok'] <= 0);
                        $kat_id = (int)($p['kategori_id'] ?? 0);
                        $sat_code = e($p['kode_satuan'] ?? ($p['nama_satuan'] ?? 'pcs'));
                    ?>
                    <div class="col product-item" 
                         data-id="<?= (int)$p['id'] ?>"
                         data-nama="<?= e($p['nama_produk']) ?>"
                         data-harga="<?= (float)$p['harga'] ?>"
                         data-stok="<?= (int)$p['stok'] ?>"
                         data-satuan="<?= $sat_code ?>"
                         data-kat="<?= $kat_id ?>">
                        <div class="pos-product-card p-1 <?= $habis ? 'out-of-stock' : '' ?>" 
                             onclick='addToCart(<?= (int)$p['id'] ?>, <?= json_encode($p['nama_produk']) ?>, <?= (float)$p['harga'] ?>, <?= (int)$p['stok'] ?>, <?= json_encode($sat_code) ?>)'>
                            <div class="product-icon-wrap rounded mb-1">
                                <?php if (!empty($p['foto']) && file_exists(__DIR__ . '/../' . $p['foto'])): ?>
                                    <img src="../<?= e($p['foto']) ?>?v=<?= filemtime(__DIR__ . '/../' . $p['foto']) ?>" alt="Foto" class="rounded">
                                <?php else: ?>
                                    <img src="<?= e($logo_url_kasir) ?>" alt="Icon">
                                <?php endif; ?>
                                <span class="badge position-absolute top-0 end-0 m-1 <?= $habis ? 'bg-danger' : 'bg-secondary' ?>" style="font-size: 0.58rem; padding: 2px 4px;">
                                    <?= $habis ? 'HABIS' : ((int)$p['stok'] . ' ' . $sat_code) ?>
                                </span>
                            </div>
                            <div class="d-flex flex-column flex-grow-1 px-1 pb-1">
                                <div class="product-title text-white text-truncate" title="<?= e($p['nama_produk']) ?>">
                                    <?= e($p['nama_produk']) ?>
                                </div>
                                <div class="mt-auto pt-1 d-flex justify-content-between align-items-center">
                                    <span class="product-price-tag">
                                        <?= rupiah($p['harga']) ?>
                                    </span>
                                    <button type="button" class="btn btn-sm btn-primary rounded-circle p-0" style="width: 20px; height: 20px; font-size: 0.7rem; display: flex; align-items: center; justify-content: center;" <?= $habis ? 'disabled' : '' ?>>
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- ======================================================== -->
        <!-- SEBELAH KANAN: KERANJANG & AUTO TOTAL CERDAS COMPACT -->
        <!-- ======================================================== -->
        <div class="col-lg-4 col-xl-4 col-xxl-3">
            <div class="pos-cart-panel shadow p-2">
                <!-- Header Cart -->
                <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-1 mb-1">
                    <div>
                        <span class="fw-bold text-white small"><i class="bi bi-cart3 text-primary me-1"></i> Pesanan</span>
                        <span class="text-secondary font-monospace ms-1" style="font-size: 0.7rem;" id="invoicePreview">INV-<?= date('ymd') ?></span>
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-2 py-0" style="font-size: 0.7rem;" onclick="clearCart()">
                        <i class="bi bi-trash"></i> Reset
                    </button>
                </div>
                <!-- Dropdown Tipe Pelanggan (Umum, Siswa, Guru) -->
                <div class="input-group input-group-sm mb-1">
                    <span class="input-group-text bg-dark border-secondary text-secondary py-0" title="Kategori Pelanggan">
                        <i class="bi bi-person-badge text-info"></i>
                    </span>
                    <select id="namaPembeli" class="form-select bg-dark border-secondary text-white py-1 fw-semibold" style="font-size: 0.78rem;">
                        <option value="Umum" selected>Umum</option>
                        <option value="Siswa">Siswa</option>
                        <option value="Guru">Guru</option>
                    </select>
                </div>
                <!-- Daftar Item Belanja (Scrollable) -->
                <div class="cart-items-scroll border rounded p-1 mb-1 bg-dark bg-opacity-40" style="border-color: var(--border-color) !important;">
                    <table class="table table-sm table-dark table-borderless align-middle mb-0" style="font-size: 0.76rem;">
                        <tbody id="cartTableBody">
                            <!-- Injected by JavaScript -->
                        </tbody>
                    </table>
                    <div id="cartEmptyNotice" class="text-center text-secondary py-4 small">
                        <i class="bi bi-bag-plus fs-3 d-block text-muted mb-1"></i>
                        Keranjang kosong.<br>Klik produk di sebelah kiri.
                    </div>
                </div>
                <!-- PANEL AUTO TOTAL CERDAS COMPACT -->
                <div class="total-display-box mb-1">
                    <div class="d-flex justify-content-between text-secondary small mb-1" style="font-size: 0.7rem;">
                        <span>Total Item:</span>
                        <span class="fw-bold text-white" id="cartTotalQty">0 unit</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center border-top border-secondary pt-1">
                        <span class="text-white fw-bold small">TOTAL:</span>
                        <div class="grand-total-amount text-end" id="grandTotalText">Rp 0</div>
                    </div>
                </div>
                <!-- Quick Cash Buttons Ringkas -->
                <div class="row g-1 mb-1">
                    <div class="col-4">
                        <button type="button" class="btn btn-quick-cash w-100 fw-semibold" onclick="setCashExact()" title="Tombol Pintas: F8">Uang Pas [F8]</button>
                    </div>
                    <div class="col-4">
                        <button type="button" class="btn btn-quick-cash w-100" onclick="addCash(10000)">+10.000</button>
                    </div>
                    <div class="col-4">
                        <button type="button" class="btn btn-quick-cash w-100" onclick="addCash(20000)">+20.000</button>
                    </div>
                    <div class="col-4">
                        <button type="button" class="btn btn-quick-cash w-100" onclick="setCash(50000)">Rp 50rb</button>
                    </div>
                    <div class="col-4">
                        <button type="button" class="btn btn-quick-cash w-100" onclick="setCash(100000)">Rp 100rb</button>
                    </div>
                    <div class="col-4">
                        <button type="button" class="btn btn-quick-cash w-100" onclick="setCash(200000)">Rp 200rb</button>
                    </div>
                </div>
                <!-- Input Nominal Bayar -->
                <div class="input-group input-group-sm mb-1">
                    <span class="input-group-text bg-dark border-secondary text-white fw-bold py-0" style="font-size: 0.75rem;" title="Tombol Pintas: F4">Bayar [F4]</span>
                    <input type="number" id="inputUangBayar" class="form-control bg-dark border-secondary text-white fw-bold font-monospace fs-6 text-end py-1" placeholder="0" min="0" oninput="calculateChange()">
                </div>
                <!-- Output Kembalian Otomatis -->
                <div class="p-1 px-2 rounded mb-1 d-flex justify-content-between align-items-center" id="changeBox" style="background-color: #0f172a; border: 1px solid var(--border-color);">
                    <span class="small fw-semibold text-secondary" style="font-size: 0.72rem;" id="changeLabel">Kembalian:</span>
                    <span class="change-amount text-success" id="changeText">Rp 0</span>
                </div>
                <!-- Form Submit Tersembunyi -->
                <form id="checkoutForm" action="index.php" method="POST">
                    <input type="hidden" name="action" value="bayar_transaksi">
                    <input type="hidden" name="nama_pembeli" id="formNamaPembeli">
                    <input type="hidden" name="uang_bayar" id="formUangBayar">
                    <input type="hidden" name="cart_json" id="formCartJson">
                    <!-- Tombol Selesaikan Transaksi -->
                    <button type="button" id="btnProsesBayar" class="btn btn-success w-100 rounded-pill fw-bold py-1 shadow d-flex align-items-center justify-content-center gap-1" style="font-size: 0.88rem;" onclick="submitCheckout()" disabled>
                        <i class="bi bi-printer-fill"></i> BAYAR & CETAK [F9]
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>
<!-- ======================================================== -->
<!-- MODAL PRATINJAU / KONFIRMASI STRUK (SEBELUM DISIMPAN KE DB) -->
<!-- ======================================================== -->
<div class="modal fade" id="modalKonfirmasiStruk" tabindex="-1" style="background: rgba(0,0,0,0.85);" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
        <div class="modal-content text-dark border-0 shadow-lg rounded-4 overflow-hidden bg-white">
            <div class="modal-header bg-light py-2 px-3 border-bottom d-flex justify-content-between align-items-center no-print">
                <span class="fw-bold small text-dark"><i class="bi bi-receipt-cutoff text-primary me-1"></i> Konfirmasi Struk (58mm)</span>
                <span class="badge bg-warning text-dark small fw-bold">Siap Cetak</span>
            </div>
            <div class="modal-body p-3" id="previewStrukArea" style="width: 100%; max-width: 340px; margin: 0 auto;">
                <div class="text-center mb-2">
                    <img src="<?= e($logo_url_kasir) ?>" alt="Logo" style="height: 38px;" class="mb-1">
                    <div class="receipt-title fw-bold text-dark" style="font-size: 13px; letter-spacing: 0.5px;"><?= strtoupper(e(get_app_name())) ?></div>
                    <div class="receipt-sub small text-muted" style="font-size: 9.5px;">Sistem POS Kasir Cerdas</div>
                    <div class="receipt-sub text-secondary" style="font-size: 9px;" id="previewWaktuStruk">03/11/2026 00:00:00</div>
                </div>
                <div class="dashed-divider my-2" style="border-top: 1px dashed #000;"></div>
                <div class="small font-monospace" style="font-size: 10px; line-height: 1.35;">
                    <div class="d-flex justify-content-between">
                        <span>No.TRX:</span>
                        <strong id="previewNoTrx">TRX-<?= date('Ymd') ?>-PREV</strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Kasir :</span>
                        <span><?= e($user_active['nama_lengkap']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Plggn :</span>
                        <span id="previewNamaPlggn">Umum</span>
                    </div>
                </div>
                <div class="dashed-divider my-2" style="border-top: 1px dashed #000;"></div>
                <!-- Daftar Item Belanjaan Preview -->
                <div class="receipt-items font-monospace" id="previewItemsList" style="font-size: 10.5px;">
                    <!-- Diisi otomatis oleh JavaScript dari cart -->
                </div>
                <div class="dashed-divider my-2" style="border-top: 1px dashed #000;"></div>
                <!-- Rincian Total -->
                <div class="font-monospace" style="font-size: 11px; line-height: 1.4;">
                    <div class="d-flex justify-content-between fw-bold" style="font-size: 12px;">
                        <span>TOTAL:</span>
                        <span id="previewTotalHarga">Rp 0</span>
                    </div>
                    <div class="d-flex justify-content-between text-secondary" style="font-size: 10.5px;">
                        <span>TUNAI:</span>
                        <span id="previewUangBayar">Rp 0</span>
                    </div>
                    <div class="d-flex justify-content-between fw-bold text-dark" style="font-size: 11.5px;">
                        <span>KEMBALI:</span>
                        <span id="previewKembalian">Rp 0</span>
                    </div>
                </div>
                <div class="dashed-divider my-2" style="border-top: 1px dashed #000;"></div>
                <div class="text-center small font-monospace receipt-footer-text" style="font-size: 9.5px; line-height: 1.3;">
                    <div>*** TERIMA KASIH ***</div>
                    <div>Barang yang sudah dibeli</div>
                    <div>tidak dapat ditukar/dikembalikan</div>
                </div>
                <!-- Jarak potong kertas -->
                <div class="paper-feed-spacer" style="height: 14mm;"></div>
            </div>
            <!-- FOOTER TOMBOL: CETAK STRUK & BATAL / TAMBAH BELANJA -->
            <div class="modal-footer bg-light p-2 justify-content-center no-print gap-2">
                <button type="button" class="btn btn-dark btn-sm rounded-pill px-3 fw-bold shadow-sm" onclick="finalEksekusiBayarDanCetak()">
                    <i class="bi bi-printer-fill me-1 text-warning"></i> Cetak Struk (58mm)
                </button>
                <button type="button" class="btn btn-warning btn-sm rounded-pill px-3 fw-bold text-dark shadow-sm" onclick="tutupPreviewKembaliBelanja()" title="Tutup struk dan kembali ke kasir untuk tambah pembelian">
                    <i class="bi bi-arrow-left-circle-fill me-1"></i> Batal / Tambah Barang
                </button>
            </div>
            <div class="px-3 pb-2 text-center no-print" style="font-size: 0.72rem; color: #64748b;">
                <i class="bi bi-info-circle me-1"></i>Klik <strong>Kembali</strong> jika pembeli ingin menambah pembelian (data belum disimpan).
            </div>
        </div>
    </div>
</div>
<!-- ======================================================== -->
<!-- ======================================================== -->
<!-- MODAL STRUK PEMBAYARAN SUKSES (KHUSUS PRINTER THERMAL 58MM) -->
<!-- ======================================================== -->
<?php if ($transaksi_sukses): ?>
<div class="modal fade show" id="modalStrukSukses" tabindex="-1" style="display: block; background: rgba(0,0,0,0.85);" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
        <div class="modal-content text-dark border-0 shadow-lg rounded-4 overflow-hidden bg-white">
            <div class="modal-header bg-light py-2 px-3 border-bottom d-flex justify-content-between align-items-center no-print">
                <span class="fw-bold small text-dark"><i class="bi bi-printer-fill text-primary me-1"></i> Struk Thermal 58mm (PT-210)</span>
                <span class="badge bg-success small">Sukses</span>
            </div>
            <div class="modal-body p-3" id="strukPrintArea" style="width: 100%; max-width: 340px; margin: 0 auto;">
                <div class="text-center mb-2">
                    <img src="<?= e($logo_url_kasir) ?>" alt="Logo" style="height: 38px;" class="mb-1">
                    <div class="receipt-title fw-bold text-dark" style="font-size: 13px; letter-spacing: 0.5px;"><?= strtoupper(e(get_app_name())) ?></div>
                    <div class="receipt-sub small text-muted" style="font-size: 9.5px;">Sistem POS Kasir Cerdas</div>
                    <div class="receipt-sub text-secondary" style="font-size: 9px;"><?= e($transaksi_sukses['waktu']) ?></div>
                </div>
                <div class="dashed-divider my-2" style="border-top: 1px dashed #000;"></div>
                <div class="small font-monospace" style="font-size: 10px; line-height: 1.35;">
                    <div class="d-flex justify-content-between">
                        <span>No.TRX:</span>
                        <strong><?= e($transaksi_sukses['kode_pesanan']) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Kasir :</span>
                        <span><?= e($transaksi_sukses['kasir']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Plggn :</span>
                        <span><?= e($transaksi_sukses['nama_pembeli']) ?></span>
                    </div>
                </div>
                <div class="dashed-divider my-2" style="border-top: 1px dashed #000;"></div>
                <!-- Daftar Item Rapi 2 Baris untuk Kertas 58mm -->
                <div class="receipt-items font-monospace" style="font-size: 10.5px;">
                    <?php foreach ($transaksi_sukses['items'] as $it): ?>
                        <div class="receipt-item mb-1">
                            <div class="fw-bold text-dark text-truncate" style="max-width: 100%;"><?= e($it['nama_produk']) ?></div>
                            <div class="d-flex justify-content-between text-secondary" style="font-size: 10px;">
                                <span><?= $it['jumlah'] ?> x <?= number_format($it['harga'], 0, ',', '.') ?></span>
                                <span class="text-dark fw-bold"><?= number_format($it['subtotal'], 0, ',', '.') ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="dashed-divider my-2" style="border-top: 1px dashed #000;"></div>
                <!-- Rincian Total -->
                <div class="font-monospace" style="font-size: 11px; line-height: 1.4;">
                    <div class="d-flex justify-content-between fw-bold" style="font-size: 12px;">
                        <span>TOTAL:</span>
                        <span>Rp <?= number_format($transaksi_sukses['total_harga'], 0, ',', '.') ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-secondary" style="font-size: 10.5px;">
                        <span>TUNAI:</span>
                        <span>Rp <?= number_format($transaksi_sukses['uang_bayar'], 0, ',', '.') ?></span>
                    </div>
                    <div class="d-flex justify-content-between fw-bold text-dark" style="font-size: 11.5px;">
                        <span>KEMBALI:</span>
                        <span>Rp <?= number_format($transaksi_sukses['kembalian'], 0, ',', '.') ?></span>
                    </div>
                </div>
                <div class="dashed-divider my-2" style="border-top: 1px dashed #000;"></div>
                <div class="text-center small font-monospace receipt-footer-text" style="font-size: 9.5px; line-height: 1.3;">
                    <div>*** TERIMA KASIH ***</div>
                    <div>Barang yang sudah dibeli</div>
                    <div>tidak dapat ditukar/dikembalikan</div>
                </div>
                <!-- Jarak potong kertas (feed spacer) agar teks tidak terpotong pisau gerigi printer thermal -->
                <div class="paper-feed-spacer" style="height: 14mm;"></div>
            </div>
            <div class="modal-footer bg-light p-2 justify-content-center no-print gap-2 flex-wrap">
                <!-- 1. Tombol Cetak Struk -->
                <button type="button" class="btn btn-dark btn-sm rounded-pill px-3 fw-bold shadow-sm" onclick="cetakStrukThermal()">
                    <i class="bi bi-printer-fill me-1 text-warning"></i> Cetak Struk (58mm)
                </button>

                <!-- 2. Tombol Batal / Tambah Barang Belanjaan -->
                <form action="index.php" method="POST" class="d-inline" onsubmit="return confirm('Apakah pembeli ingin menambah barang belanjaan?\n\nTransaksi ini akan dibatalkan dan seluruh item akan dikembalikan ke kasir agar Anda bisa menambah barang.');">
                    <input type="hidden" name="action" value="batal_tambah_belanja">
                    <input type="hidden" name="pesanan_id" value="<?= (int)($transaksi_sukses['pesanan_id'] ?? 0) ?>">
                    <button type="submit" class="btn btn-warning btn-sm rounded-pill px-3 fw-bold shadow-sm text-dark" title="Batalkan transaksi ini dan kembalikan barang ke kasir untuk ditambah">
                        <i class="bi bi-arrow-left-circle-fill me-1"></i> Batal / Tambah Barang
                    </button>
                </form>

                <!-- 3. Tombol Selesai / Transaksi Baru -->
                <a href="index.php" class="btn btn-success btn-sm rounded-pill px-3 fw-bold shadow-sm text-white" title="Selesai dan mulai transaksi baru">
                    <i class="bi bi-check-circle-fill me-1"></i> Selesai / Transaksi Baru
                </a>

                <!-- 4. Tombol Lihat di Mutasi -->
                <a href="mutasi.php" class="btn btn-outline-info btn-sm rounded-pill px-3 fw-semibold shadow-sm" title="Lihat di Riwayat Mutasi Penjualan & Cetak Ulang Kapan Saja">
                    <i class="bi bi-clock-history me-1"></i> Lihat Mutasi
                </a>
            </div>
            <div class="px-3 pb-2 text-center no-print" style="font-size: 0.72rem; color: #64748b;">
                <i class="bi bi-info-circle me-1"></i>Ukuran telah disesuaikan untuk printer portable 58mm (PT-210).
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<!-- SCRIPT KASIR REAL-TIME & AUTO TOTAL CERDAS -->
<script>
let cart = <?php 
    if (!empty($_SESSION['restore_cart'])) {
        echo json_encode($_SESSION['restore_cart']);
        unset($_SESSION['restore_cart']);
    } else {
        echo '[]';
    }
?>;
let restoredPembeli = <?= json_encode($_SESSION['restore_pembeli'] ?? '') ?>;
<?php unset($_SESSION['restore_pembeli']); ?>
// Fungsi cetak struk khusus printer thermal 58mm & printer standar
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
            <title>Struk Transaksi</title>
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
// Format Rupiah
function formatRupiah(number) {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.max(0, number));
}
// Tambah item ke keranjang
function addToCart(id, nama, harga, stokMax, satuan = 'pcs') {
    if (stokMax <= 0) {
        alert('Stok produk ini habis!');
        return;
    }
    let existing = cart.find(item => item.id === id);
    if (existing) {
        if (existing.qty < stokMax) {
            existing.qty += 1;
        } else {
            alert('Jumlah item telah mencapai stok maksimal yang tersedia (' + stokMax + ' ' + (existing.satuan || 'pcs') + ').');
        }
    } else {
        cart.push({
            id: id,
            nama: nama,
            harga: harga,
            qty: 1,
            stokMax: stokMax,
            satuan: satuan || 'pcs'
        });
    }
    renderCart();
}
// Ubah Qty (+ / -)
function updateCartQty(id, delta) {
    let item = cart.find(i => i.id === id);
    if (!item) return;
    item.qty += delta;
    if (item.qty <= 0) {
        cart = cart.filter(i => i.id !== id);
    } else if (item.qty > item.stokMax) {
        item.qty = item.stokMax;
        alert('Maksimal stok yang tersedia: ' + item.stokMax + ' ' + (item.satuan || 'pcs'));
    }
    renderCart();
}

// Input Manual Qty
function setCartQty(id, val) {
    let item = cart.find(i => i.id === id);
    if (!item) return;
    let qty = parseInt(val, 10);
    if (isNaN(qty) || qty <= 0) {
        removeCartItem(id);
        return;
    }
    if (qty > item.stokMax) {
        alert('Jumlah melebihi stok yang tersedia! Maksimal stok: ' + item.stokMax + ' ' + (item.satuan || 'pcs'));
        qty = item.stokMax;
    }
    if (item.qty === qty) return;
    item.qty = qty;
    renderCart();
}

// Hapus Item
function removeCartItem(id) {
    cart = cart.filter(i => i.id !== id);
    renderCart();
}
// Kosongkan Keranjang
function clearCart() {
    if (cart.length > 0 && confirm('Kosongkan semua barang di kasir?')) {
        cart = [];
        document.getElementById('inputUangBayar').value = '';
        if (document.getElementById('namaPembeli')) {
            document.getElementById('namaPembeli').value = 'Umum';
        }
        renderCart();
    }
}
// Render Keranjang & Hitung Total
function renderCart() {
    const tbody = document.getElementById('cartTableBody');
    const emptyNotice = document.getElementById('cartEmptyNotice');
    tbody.innerHTML = '';
    if (cart.length === 0) {
        emptyNotice.style.display = 'block';
    } else {
        emptyNotice.style.display = 'none';
        cart.forEach(item => {
            const tr = document.createElement('tr');
            const subtotal = item.qty * item.harga;
            tr.innerHTML = `
                <td style="max-width: 110px;">
                    <div class="fw-semibold text-white text-truncate" title="${item.nama}">${item.nama}</div>
                    <small class="text-secondary" style="font-size: 0.68rem;">${formatRupiah(item.harga)} / ${item.satuan || 'pcs'}</small>
                </td>
                <td class="text-center" style="width: 88px; min-width: 88px;">
                    <div class="d-inline-flex align-items-center justify-content-center gap-1">
                        <button type="button" class="btn btn-outline-secondary px-1 py-0" style="font-size: 0.68rem; height: 23px; width: 20px; line-height: 1;" onclick="updateCartQty(${item.id}, -1)" title="Kurang 1">-</button>
                        <input type="number" class="form-control form-control-sm cart-qty-input" 
                               value="${item.qty}" min="1" max="${item.stokMax}" 
                               title="Ketik angka langsung untuk ubah jumlah"
                               onfocus="this.select()" 
                               onchange="setCartQty(${item.id}, this.value)" 
                               onblur="setCartQty(${item.id}, this.value)" 
                               onkeydown="if(event.key==='Enter'){this.blur();}">
                        <button type="button" class="btn btn-outline-secondary px-1 py-0" style="font-size: 0.68rem; height: 23px; width: 20px; line-height: 1;" onclick="updateCartQty(${item.id}, 1)" title="Tambah 1">+</button>
                    </div>
                </td>
                <td class="text-end fw-bold text-success font-monospace" style="font-size: 0.75rem;">${formatRupiah(subtotal)}</td>
                <td class="text-end" style="width: 20px;">
                    <button type="button" class="btn btn-link text-danger p-0" onclick="removeCartItem(${item.id})"><i class="bi bi-x"></i></button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }
    // Auto Hitung Total Cerdas
    let totalHarga = cart.reduce((sum, i) => sum + (i.qty * i.harga), 0);
    let totalQty = cart.reduce((sum, i) => sum + i.qty, 0);
    document.getElementById('grandTotalText').innerText = formatRupiah(totalHarga);
    document.getElementById('cartTotalQty').innerText = totalQty + ' item';
    calculateChange();
}
// Quick Cash Helpers
function setCashExact() {
    let totalHarga = cart.reduce((sum, i) => sum + (i.qty * i.harga), 0);
    document.getElementById('inputUangBayar').value = totalHarga;
    calculateChange();
}
function setCash(nominal) {
    document.getElementById('inputUangBayar').value = nominal;
    calculateChange();
}
function addCash(nominal) {
    let current = parseFloat(document.getElementById('inputUangBayar').value) || 0;
    document.getElementById('inputUangBayar').value = current + nominal;
    calculateChange();
}
// Hitung Kembalian Otomatis
function calculateChange() {
    let totalHarga = cart.reduce((sum, i) => sum + (i.qty * i.harga), 0);
    let bayar = parseFloat(document.getElementById('inputUangBayar').value) || 0;
    let changeLabel = document.getElementById('changeLabel');
    let changeText = document.getElementById('changeText');
    let changeBox = document.getElementById('changeBox');
    let btnBayar = document.getElementById('btnProsesBayar');
    if (cart.length === 0) {
        changeLabel.innerText = 'Kembalian:';
        changeText.innerText = 'Rp 0';
        changeText.className = 'change-amount text-secondary';
        changeBox.style.borderColor = 'var(--border-color)';
        btnBayar.disabled = true;
        return;
    }
    if (bayar >= totalHarga) {
        let kembalian = bayar - totalHarga;
        changeLabel.innerText = 'Kembalian:';
        changeText.innerText = formatRupiah(kembalian);
        changeText.className = 'change-amount text-success fw-bold';
        changeBox.style.borderColor = '#10b981';
        btnBayar.disabled = false;
    } else {
        let kurang = totalHarga - bayar;
        changeLabel.innerText = 'Uang Kurang:';
        changeText.innerText = '- ' + formatRupiah(kurang);
        changeText.className = 'change-amount text-danger fw-bold';
        changeBox.style.borderColor = '#ef4444';
        btnBayar.disabled = true;
    }
}
// Submit Checkout
// Submit Checkout: Tampilkan Konfirmasi Struk Dulu (Data BELUM disimpan ke database)
// Submit Checkout: Tampilkan Konfirmasi Struk Dulu (Data BELUM disimpan ke database)
function submitCheckout() {
    let totalHarga = cart.reduce((sum, i) => sum + (i.qty * i.harga), 0);
    let bayar = parseFloat(document.getElementById('inputUangBayar').value) || 0;

    if (cart.length === 0) {
        alert('Keranjang belanja masih kosong!');
        return;
    }
    if (bayar < totalHarga) {
        alert('Nominal uang pembayaran kurang! Total: ' + formatRupiah(totalHarga) + ', Uang Diterima: ' + formatRupiah(bayar));
        return;
    }

    let nama = document.getElementById('namaPembeli').value.trim();
    if (!nama) nama = 'Umum';

    let kembalian = bayar - totalHarga;

    // Set waktu lokal WITA di preview struk
    const now = new Date();
    const tglStr = String(now.getDate()).padStart(2, '0') + '/' + 
                   String(now.getMonth() + 1).padStart(2, '0') + '/' + 
                   now.getFullYear() + ' ' + 
                   String(now.getHours()).padStart(2, '0') + ':' + 
                   String(now.getMinutes()).padStart(2, '0') + ':' + 
                   String(now.getSeconds()).padStart(2, '0');

    document.getElementById('previewWaktuStruk').innerText = tglStr;
    document.getElementById('previewNamaPlggn').innerText = nama;
    document.getElementById('previewTotalHarga').innerText = formatRupiah(totalHarga);
    document.getElementById('previewUangBayar').innerText = formatRupiah(bayar);
    document.getElementById('previewKembalian').innerText = formatRupiah(kembalian);

    // Render daftar item belanjaan ke preview struk
    let itemsHtml = '';
    cart.forEach(it => {
        let subtotal = it.qty * it.harga;
        itemsHtml += `
            <div class="receipt-item mb-1">
                <div class="fw-bold text-dark text-truncate">${escapeHtml(it.nama)}</div>
                <div class="d-flex justify-content-between text-secondary" style="font-size: 10px;">
                    <span>${it.qty} x ${new Intl.NumberFormat('id-ID').format(it.harga)}</span>
                    <span class="text-dark fw-bold">${new Intl.NumberFormat('id-ID').format(subtotal)}</span>
                </div>
            </div>
        `;
    });
    document.getElementById('previewItemsList').innerHTML = itemsHtml;

    // Tampilkan Modal Konfirmasi Struk (Data BELUM disimpan ke database!)
    let modalEl = document.getElementById('modalKonfirmasiStruk');
    let modalObj = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalObj.show();
}

// Tombol Kembali: Menutup Struk Preview, TIDAK DISIMPAN, Kasir bisa Lanjut Tambah Pembelian
function tutupPreviewKembaliBelanja() {
    let modalEl = document.getElementById('modalKonfirmasiStruk');
    let modalObj = bootstrap.Modal.getInstance(modalEl);
    if (modalObj) {
        modalObj.hide();
    }
}

// Tombol Cetak Struk: Simpan Transaksi Resmi ke Database & Cetak Thermal
function finalEksekusiBayarDanCetak() {
    let totalHarga = cart.reduce((sum, i) => sum + (i.qty * i.harga), 0);
    let bayar = parseFloat(document.getElementById('inputUangBayar').value) || 0;
    let nama = document.getElementById('namaPembeli').value.trim() || 'Umum';

    document.getElementById('formNamaPembeli').value = nama;
    document.getElementById('formUangBayar').value = bayar;
    document.getElementById('formCartJson').value = JSON.stringify(cart);

    // Submit ke database (checkoutForm)
    document.getElementById('checkoutForm').submit();
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
// Live Search Filter
document.getElementById('posSearch').addEventListener('input', function() {
    let query = this.value.toLowerCase().trim();
    let items = document.querySelectorAll('.product-item');
    items.forEach(el => {
        let nama = el.getAttribute('data-nama').toLowerCase();
        let matches = nama.includes(query);
        let activeKat = document.querySelector('.kat-btn.active').getAttribute('data-kat');
        let itemKat = el.getAttribute('data-kat');
        let katMatches = (activeKat === 'all' || activeKat === itemKat);
        el.style.display = (matches && katMatches) ? 'block' : 'none';
    });
});
function clearSearch() {
    document.getElementById('posSearch').value = '';
    document.getElementById('posSearch').dispatchEvent(new Event('input'));
}
// Kategori Filter
function filterCategory(katId, btn) {
    document.querySelectorAll('.kat-btn').forEach(b => {
        b.classList.remove('active', 'btn-primary');
        b.classList.add('btn-outline-secondary');
    });
    btn.classList.add('active', 'btn-primary');
    btn.classList.remove('btn-outline-secondary');
    let query = document.getElementById('posSearch').value.toLowerCase().trim();
    let items = document.querySelectorAll('.product-item');
    items.forEach(el => {
        let nama = el.getAttribute('data-nama').toLowerCase();
        let matches = nama.includes(query);
        let itemKat = el.getAttribute('data-kat');
        let katMatches = (katId === 'all' || katId.toString() === itemKat);
        el.style.display = (matches && katMatches) ? 'block' : 'none';
    });
}
// Pengatur Skala / Kepadatan Layar (Zoom Level)
let currentZoom = parseFloat(localStorage.getItem('pos_zoom') || 0.92);
function applyZoom(zoomVal) {
    currentZoom = Math.min(1.2, Math.max(0.75, Math.round(zoomVal * 100) / 100));
    document.body.style.zoom = currentZoom;
    const txt = document.getElementById('zoomPercentText');
    if (txt) txt.innerText = Math.round(currentZoom * 100) + '%';
    localStorage.setItem('pos_zoom', currentZoom);
}
function adjustZoom(delta) {
    applyZoom(currentZoom + delta);
}
// Terapkan zoom yang tersimpan saat pertama kali dibuka
document.addEventListener('DOMContentLoaded', function() {
    applyZoom(currentZoom);
    if (cart && cart.length > 0) {
        if (restoredPembeli && document.getElementById('namaPembeli')) {
            document.getElementById('namaPembeli').value = restoredPembeli;
        }
        renderCart();
    }
});
// Live Jam Digital WIB
function updateClock() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    const s = String(now.getSeconds()).padStart(2, '0');
    const clockEl = document.getElementById('liveClock');
    if (clockEl) {
        clockEl.innerText = `${h}:${m}:${s} WITA`;
    }
}
setInterval(updateClock, 1000);
updateClock();
// Shortcut Keyboard Kasir Super Ergonomis & Cepat
window.addEventListener('keydown', function(e) {
    // F2: Langsung fokus ke kotak pencarian / barcode
    if (e.key === 'F2') {
        e.preventDefault();
        const searchInput = document.getElementById('posSearch');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }
    // F4: Langsung fokus ke nominal Uang Bayar
    else if (e.key === 'F4') {
        e.preventDefault();
        const bayarInput = document.getElementById('inputUangBayar');
        if (bayarInput) {
            bayarInput.focus();
            bayarInput.select();
        }
    }
    // F7: Buka Halaman Mutasi Penjualan Kasir
    else if (e.key === 'F7') {
        e.preventDefault();
        window.location.href = 'mutasi.php';
    }
    // F8: Tombol Pintas Uang Pas
    else if (e.key === 'F8') {
        e.preventDefault();
        setCashExact();
    }
    // F9: Selesaikan Transaksi & Cetak Struk
    else if (e.key === 'F9') {
        e.preventDefault();
        let btn = document.getElementById('btnProsesBayar');
        if (btn && !btn.disabled) submitCheckout();
    }
    // Escape: Bersihkan pencarian atau tutup modal struk jika aktif
    else if (e.key === 'Escape') {
        const modalStruk = document.getElementById('modalStrukSukses');
        if (modalStruk && modalStruk.style.display !== 'none') {
            window.location.href = 'index.php';
        } else {
            clearSearch();
        }
    }
});
// Smart Global Barcode Scanner Listener
// Menangkap tembakan barcode scanner USB/Bluetooth di mana saja tanpa klik kotak cari
let barcodeBuffer = '';
let lastKeyTime = Date.now();
window.addEventListener('keypress', function(e) {
    const activeEl = document.activeElement;
    // Jangan tangkap jika kasir sedang mengetik di input search atau uang bayar secara sadar
    if (activeEl && (activeEl.id === 'posSearch' || activeEl.id === 'inputUangBayar' || activeEl.tagName === 'INPUT')) {
        return;
    }
    const now = Date.now();
    if (now - lastKeyTime > 120) {
        barcodeBuffer = ''; // Reset buffer jika jeda lebih dari 120ms (ketikan manual)
    }
    lastKeyTime = now;
    if (e.key === 'Enter') {
        if (barcodeBuffer.trim().length >= 2) {
            e.preventDefault();
            handleBarcodeAutoAdd(barcodeBuffer.trim());
            barcodeBuffer = '';
        }
    } else if (e.key.length === 1) {
        barcodeBuffer += e.key;
    }
});
function handleBarcodeAutoAdd(code) {
    const codeLower = code.toLowerCase();
    const items = document.querySelectorAll('.product-item');
    let matched = null;
    items.forEach(el => {
        const nama = el.getAttribute('data-nama').toLowerCase();
        const id = el.getAttribute('data-id');
        if (id === code || nama.includes(codeLower)) {
            if (!matched) matched = el;
        }
    });
    if (matched) {
        const card = matched.querySelector('.pos-product-card');
        if (card) {
            card.click();
            try {
                // Beep audio feedback ringkas tanda sukses scan
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, ctx.currentTime);
                osc.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.08);
            } catch (err) {}
        }
    }
}
</script>
<!-- Popup Chat Pengguna Aktif -->
<?php require_once __DIR__ . '/../includes/chat_widget.php'; ?>
<?= function_exists('render_theme_modal') ? render_theme_modal() : '' ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>