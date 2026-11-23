<?php
/**
 * Router Utama Kios / POS
 * Otomatis mengarahkan ke Login jika belum masuk,
 * atau ke Admin / Kasir sesuai Role pengguna.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/auth.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

$user = current_user();

if (in_array($user['role'] ?? '', ['admin', 'superadmin'])) {
    header('Location: admin/index.php');
    exit;
} else {
    header('Location: qios/index.php');
    exit;
}
