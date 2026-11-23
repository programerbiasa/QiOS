<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/auth.php';
require_admin();

$admin_user = current_user();
$logo_rel_root = function_exists('get_app_logo_path') ? get_app_logo_path() : 'assets/images/logo.png';
$logo_v = file_exists(__DIR__ . '/../../' . $logo_rel_root) ? filemtime(__DIR__ . '/../../' . $logo_rel_root) : time();
$logo_url_admin = function_exists('get_app_logo') ? get_app_logo('../') : ('../' . $logo_rel_root . '?v=' . $logo_v);
$app_title = function_exists('get_app_name') ? get_app_name() : 'QiOS Cendekia';
$theme_info = function_exists('get_current_theme_data') ? get_current_theme_data() : ['mode' => 'dark'];
$theme_key  = function_exists('get_app_theme') ? get_app_theme() : 'midnight';
?>
<!DOCTYPE html>
<html lang="id" data-bs-theme="<?= $theme_info['mode'] ?>" data-theme="<?= e($theme_key) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($admin_title) ? e($admin_title) . ' - ' : '' ?><?= e($app_title) ?> - Admin Panel</title>
    <!-- Favicon -->
    <link rel="icon" href="<?= e($logo_url_admin) ?>">
    <!-- Bootstrap 5.3 CSS Dark -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-darker: #090d16;
            --bg-card: #111827;
            --bg-header: #1e293b;
            --border-color: #334155;
            --accent-cyan: #38bdf8;
            --accent-green: #10b981;
        }

        body {
            background-color: var(--bg-darker);
            color: #f1f5f9;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .admin-header {
            background-color: #0f172a;
            border-bottom: 1px solid var(--border-color);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35);
        }

        .admin-topbar {
            padding-top: 0.65rem;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid rgba(51, 65, 85, 0.45);
        }

        .admin-menubar {
            background: rgba(11, 17, 32, 0.9);
            padding-top: 0.35rem;
            padding-bottom: 0.35rem;
        }

        .admin-nav {
            overflow-x: auto;
            flex-wrap: nowrap;
            white-space: nowrap;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .admin-nav::-webkit-scrollbar {
            display: none;
        }

        .admin-nav .nav-link {
            color: #94a3b8;
            font-weight: 500;
            font-size: 0.88rem;
            padding: 0.45rem 0.85rem;
            border-radius: 8px;
            white-space: nowrap;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .admin-nav .nav-link:hover {
            color: #ffffff;
            background: rgba(56, 189, 248, 0.12);
            border-color: rgba(56, 189, 248, 0.25);
        }

        .admin-nav .nav-link.active {
            color: #ffffff;
            font-weight: 600;
            background: rgba(56, 189, 248, 0.2);
            border-color: rgba(56, 189, 248, 0.45);
            box-shadow: 0 2px 8px rgba(56, 189, 248, 0.15);
        }

        .card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
        }

        .stat-card {
            background: #111827;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 1.25rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: #475569;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
        }
    </style>
    <?= function_exists('render_theme_styles') ? render_theme_styles() : '' ?>
</head>
<body>

<!-- Admin Header: Baris 1 (Brand & Aksi Pojok), Baris 2 (Menu Navigasi) -->
<header class="admin-header sticky-top">
    <!-- Baris 1: Brand & Tombol Kasir/Logout di Pojok -->
    <div class="admin-topbar">
        <div class="container d-flex align-items-center justify-content-between gap-3">
            <a class="navbar-brand d-flex align-items-center gap-2 text-decoration-none text-white my-0 flex-grow-1" href="index.php" style="min-width: 0;">
                <img src="<?= e($logo_url_admin) ?>" alt="Logo" style="height: 38px; width: 38px; object-fit: contain;" class="rounded bg-dark p-1 flex-shrink-0">
                <div class="d-flex align-items-center gap-2 flex-wrap" style="min-width: 0;">
                    <span class="fw-bold text-white fs-5 lh-sm text-break"><?= e($app_title) ?></span>
                    <?php if (($admin_user['role'] ?? '') === 'superadmin'): ?>
                        <span class="badge align-middle flex-shrink-0 text-white shadow-sm" style="font-size: 0.65rem; background: linear-gradient(135deg, #7c3aed 0%, #db2777 100%);">Super Admin</span>
                    <?php else: ?>
                        <span class="badge bg-info text-dark align-middle flex-shrink-0" style="font-size: 0.65rem;">Admin</span>
                    <?php endif; ?>
                </div>
            </a>

            <!-- Tombol Aksi di Pojok Kanan -->
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <a href="../logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-semibold d-flex align-items-center gap-1 shadow-sm" title="Keluar / Logout dari Sistem" onclick="return confirm('Apakah Anda yakin ingin keluar dari sistem?');">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Baris 2: Menu Navigasi Utama di Bawah Brand -->
    <nav class="admin-menubar">
        <div class="container">
            <ul class="nav admin-nav gap-1 py-1">
                <li class="nav-item">
                    <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'index.php') ? 'active' : '' ?>" href="index.php">
                        <i class="bi bi-speedometer2 me-1"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'produk.php') ? 'active' : '' ?>" href="produk.php">
                        <i class="bi bi-box-seam me-1"></i> Barang
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'kategori.php') ? 'active' : '' ?>" href="kategori.php">
                        <i class="bi bi-tags me-1"></i> Kategori
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'satuan.php') ? 'active' : '' ?>" href="satuan.php">
                        <i class="bi bi-rulers me-1"></i> Satuan
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'users.php') ? 'active' : '' ?>" href="users.php">
                        <i class="bi bi-person-gear me-1"></i> Hak Akses
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'transaksi.php') ? 'active' : '' ?>" href="transaksi.php">
                        <i class="bi bi-receipt me-1"></i> Transaksi
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'backup.php') ? 'active' : '' ?>" href="backup.php">
                        <i class="bi bi-database-fill-gear me-1"></i> Backup & Restore
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) === 'pengaturan.php') ? 'active' : '' ?>" href="pengaturan.php">
                        <i class="bi bi-gear-fill me-1"></i> Pengaturan
                    </a>
                </li>
            </ul>
        </div>
    </nav>
</header>

<main class="py-4 flex-grow-1">
    <div class="container">
        <!-- Flash Message Handler -->
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?= e($_SESSION['flash_success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($_SESSION['flash_error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
