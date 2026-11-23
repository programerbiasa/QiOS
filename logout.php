<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? null;
if ($user_id) {
    try {
        require_once __DIR__ . '/config/database.php';
        if (isset($pdo) && $pdo) {
            $stmt_logout = $pdo->prepare("
                UPDATE users 
                SET active_session_token = NULL,
                    active_device_name = NULL,
                    last_active_at = NULL
                WHERE id = ?
            ");
            $stmt_logout->execute([(int)$user_id]);
        }
    } catch (Exception $e) {
        // Abaikan jika database tidak dapat dijangkau saat logout
    }
}

// Hapus seluruh session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

session_start();
$_SESSION['flash_success'] = 'Anda telah berhasil keluar dari sistem.';
header('Location: login.php');
exit;
