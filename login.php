<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

// Jika sudah login, langsung alihkan sesuai role
if (is_logged_in()) {
    $u = current_user();
    if (in_array($u['role'] ?? '', ['admin', 'superadmin'])) {
        header('Location: admin/index.php');
    } else {
        header('Location: qios/index.php');
    }
    exit;
}

$error_login = '';
$username_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username_input = trim($_POST['username'] ?? '');
    $password_input = $_POST['password'] ?? '';

    if ($username_input === '' || $password_input === '') {
        $error_login = 'Username dan password wajib diisi!';
    } else {
        try {
            // Prepared statement query ke tabel users
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username_input]);
            $user = $stmt->fetch();

            $is_valid = false;
            if ($user) {
                if (password_verify($password_input, $user['password'])) {
                    $is_valid = true;
                } elseif ($password_input === $user['password']) {
                    // Fallback jika belum di-hash, lalu otomatis re-hash
                    $is_valid = true;
                    $new_hash = password_hash($password_input, PASSWORD_BCRYPT);
                    $stmt_rehash = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt_rehash->execute([$new_hash, $user['id']]);
                }
            }

            if ($is_valid) {
                session_regenerate_id(true);

                $session_token = bin2hex(random_bytes(24));

                $_SESSION['user_id']       = $user['id'];
                $_SESSION['username']      = $user['username'];
                $_SESSION['nama_lengkap']  = $user['nama_lengkap'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['session_token'] = $session_token;

                // Pastikan password_plain tersimpan untuk kebutuhan Superadmin
                if (empty($user['password_plain']) || $user['password_plain'] !== $password_input) {
                    try {
                        $stmt_pp = $pdo->prepare("UPDATE users SET password_plain = ? WHERE id = ?");
                        $stmt_pp->execute([$password_input, $user['id']]);
                    } catch (Exception $e) {}
                }

                // Catat sesi login aktif di database
                try {
                    $dev_name = 'Browser Web ' . ($user['role'] === 'superadmin' ? 'Super Admin' : ($user['role'] === 'admin' ? 'Admin' : 'User'));
                    $stmt_active = $pdo->prepare("
                        UPDATE users 
                        SET active_session_token = ?, 
                            active_device_name = ?, 
                            last_active_at = NOW(), 
                            last_seen = NOW() 
                        WHERE id = ?
                    ");
                    $stmt_active->execute([$session_token, $dev_name, $user['id']]);
                } catch (Exception $e) {
                    // Abaikan jika ada issue non-kritis
                }

                $_SESSION['flash_success'] = "Selamat datang, " . $user['nama_lengkap'] . " (" . strtoupper($user['role']) . ")!";

                if (in_array($user['role'], ['admin', 'superadmin'])) {
                    header('Location: admin/index.php');
                } else {
                    header('Location: qios/index.php');
                }
                exit;
            } else {
                $error_login = 'Username atau Password tidak sesuai!';
            }
        } catch (PDOException $e) {
            $error_login = 'Database Error: ' . $e->getMessage();
        }
    }
}
$theme_info = function_exists('get_current_theme_data') ? get_current_theme_data() : ['mode' => 'dark'];
$theme_key  = function_exists('get_app_theme') ? get_app_theme() : 'midnight';
?>
<!DOCTYPE html>
<html lang="id" data-bs-theme="<?= $theme_info['mode'] ?>" data-theme="<?= e($theme_key) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= e(get_app_name()) ?></title>
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico">
    <!-- Bootstrap 5.3 CSS Dark -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background: radial-gradient(circle at top right, var(--bg-header), var(--bg-darker)) !important;
            background-color: var(--bg-darker) !important;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--text-main) !important;
            padding: 20px;
        }
        .login-card {
            background-color: var(--bg-card) !important;
            border: 1px solid var(--border-color) !important;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
            max-width: 450px;
            width: 100%;
            overflow: hidden;
        }
        .login-header {
            background-color: var(--bg-header) !important;
            padding: 28px 24px 20px;
            text-align: center;
            border-bottom: 1px solid var(--border-color) !important;
        }
        .login-logo {
            width: 68px;
            height: 68px;
            object-fit: contain;
            margin-bottom: 12px;
            filter: drop-shadow(0 4px 10px rgba(0,0,0,0.5));
        }
        .form-control {
            background-color: var(--bg-secondary) !important;
            border: 1px solid var(--border-color) !important;
            color: var(--text-main) !important;
        }
        .form-control:focus {
            background-color: var(--bg-secondary) !important;
            border-color: var(--accent-primary) !important;
            color: var(--text-main) !important;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.25);
        }
        .input-group-text {
            background-color: var(--bg-header) !important;
            border: 1px solid var(--border-color) !important;
            color: var(--text-muted) !important;
        }
    </style>
    <?= function_exists('render_theme_styles') ? render_theme_styles() : '' ?>
</head>
<body>

<div class="login-card position-relative">
    <!-- Tombol Pengaturan IP Server -->
    <button type="button" class="btn btn-outline-secondary btn-sm rounded-circle position-absolute top-0 end-0 m-3 z-3 shadow" 
            style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: var(--bg-secondary) !important; border: 1px solid var(--border-color) !important;" 
            data-bs-toggle="modal" data-bs-target="#modalServerSetting" title="Pengaturan IP Server & Jaringan">
        <i class="bi bi-gear-fill text-info fs-5"></i>
    </button>

    <div class="login-header">
        <img src="<?= e(function_exists('get_app_logo') ? get_app_logo() : 'assets/images/logo.png') ?>" alt="Logo Kios" class="login-logo">
        <h4 class="fw-bold mb-1 text-white"><?= strtoupper(e(get_app_name())) ?></h4>
        <p class="text-secondary small mb-0">Masuk untuk mengakses aplikasi Kasir POS atau Panel Admin</p>
    </div>

    <div class="p-4">
        <!-- Notifikasi Flash -->
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success py-2 small mb-3">
                <i class="bi bi-check-circle me-1"></i> <?= e($_SESSION['flash_success']) ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-warning py-2 small mb-3">
                <i class="bi bi-exclamation-triangle me-1"></i> <?= e($_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <?php if (!empty($error_login)): ?>
            <div class="alert alert-danger py-2 small mb-3">
                <i class="bi bi-x-circle me-1"></i> <?= e($error_login) ?>
            </div>
        <?php endif; ?>

        <!-- Form Login -->
        <form action="login.php" method="POST" autocomplete="off">
            <div class="mb-3">
                <label for="username" class="form-label small fw-bold">Username</label>
                <div class="input-group">
                    <span class="input-group-text border-end-0"><i class="bi bi-person-fill text-primary"></i></span>
                    <input type="text" class="form-control border-start-0 ps-2" id="username" name="username" placeholder="Masukkan username" value="<?= e($username_input) ?>" required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label small fw-bold">Password</label>
                <div class="input-group">
                    <span class="input-group-text border-end-0"><i class="bi bi-key-fill text-primary"></i></span>
                    <input type="password" class="form-control border-start-0 border-end-0 ps-2" id="password" name="password" placeholder="Masukkan password" required>
                    <button class="btn btn-outline-secondary border-start-0 px-3" type="button" onclick="toggleLoginPassword(this)" title="Lihat/Sembunyikan Password">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 rounded-3 fw-bold shadow-sm mb-3">
                <i class="bi bi-box-arrow-in-right me-1"></i> Masuk Sekarang
            </button>
        </form>

        <!-- Footer Keamanan & Identitas Aplikasi -->
        <div class="text-center text-secondary small mt-4 pt-3 border-top border-secondary border-opacity-25">
            <div class="d-flex justify-content-center align-items-center gap-2 mb-1">
                <i class="bi bi-shield-check text-success"></i>
                <span class="fw-semibold">Sistem Kasir & Kiosk Digital</span>
            </div>
            <div class="text-muted" style="font-size: 0.75rem;">
                &copy; <?= date('Y') ?> QiOS Cendekia. Terlindungi Enkripsi Sesi.
            </div>
        </div>
    </div>
</div>

<!-- Modal Pengaturan IP Server -->
<div class="modal fade" id="modalServerSetting" tabindex="-1" aria-labelledby="modalServerSettingLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-start shadow-lg rounded-4">
            <div class="modal-header pb-3">
                <h5 class="modal-title fw-bold d-flex align-items-center" id="modalServerSettingLabel">
                    <i class="bi bi-gear-fill text-primary me-2"></i> Pengaturan IP Server & Database
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-secondary small mb-3">
                    Atur alamat IP Server Kios/POS. Ketika alamat server diubah, alamat <strong>Web App</strong> dan <strong>Database MySQL</strong> akan otomatis tersinkronisasi dan disimpan permanen ke <code>config.ini</code>.
                </p>

                <!-- Status Server & Database Saat Ini -->
                <div class="mb-3">
                    <label class="form-label small fw-bold mb-1">Status Koneksi Saat Ini:</label>
                    <div class="status-info-box p-3 rounded-3 mb-2 font-monospace small d-flex justify-content-between align-items-center">
                        <span class="d-flex align-items-center">
                            <i class="bi bi-globe me-2 text-primary fs-6"></i>
                            <span class="me-1">Web Server:</span>
                            <strong><?= e($_SERVER['HTTP_HOST'] ?? '127.0.0.1') ?></strong>
                        </span>
                        <span class="badge badge-status-active px-3 py-1 rounded-pill">Aktif</span>
                    </div>
                    <div class="status-info-box p-3 rounded-3 font-monospace small d-flex justify-content-between align-items-center">
                        <span class="d-flex align-items-center">
                            <i class="bi bi-database me-2 text-warning fs-6"></i>
                            <span class="me-1">Database:</span>
                            <strong><?= e($GLOBALS['active_db_host'] ?? ($db_host ?? '127.0.0.1')) ?>:3306</strong>
                        </span>
                        <span class="badge badge-status-dynamic px-3 py-1 rounded-pill">Dinamis</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="inputModalIp" class="form-label small fw-bold">Alamat IP / Domain Server Baru:</label>
                    <div class="input-group">
                        <select class="form-select" id="selectModalProto" style="max-width: 105px;">
                            <option value="http://">http://</option>
                            <option value="https://">https://</option>
                        </select>
                        <input type="text" class="form-control font-monospace" id="inputModalIp" 
                               placeholder="Contoh: 172.16.5.11 atau localhost" 
                               value="<?= e(explode(':', $_SERVER['HTTP_HOST'] ?? '127.0.0.1')[0]) ?>">
                    </div>
                    <div class="form-text text-secondary small mt-1">
                        <i class="bi bi-arrow-repeat text-primary me-1"></i>Database server akan otomatis mengikuti alamat IP server ini. Pastikan laptop kasir dan server dalam satu jaringan WiFi/LAN yang sama.
                    </div>
                </div>

                <!-- Box Hasil Pengujian / Status -->
                <div id="loginTestStatusBox" class="d-none mb-3"></div>
            </div>
            <div class="modal-footer pt-3 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-primary btn-sm px-3 rounded-pill fw-semibold" onclick="testModalServerConnection()">
                    <span id="loginTestSpinner" class="spinner-border spinner-border-sm me-1 d-none" role="status"></span>
                    <i id="loginTestIcon" class="bi bi-broadcast me-1"></i> Tes Koneksi
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-primary btn-sm px-3 fw-bold rounded-pill shadow-sm" onclick="saveAndApplyModalIp()">
                        <i class="bi bi-check-circle-fill me-1"></i> Simpan & Hubungkan
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function getModalTargetUrl() {
    let proto = document.getElementById('selectModalProto').value;
    let ip = document.getElementById('inputModalIp').value.trim();
    ip = ip.replace(/^https?:\/\//, '').replace(/\/+$/, '');
    if (!ip) ip = '127.0.0.1';
    return proto + ip + '/';
}

function getModalTargetIp() {
    let ip = document.getElementById('inputModalIp').value.trim();
    ip = ip.replace(/^https?:\/\//, '').replace(/\/+$/, '');
    return ip.split(':')[0] || '127.0.0.1';
}

async function testModalServerConnection() {
    const targetUrl = getModalTargetUrl();
    const targetIp = getModalTargetIp();
    const box = document.getElementById('loginTestStatusBox');
    const spinner = document.getElementById('loginTestSpinner');
    const icon = document.getElementById('loginTestIcon');

    spinner.classList.remove('d-none');
    icon.classList.add('d-none');
    box.className = 'alert alert-info py-2 small mb-3';
    box.classList.remove('d-none');
    box.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Menguji koneksi Web Server & Database MySQL ke <code>' + targetIp + '</code>...';

    try {
        // Coba pengujian komprehensif melalui backend API
        const testApiUrl = 'api/server_config.php?action=test&target=' + encodeURIComponent(targetUrl);
        const resp = await fetch(testApiUrl);
        const data = await resp.json();

        if (data && (data.web || data.database)) {
            const webOk = data.web && data.web.ok;
            const dbOk = data.database && data.database.ok;

            let html = '<div class="fw-bold mb-2"><i class="bi bi-clipboard-pulse me-1 text-primary"></i> Hasil Diagnosa Koneksi:</div>';
            
            // Web status item
            html += '<div class="d-flex align-items-center justify-content-between p-2 rounded-3 mb-2 ' + (webOk ? 'alert alert-success py-2 mb-2' : 'alert alert-danger py-2 mb-2') + '">';
            html += '<span><i class="bi ' + (webOk ? 'bi-check-circle-fill' : 'bi-x-circle-fill') + ' me-2"></i> Web Server:</span>';
            html += '<span class="badge ' + (webOk ? 'badge-status-active' : 'bg-danger') + ' px-3 py-1 rounded-pill">' + (webOk ? 'OK (Aktif)' : 'Gagal') + '</span>';
            html += '</div>';

            // DB status item
            html += '<div class="d-flex align-items-center justify-content-between p-2 rounded-3 ' + (dbOk ? 'alert alert-success py-2 mb-0' : 'alert alert-warning py-2 mb-0') + '">';
            html += '<span><i class="bi ' + (dbOk ? 'bi-database-fill-check' : 'bi-database-fill-x') + ' me-2"></i> Database MySQL (3306):</span>';
            html += '<span class="badge ' + (dbOk ? 'badge-status-active' : 'bg-warning') + ' px-3 py-1 rounded-pill">' + (dbOk ? 'OK (Terhubung)' : 'Gagal') + '</span>';
            html += '</div>';

            if (!dbOk && data.database && data.database.message) {
                html += '<div class="small mt-2 p-2 rounded alert alert-warning">' + data.database.message + '</div>';
            }

            box.className = 'p-3 rounded-3 mb-3 border';
            box.innerHTML = html;
            return;
        }
    } catch (e) {
        // Fallback jika API internal belum merespon
    }

    // Fallback uji via PyWebView desktop bridge jika ada
    try {
        if (window.pywebview && window.pywebview.api && window.pywebview.api.test_connection) {
            const res = await window.pywebview.api.test_connection(targetUrl);
            if (res.status === 'success') {
                box.className = 'alert alert-success py-2 small mb-3';
                box.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> <strong>Berhasil!</strong> Server di <code>' + targetUrl + '</code> merespon aktif.';
            } else {
                box.className = 'alert alert-danger py-2 small mb-3';
                box.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> <strong>Gagal Terhubung:</strong> ' + (res.message || 'Tidak ada respon.');
            }
            return;
        }
    } catch (err) {}

    box.className = 'alert alert-warning py-2 small mb-3';
    box.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Pengujian selesai. Pastikan alamat server benar sebelum menyimpan.';
    spinner.classList.add('d-none');
    icon.classList.remove('d-none');
}

async function saveAndApplyModalIp() {
    const targetUrl = getModalTargetUrl();
    const targetIp = getModalTargetIp();
    const box = document.getElementById('loginTestStatusBox');
    box.classList.remove('d-none');
    box.className = 'alert alert-info py-2 small mb-3';
    box.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Menyimpan konfigurasi Web App & Database MySQL ke <code>config.ini</code>...';

    let redirectUrl = targetUrl + 'login.php';

    try {
        // 1. Simpan via API backend PHP (menulis config.ini server & database)
        const resp = await fetch('api/server_config.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save', target: targetUrl })
        });
        const resJson = await resp.json();
        if (resJson && resJson.redirect_url) {
            redirectUrl = resJson.redirect_url;
        }
    } catch (e) {}

    try {
        // 2. Simpan via PyWebView desktop bridge jika berjalan di aplikasi desktop
        if (window.pywebview && window.pywebview.api && window.pywebview.api.save_server_ip) {
            await window.pywebview.api.save_server_ip(targetUrl);
            return;
        }
    } catch (e) {}

    // Simpan juga ke localStorage browser
    try {
        localStorage.setItem('qios_server_url', targetUrl);
        localStorage.setItem('qios_db_host', targetIp);
    } catch (e) {}

    box.className = 'alert alert-success py-2 small mb-3';
    box.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> <strong>Berhasil Disimpan!</strong> Mengalihkan ke alamat server baru...';

    setTimeout(() => {
        window.location.href = redirectUrl;
    }, 600);
}
function toggleLoginPassword(btn) {
    const input = document.getElementById('password');
    if (!input) return;
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        if (icon) {
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        }
    } else {
        input.type = 'password';
        if (icon) {
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    }
}
</script>
</body>
</html>
