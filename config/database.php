<?php
/**
 * Konfigurasi Koneksi Database Kios
 * Host Server: 172.16.5.11
 * User: root
 * Password: pastijuara1
 * Database: kios_db
 */

// Pengaturan Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Sembunyikan output raw error ke client untuk keamanan

// Baca file konfigurasi config.ini di root jika tersedia
$ini_file = __DIR__ . '/../config.ini';
$ini_data = [];
if (file_exists($ini_file)) {
    $parsed_ini = @parse_ini_file($ini_file, true);
    if (is_array($parsed_ini)) {
        $ini_data = $parsed_ini;
    }
}

// 1. Deteksi host web server secara dinamis
$current_web_host = '127.0.0.1';
if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
    $current_web_host = strtolower(explode(':', $_SERVER['HTTP_HOST'])[0]);
} elseif (isset($_SERVER['SERVER_ADDR']) && !empty($_SERVER['SERVER_ADDR'])) {
    $current_web_host = strtolower($_SERVER['SERVER_ADDR']);
} elseif (!empty($ini_data['SERVER']['server_url'])) {
    $parsed_url = parse_url($ini_data['SERVER']['server_url']);
    if (!empty($parsed_url['host'])) {
        $current_web_host = strtolower($parsed_url['host']);
    }
}

$is_local_request = in_array($current_web_host, ['localhost', '127.0.0.1', 'kiosk.test', '::1']);

// 2. Tentukan Host Database secara Dinamis mengikuti Host Server
// Jika user mengubah IP server (misal ke 192.168.1.100 atau 172.16.5.11), DB host otomatis mengikuti!
$dynamic_db_host = $current_web_host;
if ($is_local_request) {
    $dynamic_db_host = '127.0.0.1';
}

// Cek jika ada konfigurasi khusus di config.ini
if (!empty($ini_data['DATABASE']['db_host'])) {
    $dynamic_db_host = trim($ini_data['DATABASE']['db_host']);
}

$db_host = getenv('DB_HOST') ? getenv('DB_HOST') : $dynamic_db_host;
$db_user = getenv('DB_USER') ? getenv('DB_USER') : ($ini_data['DATABASE']['db_user'] ?? 'root');
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($ini_data['DATABASE']['db_password'] ?? ($ini_data['DATABASE']['db_pass'] ?? ($is_local_request ? '' : 'pastijuara1')));
$db_name = getenv('DB_NAME') ? getenv('DB_NAME') : ($ini_data['DATABASE']['db_name'] ?? 'kios_db');
$db_port = getenv('DB_PORT') ? getenv('DB_PORT') : ($ini_data['DATABASE']['db_port'] ?? '3306');
$db_charset = 'utf8mb4';

// 3. Bangun daftar kombinasi koneksi (Fallback Candidate)
// Mencoba host target dinamis, 127.0.0.1 (jika webserver & MySQL di mesin yang sama), dan password alternatif
$candidate_hosts = array_values(array_unique([$db_host, '127.0.0.1', 'localhost']));
$candidate_passwords = array_values(array_unique([$db_pass, 'pastijuara1', '']));

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 2, // 2 detik timeout per percobaan
];

$pdo = null;
$connected_host = null;
$connection_error = null;

foreach ($candidate_hosts as $try_host) {
    if (!$try_host) continue;
    foreach ($candidate_passwords as $try_pass) {
        try {
            $dsn = "mysql:host={$try_host};port={$db_port};dbname={$db_name};charset={$db_charset}";
            $pdo = new PDO($dsn, $db_user, $try_pass, $options);
            $connected_host = $try_host;
            $db_host = $try_host;
            $db_pass = $try_pass;
            $GLOBALS['active_db_host'] = $try_host;
            break 2;
        } catch (PDOException $ex) {
            $connection_error = $ex;
        }
    }
}

if (!$pdo) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Koneksi Database Gagal - Kios Digital</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background: #0b1120; color: #f1f5f9; font-family: 'Segoe UI', system-ui, sans-serif; }
            .error-card { max-width: 650px; margin: 60px auto; background: #1e293b; border: 1px solid #334155; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.4); color: #f1f5f9; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card error-card p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="badge bg-danger p-3 rounded-circle mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="currentColor" class="bi bi-exclamation-triangle" viewBox="0 0 16 16">
                            <path d="M7.938 2.016A.13.13 0 0 1 8.002 2a.13.13 0 0 1 .063.016.15.15 0 0 1 .054.057l6.857 11.667c.036.06.035.124.002.183a.2.2 0 0 1-.054.06.1.1 0 0 1-.066.017H1.146a.1.1 0 0 1-.066-.017.2.2 0 0 1-.054-.06.18.18 0 0 1 .002-.183L7.884 2.073a.15.15 0 0 1 .054-.057m1.044-.45a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767z"/>
                            <path d="M7.002 12a1 1 0 1 1 2 0 1 1 0 0 1-2 0M7.1 5.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0z"/>
                        </svg>
                    </div>
                    <h3 class="fw-bold text-danger">Koneksi Database Gagal</h3>
                    <p class="text-secondary">Aplikasi tidak dapat terhubung ke database server MySQL pada host yang diminta.</p>
                </div>

                <div class="alert alert-danger text-break py-2 small">
                    <strong>Pesan Sistem:</strong><br>
                    <?= htmlspecialchars($connection_error ? $connection_error->getMessage() : 'Gagal menghubungkan ke database server.', ENT_QUOTES, 'UTF-8') ?>
                </div>

                <div class="p-3 rounded mb-3" style="background: #0f172a; border: 1px solid #334155;">
                    <h6 class="fw-bold text-light mb-2">Konfigurasi yang Dicoba:</h6>
                    <ul class="list-unstyled mb-0 small text-secondary">
                        <li><strong>Host Web Server:</strong> <?= htmlspecialchars($current_web_host, ENT_QUOTES, 'UTF-8') ?></li>
                        <li><strong>Host DB Target:</strong> <?= htmlspecialchars($dynamic_db_host, ENT_QUOTES, 'UTF-8') ?>:<?= htmlspecialchars($db_port, ENT_QUOTES, 'UTF-8') ?></li>
                        <li><strong>Candidate DB Hosts:</strong> <?= htmlspecialchars(implode(', ', $candidate_hosts), ENT_QUOTES, 'UTF-8') ?></li>
                        <li><strong>Database:</strong> <?= htmlspecialchars($db_name, ENT_QUOTES, 'UTF-8') ?> (User: <?= htmlspecialchars($db_user, ENT_QUOTES, 'UTF-8') ?>)</li>
                    </ul>
                </div>

                <div class="card-footer bg-transparent border-0 px-0">
                    <h6 class="fw-bold text-light">Langkah Pemeriksaan & Solusi:</h6>
                    <ol class="small text-secondary ps-3 mb-4">
                        <li>Pastikan service MySQL / MariaDB di server <strong><?= htmlspecialchars($dynamic_db_host, ENT_QUOTES, 'UTF-8') ?></strong> sudah aktif.</li>
                        <li>Jika menggunakan IP LAN/WiFi, pastikan MySQL mengizinkan koneksi remote (<code>bind-address = 0.0.0.0</code>) dan user <code>root</code> memiliki akses.</li>
                        <li>Pastikan database <strong>kios_db</strong> sudah dibuat dan file <code>database.sql</code> telah diimport.</li>
                    </ol>
                    <div class="d-flex justify-content-center gap-2">
                        <button onclick="location.reload();" class="btn btn-primary px-4">Coba Lagi</button>
                        <a href="login.php" class="btn btn-outline-secondary px-3">Kembali ke Login</a>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 4. Auto-Migrate / Self-Healing Database Schema
// Mencegah error 'Table kios_db.satuan doesn't exist' dan memastikan kolom-kolom baru selalu tersedia
auto_migrate_schema($pdo);

function auto_migrate_schema(PDO $pdo) {
    static $already_run = false;
    if ($already_run) return;
    $already_run = true;

    try {
        // 1. Pastikan tabel satuan ada
        $pdo->exec("CREATE TABLE IF NOT EXISTS `satuan` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `nama_satuan` VARCHAR(100) NOT NULL UNIQUE,
            `kode_satuan` VARCHAR(20) NOT NULL,
            `deskripsi` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed data satuan default jika kosong
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `satuan`")->fetchColumn();
        if ($cnt === 0) {
            $pdo->exec("INSERT INTO `satuan` (`id`, `nama_satuan`, `kode_satuan`, `deskripsi`) VALUES
                (1, 'Pieces', 'Pcs', 'Satuan umum per satuan/biji'),
                (2, 'Botol', 'Btl', 'Kemasan botol cairan/minuman'),
                (3, 'Bungkus', 'Bks', 'Kemasan sachet/bungkus/kantong'),
                (4, 'Dus / Karton', 'Dus', 'Kemasan kardus atau karton besar'),
                (5, 'Pak / Pack', 'Pak', 'Kemasan paket atau pack isi beberapa item'),
                (6, 'Porsi / Cup', 'Cup', 'Porsi sajian makanan atau minuman langsung santap'),
                (7, 'Kilogram', 'Kg', 'Satuan berat kilogram')");
        }

        // 2. Pastikan kolom satuan_id ada di tabel produk
        $hasSatuanId = false;
        $cols = $pdo->query("SHOW COLUMNS FROM `produk` LIKE 'satuan_id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `produk` ADD COLUMN `satuan_id` INT NULL AFTER `kategori_id`");
        }

        // 3. Pastikan kolom password_plain ada di tabel users
        $uCols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'password_plain'")->fetchAll();
        if (empty($uCols)) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `password_plain` VARCHAR(255) NULL AFTER `password`");
        }

        // 4. Pastikan kolom role di tabel users mendukung ENUM('superadmin', 'admin', 'kasir', 'user')
        $roleCol = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'role'")->fetch();
        if ($roleCol && strpos($roleCol['Type'], 'user') === false) {
            $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('superadmin', 'admin', 'kasir', 'user') NOT NULL DEFAULT 'kasir'");
        }
    } catch (Throwable $t) {
        // Abaikan error minor jika izin terbatas agar tidak menghentikan aplikasi
    }
}

// Inisialisasi Session jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Helper Fungsi Sanitasi Output (Mencegah XSS)
 */
function e($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Helper Format Angka ke Rupiah
 */
function rupiah($angka) {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

/**
 * Helper Mendapatkan Nilai Pengaturan Sistem dari Database
 */
function get_setting($key, $default = '') {
    global $pdo;
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!isset($pdo) || !$pdo) {
        return $default;
    }
    try {
        $stmt = $pdo->prepare("SELECT nilai FROM pengaturan WHERE kunci = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row && $row['nilai'] !== null && $row['nilai'] !== '') {
            $cache[$key] = (string)$row['nilai'];
            return $cache[$key];
        }
    } catch (PDOException $e) {
        // Fallback default jika tabel belum siap
    }
    $cache[$key] = $default;
    return $default;
}

/**
 * Helper Menyimpan / Memperbarui Nilai Pengaturan Sistem
 */
function set_setting($key, $val, $keterangan = '') {
    global $pdo;
    if (!isset($pdo) || !$pdo) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("
            INSERT INTO pengaturan (kunci, nilai, keterangan)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE nilai = VALUES(nilai), keterangan = VALUES(keterangan)
        ");
        return $stmt->execute([$key, (string)$val, $keterangan]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Helper Mendapatkan Nama Aplikasi Aktif
 */
function get_app_name() {
    return get_setting('nama_aplikasi', 'QiOS Cendekia');
}

/**
 * Helper Mendapatkan Path Relatif Logo Aplikasi Aktif (dari root project)
 */
function get_app_logo_path() {
    $logo_file = get_setting('logo_aplikasi', 'assets/images/logo.png');
    $abs_path  = __DIR__ . '/../' . $logo_file;
    if (!file_exists($abs_path)) {
        $logo_file = 'assets/images/logo.png';
    }
    return $logo_file;
}

/**
 * Helper Mendapatkan URL / Src Logo Lengkap dengan Cache-Buster (?v=)
 */
function get_app_logo($prefix = '') {
    $logo_file = get_app_logo_path();
    $abs_path  = __DIR__ . '/../' . $logo_file;
    $v = file_exists($abs_path) ? filemtime($abs_path) : time();
    return $prefix . $logo_file . '?v=' . $v;
}

// Muat engine tema sistem
require_once __DIR__ . '/theme.php';

