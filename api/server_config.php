<?php
/**
 * API Pengaturan & Pengujian Server & Database Kios POS
 * Menghubungkan secara dinamis Web Server dan Database MySQL
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Muat konfigurasi database utama
require_once __DIR__ . '/../config/database.php';

// Helper input request
$raw_input = file_get_contents('php://input');
$json_input = !empty($raw_input) ? json_decode($raw_input, true) : null;
$request_data = is_array($json_input) ? array_merge($_GET, $_POST, $json_input) : array_merge($_GET, $_POST);

$action = strtolower(trim($request_data['action'] ?? 'get'));

// Helper normalisasi URL & Host
function parse_server_target($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') {
        $raw = '127.0.0.1';
    }

    if (!preg_match('~^https?://~i', $raw)) {
        $raw = 'http://' . $raw;
    }

    $parsed = parse_url($raw);
    $scheme = !empty($parsed['scheme']) ? strtolower($parsed['scheme']) : 'http';
    $host = !empty($parsed['host']) ? strtolower($parsed['host']) : '127.0.0.1';
    $port = !empty($parsed['port']) ? (int)$parsed['port'] : ($scheme === 'https' ? 443 : 80);
    $path = !empty($parsed['path']) ? trim($parsed['path'], '/') : '';

    if ($path === '' || $path === '/') {
        $full_url = "{$scheme}://{$host}" . ($port !== 80 && $port !== 443 ? ":{$port}" : "") . "/qios/";
    } else {
        $full_url = "{$scheme}://{$host}" . ($port !== 80 && $port !== 443 ? ":{$port}" : "") . "/{$path}/";
    }

    $db_host = ($host === 'localhost' || $host === '::1') ? '127.0.0.1' : $host;

    return [
        'full_url' => $full_url,
        'scheme'   => $scheme,
        'host'     => $host,
        'port'     => $port,
        'db_host'  => $db_host,
    ];
}

// -------------------------------------------------------------
// ACTION: GET (Ambil konfigurasi server dan status saat ini)
// -------------------------------------------------------------
if ($action === 'get') {
    $ini_path = __DIR__ . '/../config.ini';
    $ini = file_exists($ini_path) ? @parse_ini_file($ini_path, true) : [];

    $current_host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $active_db = $GLOBALS['active_db_host'] ?? ($db_host ?? '127.0.0.1');

    echo json_encode([
        'success' => true,
        'data' => [
            'web_host'    => $current_host,
            'server_url'  => $ini['SERVER']['server_url'] ?? ("http://" . $current_host . "/qios/"),
            'db_host'     => $active_db,
            'db_port'     => 3306,
            'db_name'     => 'kios_db',
            'is_online'   => true
        ]
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// -------------------------------------------------------------
// ACTION: TEST (Uji koneksi Web Server & Database MySQL)
// -------------------------------------------------------------
if ($action === 'test') {
    $target_raw = $request_data['target'] ?? ($request_data['server_url'] ?? ($request_data['ip'] ?? ''));
    $parsed = parse_server_target($target_raw);

    $web_result = [
        'ok'      => false,
        'url'     => $parsed['full_url'],
        'message' => 'Tidak dapat terhubung ke web server'
    ];

    $db_result = [
        'ok'      => false,
        'host'    => $parsed['db_host'],
        'port'    => 3306,
        'message' => 'Tidak dapat terhubung ke database MySQL'
    ];

    // 1. Uji Web Server HTTP
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 2.5,
            'header'  => "User-Agent: QiOS-Ping/1.0\r\n"
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ]
    ]);

    $web_test_url = rtrim($parsed['full_url'], '/') . '/login.php';
    $fp = @file_get_contents($web_test_url, false, $ctx, 0, 500);

    if ($fp !== false || (!empty($http_response_header) && preg_match('~HTTP/\d\.\d\s+(\d+)~i', $http_response_header[0] ?? ''))) {
        $web_result['ok'] = true;
        $web_result['message'] = 'Web server aktif dan merespon normal.';
    } else {
        // Coba ping root URL
        $fp_root = @file_get_contents($parsed['full_url'], false, $ctx, 0, 500);
        if ($fp_root !== false) {
            $web_result['ok'] = true;
            $web_result['message'] = 'Web server aktif di alamat ' . $parsed['full_url'];
        } else {
            $web_result['message'] = 'Web server tidak merespon pada port ' . $parsed['port'] . '. Pastikan server aktif dan terhubung LAN/WiFi.';
        }
    }

    // 2. Uji Database Server MySQL
    // Coba target DB host, dengan fallback password pastijuara1 dan ''
    $test_passwords = ['pastijuara1', ''];
    $db_connected = false;
    $db_err_msg = '';

    // Jika target adalah localhost atau IP yang sama dengan server saat ini
    $test_hosts = array_values(array_unique([$parsed['db_host'], '127.0.0.1']));

    foreach ($test_hosts as $thost) {
        foreach ($test_passwords as $tpass) {
            try {
                $test_dsn = "mysql:host={$thost};port=3306;dbname=kios_db;charset=utf8mb4";
                $t_pdo = new PDO($test_dsn, 'root', $tpass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 2
                ]);
                $db_connected = true;
                $db_result['ok'] = true;
                $db_result['host'] = $thost;
                $db_result['message'] = "Koneksi database MySQL 'kios_db' di {$thost}:3306 berhasil terhubung!";
                break 2;
            } catch (PDOException $pex) {
                $db_err_msg = $pex->getMessage();
            }
        }
    }

    if (!$db_connected) {
        $db_result['message'] = "MySQL gagal terhubung ({$parsed['db_host']}:3306): " . $db_err_msg;
    }

    $all_ok = $web_result['ok'] && $db_result['ok'];

    echo json_encode([
        'success'  => $all_ok,
        'web'      => $web_result,
        'database' => $db_result
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// -------------------------------------------------------------
// ACTION: SAVE (Simpan konfigurasi ke config.ini di semua platform)
// -------------------------------------------------------------
if ($action === 'save') {
    $target_raw = $request_data['target'] ?? ($request_data['server_url'] ?? ($request_data['ip'] ?? ''));
    if (empty($target_raw)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Alamat IP / URL server tidak boleh kosong.']);
        exit;
    }

    $parsed = parse_server_target($target_raw);
    $target_url = $parsed['full_url'];
    $db_host_target = $parsed['db_host'];

    // Format konten config.ini lengkap (SERVER & DATABASE)
    $ini_content = "[SERVER]\n";
    $ini_content .= "server_url={$target_url}\n\n";
    $ini_content .= "[DATABASE]\n";
    $ini_content .= "db_host={$db_host_target}\n";
    $ini_content .= "db_port=3306\n";
    $ini_content .= "db_user=root\n";
    $ini_content .= "db_password=pastijuara1\n";
    $ini_content .= "db_name=kios_db\n";

    // Daftar lokasi config.ini yang perlu disinkronkan
    $paths_to_update = [
        __DIR__ . '/../config.ini',
        __DIR__ . '/../desktop_go/config.ini',
        __DIR__ . '/../dist/QiOS-Cendekia-Portable/config.ini',
        __DIR__ . '/../dist/QiOS-Native-POS-Golang/config.ini',
    ];

    $saved_count = 0;
    foreach ($paths_to_update as $p) {
        $dir = dirname($p);
        if (is_dir($dir)) {
            if (@file_put_contents($p, $ini_content) !== false) {
                $saved_count++;
            }
        }
    }

    echo json_encode([
        'success'      => true,
        'message'      => 'Pengaturan IP Web Server dan Database Server berhasil disimpan dan disinkronkan!',
        'server_url'   => $target_url,
        'db_host'      => $db_host_target,
        'redirect_url' => rtrim($target_url, '/') . '/login.php',
        'saved_files'  => $saved_count
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Default response
echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali. Gunakan action=get, test, atau save.']);
exit;
