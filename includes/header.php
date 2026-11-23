<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';

// Hitung total item dalam keranjang belanja
$cart_total_items = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_total_items += (int)$item['jumlah'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? e($page_title) . ' - ' : '' ?>Kios Digital Cendekia</title>
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico">
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
    <?= function_exists('render_theme_styles') ? render_theme_styles() : '' ?>
</head>
<body>

<!-- Header / Navbar Kios -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-kios sticky-top py-2">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
            <img src="<?= e(function_exists('get_app_logo') ? get_app_logo() : 'assets/images/logo.png') ?>" alt="Logo Kios" class="brand-logo">
            <div>
                <div class="brand-text">KIOS CENDEKIA</div>
                <div class="brand-subtext">Belanja Cepat & Praktis</div>
            </div>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-2 mt-3 mt-lg-0">
                <li class="nav-nav-item">
                    <a class="nav-link text-white px-3" href="index.php">
                        <i class="bi bi-grid me-1"></i> Katalog Produk
                    </a>
                </li>
                <li class="nav-item">
                    <a class="btn btn-warning position-relative px-3 fw-semibold text-dark rounded-pill" href="cart.php">
                        <i class="bi bi-cart3 me-1"></i> Keranjang
                        <?php if ($cart_total_items > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-light">
                                <?= $cart_total_items ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item ms-lg-2">
                    <a class="btn btn-outline-light rounded-pill btn-sm px-3" href="admin/login.php">
                        <i class="bi bi-shield-lock me-1"></i> Login Admin
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<main class="py-4">
    <div class="container">
        <!-- Flash Messages -->
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?= e($_SESSION['flash_success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($_SESSION['flash_error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
