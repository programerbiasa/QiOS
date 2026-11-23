<?php
/**
 * Helper Otentikasi dan Manajemen Sesi Multi-Role (Admin & Kasir)
 */
require_once __DIR__ . '/database.php';

/**
 * Cek apakah user sedang login
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Dapatkan data user saat ini
 */
function current_user() {
    if (is_logged_in()) {
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? '',
            'nama_lengkap' => $_SESSION['nama_lengkap'] ?? 'Pengguna',
            'role' => $_SESSION['role'] ?? 'kasir'
        ];
    }
    return null;
}

/**
 * Wajib login
 */
function require_login() {
    if (!is_logged_in()) {
        $_SESSION['flash_error'] = 'Silakan login terlebih dahulu untuk mengakses sistem.';
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Cek apakah user memiliki hak akses Superadmin
 */
function is_superadmin() {
    return is_logged_in() && (($_SESSION['role'] ?? '') === 'superadmin');
}

/**
 * Cek apakah user memiliki hak akses Admin atau Superadmin
 */
function is_admin() {
    return is_logged_in() && in_array($_SESSION['role'] ?? '', ['admin', 'superadmin']);
}

/**
 * Wajib Role Admin atau Superadmin
 */
function require_admin() {
    require_login();
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'])) {
        $_SESSION['flash_error'] = 'Akses ditolak! Menu ini hanya untuk Administrator.';
        header('Location: ../qios/index.php');
        exit;
    }
}

/**
 * Wajib Role Superadmin Khusus
 */
function require_superadmin() {
    require_login();
    if (!is_superadmin()) {
        $_SESSION['flash_error'] = 'Akses ditolak! Menu ini khusus untuk Super Administrator.';
        header('Location: ../admin/index.php');
        exit;
    }
}

/**
 * Wajib Role Kasir, Admin, atau Superadmin
 */
function require_kasir_or_admin() {
    require_login();
    // Superadmin, Admin, User, dan Kasir berhak mengakses kasir
    if (!in_array($_SESSION['role'] ?? '', ['kasir', 'user', 'admin', 'superadmin'])) {
        $_SESSION['flash_error'] = 'Akses ditolak!';
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Generate CSRF Token untuk proteksi form
 */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validasi CSRF Token
 */
function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
        return false;
    }
    return true;
}
