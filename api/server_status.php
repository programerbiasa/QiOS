<?php
/**
 * API Status Server Real-Time
 * Memberikan informasi alamat IP Server, Status Database MySQL, Port, dan Uptime secara akurat & live
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

// 1. Deteksi IP Server Asli (Internal / LAN / Tailscale)
$server_ip = $_SERVER['SERVER_ADDR'] ?? ($_SERVER['LOCAL_ADDR'] ?? '127.0.0.1');

// Jika dijalankan di Linux (Raspberry Pi), ambil IP aktif dari interface jaringan
if (PHP_OS_FAMILY === 'Linux' && (!filter_var($server_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || $server_ip === '127.0.0.1')) {
    $ip_cmd = shell_exec("hostname -I 2>/dev/null");
    if ($ip_cmd) {
        $ips = preg_split('/\s+/', trim($ip_cmd));
        if (!empty($ips[0])) {
            $server_ip = $ips[0];
        }
    }
}

// 2. Deteksi Host Web Server yang sedang diakses browser
$web_host = $_SERVER['HTTP_HOST'] ?? $server_ip;

// 3. Status Database Real-Time
$db_online = false;
$active_host = $GLOBALS['active_db_host'] ?? ($connected_host ?? ($db_host ?? '127.0.0.1'));
$active_port = $db_port ?? 3306;

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $pdo->query("SELECT 1");
        $db_online = true;
    } catch (Exception $e) {
        $db_online = false;
    }
}

// 4. Deteksi beban sistem & memori jika di Linux
$load = null;
if (function_exists('sys_getloadavg')) {
    $la = sys_getloadavg();
    if (is_array($la) && isset($la[0])) {
        $load = round($la[0], 2);
    }
}

echo json_encode([
    'status'       => 'success',
    'timestamp'    => time(),
    'web_host'     => $web_host,
    'server_ip'    => $server_ip,
    'db'           => [
        'online' => $db_online,
        'host'   => $active_host,
        'port'   => $active_port
    ],
    'server_time'  => date('H:i:s'),
    'system_load'  => $load
]);
