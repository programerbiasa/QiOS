<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_admin();

$admin_title = 'Pengaturan Sistem';

// Buat folder assets/images jika belum ada
$assets_dir = __DIR__ . '/../assets/images';
if (!is_dir($assets_dir)) {
    mkdir($assets_dir, 0775, true);
}

$logo_png_path   = $assets_dir . '/logo.png';
$logo_gif_path   = $assets_dir . '/logo.gif';
$active_logo_rel = function_exists('get_app_logo_path') ? get_app_logo_path() : 'assets/images/logo.png';
$logo_path       = __DIR__ . '/../' . $active_logo_rel;
$logo_rel        = '../' . $active_logo_rel;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Simpan Perubahan Nama Aplikasi & Yayasan
    if ($action === 'update_app_name') {
        $nama_aplikasi = trim($_POST['nama_aplikasi'] ?? '');
        $nama_yayasan  = trim($_POST['nama_yayasan'] ?? '');

        if ($nama_aplikasi === '') {
            $_SESSION['flash_error'] = 'Nama aplikasi tidak boleh kosong!';
        } elseif (mb_strlen($nama_aplikasi) > 100) {
            $_SESSION['flash_error'] = 'Nama aplikasi maksimal 100 karakter!';
        } elseif (mb_strlen($nama_yayasan) > 150) {
            $_SESSION['flash_error'] = 'Nama yayasan / instansi maksimal 150 karakter!';
        } else {
            $savedApp = set_setting('nama_aplikasi', $nama_aplikasi, 'Nama aplikasi / toko yang tampil di sistem, header, kasir, dan struk');
            $savedYay = set_setting('nama_yayasan', $nama_yayasan, 'Nama yayasan / instansi lembaga pengelola laporan & kop');
            if ($savedApp) {
                $_SESSION['flash_success'] = 'Identitas aplikasi dan yayasan berhasil diperbarui!';
            } else {
                $_SESSION['flash_error'] = 'Gagal menyimpan identitas ke database.';
            }
        }
        header('Location: pengaturan.php');
        exit;
    }

    // 2. Reset Nama Aplikasi ke Default
    if ($action === 'reset_app_name') {
        set_setting('nama_aplikasi', 'QiOS Cendekia', 'Nama aplikasi bawaan sistem');
        set_setting('nama_yayasan', '', 'Nama yayasan bawaan');
        $_SESSION['flash_success'] = 'Identitas berhasil dikembalikan ke default "QiOS Cendekia"!';
        header('Location: pengaturan.php');
        exit;
    }

    // 3. Upload Logo Sistem & Struk (Mendukung PNG, JPG, WEBP, GIF Animasi, ICO)
    if ($action === 'upload_logo') {
        if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
            $err_code = $_FILES['logo_file']['error'] ?? -1;
            $err_msg = 'Pilih file logo terlebih dahulu.';
            if ($err_code === UPLOAD_ERR_INI_SIZE || $err_code === UPLOAD_ERR_FORM_SIZE) {
                $err_msg = 'Ukuran file melebihi batas maksimal server.';
            }
            $_SESSION['flash_error'] = $err_msg;
        } else {
            $file = $_FILES['logo_file'];
            $file_size = $file['size'];
            $file_tmp  = $file['tmp_name'];

            // Validasi ukuran maks 5MB (mendukung GIF animasi)
            if ($file_size > 5 * 1024 * 1024) {
                $_SESSION['flash_error'] = 'Ukuran file terlalu besar! Maksimal 5 MB.';
            } else {
                // Validasi tipe MIME & Ekstensi
                $mime_type = '';
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $file_tmp);
                    finfo_close($finfo);
                } elseif (function_exists('mime_content_type')) {
                    $mime_type = @mime_content_type($file_tmp);
                } elseif (function_exists('getimagesize')) {
                    $img_info = @getimagesize($file_tmp);
                    $mime_type = $img_info['mime'] ?? '';
                }

                $allowed_mimes = [
                    'image/png'                => 'png',
                    'image/jpeg'               => 'jpg',
                    'image/webp'               => 'webp',
                    'image/gif'                => 'gif',
                    'image/x-icon'             => 'ico',
                    'image/vnd.microsoft.icon' => 'ico'
                ];

                if (!array_key_exists($mime_type, $allowed_mimes)) {
                    $_SESSION['flash_error'] = 'Format file tidak didukung! Harap upload file PNG, JPG, WEBP, GIF, atau ICO.';
                } else {
                    // Backup logo aktif lama jika ada
                    if (file_exists($logo_path)) {
                        $old_ext = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION)) ?: 'png';
                        @copy($logo_path, $assets_dir . '/logo_backup_' . date('Ymd_His') . '.' . $old_ext);
                    }

                    $ext = $allowed_mimes[$mime_type];
                    $saved = false;
                    $new_setting_path = 'assets/images/logo.png';

                    if ($ext === 'gif') {
                        // Simpan file GIF langsung tanpa konversi agar animasi & transparansi GIF terjaga utuh
                        $saved = move_uploaded_file($file_tmp, $logo_gif_path);
                        if ($saved) {
                            @chmod($logo_gif_path, 0664);
                            $new_setting_path = 'assets/images/logo.gif';
                            // Buat cadangan frame statis PNG jika dibutuhkan fallback statis
                            if (function_exists('imagecreatefromgif')) {
                                $im = @imagecreatefromgif($logo_gif_path);
                                if ($im !== false) {
                                    imagealphablending($im, false);
                                    imagesavealpha($im, true);
                                    @imagepng($im, $logo_png_path);
                                    @chmod($logo_png_path, 0664);
                                    imagedestroy($im);
                                }
                            }
                        }
                    } elseif ($ext === 'png') {
                        $saved = move_uploaded_file($file_tmp, $logo_png_path);
                    } else {
                        // Jika JPG / WEBP / ICO, convert ke PNG via GD agar kompatibel di semua browser dan printer struk
                        if (function_exists('imagecreatefromstring')) {
                            $img_data = file_get_contents($file_tmp);
                            $im = @imagecreatefromstring($img_data);
                            if ($im !== false) {
                                imagealphablending($im, false);
                                imagesavealpha($im, true);
                                $saved = imagepng($im, $logo_png_path);
                                imagedestroy($im);
                            }
                        }
                        if (!$saved) {
                            $saved = move_uploaded_file($file_tmp, $logo_png_path);
                        }
                    }

                    if ($saved) {
                        if ($ext !== 'gif') {
                            @chmod($logo_png_path, 0664);
                        }
                        set_setting('logo_aplikasi', $new_setting_path, 'Path relatif logo sistem aktif');
                        $_SESSION['flash_success'] = 'Logo sistem berhasil diperbarui (' . strtoupper($ext) . ')!';
                    } else {
                        $_SESSION['flash_error'] = 'Gagal menyimpan logo ke server. Periksa hak akses folder.';
                    }
                }
            }
        }
        header('Location: pengaturan.php');
        exit;
    }

    // 5. Update Tema Tampilan Aplikasi
    if ($action === 'update_theme') {
        $selected_theme = trim($_POST['theme'] ?? '');
        $all_th = function_exists('get_all_themes') ? get_all_themes() : [];
        if (array_key_exists($selected_theme, $all_th)) {
            $saved = set_setting('tema_aplikasi', $selected_theme, 'Tema visual sistem QiOS Cendekia');
            if ($saved) {
                $_SESSION['flash_success'] = 'Tema tampilan berhasil diubah ke "' . e($all_th[$selected_theme]['name']) . '"!';
            } else {
                $_SESSION['flash_error'] = 'Gagal menyimpan pengaturan tema ke database.';
            }
        } else {
            $_SESSION['flash_error'] = 'Pilihan tema tidak valid!';
        }
        header('Location: pengaturan.php');
        exit;
    }

    // 4. Reset Logo ke Default QiOS
    if ($action === 'reset_logo') {
        $default_qios = $assets_dir . '/qios_logo.png';
        if (file_exists($default_qios)) {
            copy($default_qios, $logo_png_path);
            @chmod($logo_png_path, 0664);
            set_setting('logo_aplikasi', 'assets/images/logo.png', 'Path relatif logo sistem aktif');
            $_SESSION['flash_success'] = 'Logo berhasil dikembalikan ke default QiOS!';
        } else {
            $_SESSION['flash_error'] = 'File logo default tidak ditemukan.';
        }
        header('Location: pengaturan.php');
        exit;
    }
}

require_once __DIR__ . '/includes/admin_header.php';

// Versioning cache buster untuk preview logo
$active_logo_rel = function_exists('get_app_logo_path') ? get_app_logo_path() : 'assets/images/logo.png';
$logo_path       = __DIR__ . '/../' . $active_logo_rel;
$logo_rel        = '../' . $active_logo_rel;
$logo_ver        = file_exists($logo_path) ? filemtime($logo_path) : time();
$logo_size_kb    = file_exists($logo_path) ? round(filesize($logo_path) / 1024, 1) : 0;
$logo_ext_label  = strtoupper(pathinfo($active_logo_rel, PATHINFO_EXTENSION) ?: 'PNG');
$current_app_name     = get_app_name();
$current_yayasan_name = function_exists('get_setting') ? get_setting('nama_yayasan', '') : '';
$current_theme_key    = function_exists('get_app_theme') ? get_app_theme() : 'midnight';
$all_themes        = function_exists('get_all_themes') ? get_all_themes() : [];
$active_theme_data = function_exists('get_current_theme_data') ? get_current_theme_data() : [];
?>

<div class="row g-4">
    <!-- HEADER HALAMAN -->
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <div>
                <h4 class="fw-bold text-white mb-0">
                    <i class="bi bi-gear-fill text-info me-2"></i>Pengaturan Sistem
                </h4>
                <p class="text-secondary small mb-0">Kelola identitas aplikasi, nama toko, logo, dan visual <?= e($current_app_name) ?></p>
            </div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-arrow-left me-1"></i>Kembali ke Dashboard
            </a>
        </div>
    </div>

    <!-- FLASH MESSAGES -->
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="col-12">
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 py-2 px-3 small" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?= e($_SESSION['flash_success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="col-12">
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 py-2 px-3 small" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($_SESSION['flash_error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <!-- KARTU 1: PENGATURAN NAMA APLIKASI -->
    <div class="col-12">
        <div class="card shadow-sm rounded-4 border-secondary border-opacity-50">
            <div class="card-header bg-dark bg-opacity-75 border-secondary border-opacity-50 py-3 d-flex align-items-center justify-content-between">
                <span class="fw-bold text-white">
                    <i class="bi bi-fonts text-info me-2"></i>Identitas & Nama Aplikasi
                </span>
                <span class="badge bg-primary bg-opacity-25 text-info px-2 py-1 small">Brand Identitas</span>
            </div>
            <div class="card-body p-4">
                <form action="pengaturan.php" method="POST">
                    <input type="hidden" name="action" value="update_app_name">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6">
                            <label for="nama_aplikasi" class="form-label fw-semibold text-light small mb-1">
                                Nama Aplikasi / Nama Toko <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary text-info">
                                    <i class="bi bi-shop"></i>
                                </span>
                                <input type="text" 
                                       class="form-control bg-dark border-secondary text-white fw-semibold" 
                                       id="nama_aplikasi" 
                                       name="nama_aplikasi" 
                                       value="<?= e($current_app_name) ?>" 
                                       maxlength="100" 
                                       required 
                                       placeholder="Contoh: QiOS Cendekia / Toko Berkah Cendekia">
                            </div>
                            <div class="form-text text-secondary small mt-2" style="font-size: 0.74rem;">
                                Ditampilkan pada <strong>Navbar Admin</strong>, <strong>Topbar Kasir POS</strong>, <strong>Struk Kasir</strong>, dan <strong>Laporan</strong>.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="nama_yayasan" class="form-label fw-semibold text-light small mb-1">
                                Nama Yayasan / Instansi Pengelola <span class="text-secondary">(Kop Laporan)</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary text-success">
                                    <i class="bi bi-building"></i>
                                </span>
                                <input type="text" 
                                       class="form-control bg-dark border-secondary text-white fw-semibold" 
                                       id="nama_yayasan" 
                                       name="nama_yayasan" 
                                       value="<?= e($current_yayasan_name) ?>" 
                                       maxlength="150" 
                                       placeholder="Contoh: Yayasan Cendekia Harapan / Pondok Pesantren">
                            </div>
                            <div class="form-text text-secondary small mt-2" style="font-size: 0.74rem;">
                                Ditampilkan pada <strong>Kop Laporan Transaksi</strong>, <strong>Cetak PDF</strong>, dan <strong>Ekspor Excel</strong>.
                            </div>
                        </div>

                        <div class="col-12 d-flex gap-2 justify-content-end flex-wrap pt-2">
                            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm">
                                <i class="bi bi-check-circle-fill me-1"></i>Simpan Identitas
                            </button>
                            <?php if ($current_app_name !== 'QiOS Cendekia' || !empty($current_yayasan_name)): ?>
                                <button type="button" 
                                        class="btn btn-outline-secondary rounded-pill px-3" 
                                        onclick="if(confirm('Kembalikan identitas aplikasi ke bawaan sistem?')) document.getElementById('formResetAppName').submit();"
                                        title="Kembalikan ke Default">
                                    <i class="bi bi-arrow-counterclockwise"></i> Default
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
                <form id="formResetAppName" action="pengaturan.php" method="POST" class="d-none">
                    <input type="hidden" name="action" value="reset_app_name">
                </form>
            </div>
        </div>
    </div>

    <!-- KARTU 2: PENGATURAN LOGO TOKO / SISTEM -->
    <div class="col-lg-7">
        <div class="card shadow-sm rounded-4 border-secondary border-opacity-50 h-100">
            <div class="card-header bg-dark bg-opacity-75 border-secondary border-opacity-50 py-3 d-flex align-items-center justify-content-between">
                <span class="fw-bold text-white">
                    <i class="bi bi-image text-warning me-2"></i>Ubah Logo Sistem & Struk
                </span>
                <span class="badge bg-info bg-opacity-25 text-info px-2 py-1 small">Branding</span>
            </div>
            <div class="card-body p-4">
                <form action="pengaturan.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_logo">

                    <div class="row align-items-center g-4 mb-4">
                        <div class="col-sm-auto text-center">
                            <div class="p-3 bg-darker rounded-4 border border-secondary border-opacity-50 d-inline-block shadow-sm" style="min-width: 140px;">
                                <div class="text-secondary small mb-2 fw-semibold">Logo Saat Ini:</div>
                                <img src="<?= $logo_rel ?>?v=<?= $logo_ver ?>" 
                                     id="previewImg" 
                                     alt="Logo <?= e($current_app_name) ?>" 
                                     class="img-fluid rounded p-1 bg-dark border border-secondary" 
                                     style="max-height: 90px; max-width: 110px; object-fit: contain;">
                                <div class="text-muted mt-2" style="font-size: 0.7rem;">
                                    <?= $logo_size_kb ?> KB (<?= e($logo_ext_label) ?>)
                                </div>
                            </div>
                        </div>

                        <div class="col-sm">
                            <label for="logo_file" class="form-label fw-semibold text-light small mb-1">
                                Pilih File Logo Baru
                            </label>
                            <input type="file" 
                                   class="form-control bg-dark border-secondary text-white" 
                                   id="logo_file" 
                                   name="logo_file" 
                                   accept="image/png, image/jpeg, image/webp, image/gif, image/x-icon, .png, .jpg, .jpeg, .webp, .gif, .ico" 
                                   required 
                                   onchange="previewLogo(event)">
                            <div class="form-text text-secondary small mt-2" style="font-size: 0.74rem; line-height: 1.4;">
                                • Format yang didukung: <strong>PNG</strong>, <strong>JPG</strong>, <strong>WEBP</strong>, <strong>GIF</strong> (mendukung animasi bergerak), atau <strong>ICO</strong>.<br>
                                • Ukuran file maksimal: <strong>5 MB</strong>.<br>
                                • Logo ini otomatis digunakan pada <strong>Navbar Admin</strong>, <strong>Halaman Kasir (POS)</strong>, <strong>Struk Cetak Thermal 58mm</strong>, dan <strong>Halaman Login</strong>.
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-3 border-top border-secondary border-opacity-50">
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm">
                            <i class="bi bi-cloud-arrow-up-fill me-1"></i>Simpan & Pasang Logo
                        </button>
                    </div>
                </form>

                <?php if (file_exists($assets_dir . '/qios_logo.png')): ?>
                    <div class="mt-4 pt-3 border-top border-secondary border-opacity-25 d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <span class="text-secondary small" style="font-size: 0.78rem;">
                            Ingin kembali ke logo asli bawaan sistem?
                        </span>
                        <form action="pengaturan.php" method="POST" onsubmit="return confirm('Kembalikan logo ke default asli QiOS Cendekia?');">
                            <input type="hidden" name="action" value="reset_logo">
                            <button type="submit" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset ke Logo Default
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- KARTU 3: INFORMASI APLIKASI & LOKASI FILE LOGO -->
    <div class="col-lg-5">
        <div class="card shadow-sm rounded-4 border-secondary border-opacity-50 h-100">
            <div class="card-header bg-dark bg-opacity-75 border-secondary border-opacity-50 py-3">
                <span class="fw-bold text-white">
                    <i class="bi bi-info-circle-fill text-info me-2"></i>Informasi Sistem & Integrasi
                </span>
            </div>
            <div class="card-body p-4">
                <ul class="list-group list-group-flush bg-transparent">
                    <li class="list-group-item bg-transparent text-secondary px-0 py-2 d-flex justify-content-between align-items-center border-secondary border-opacity-25">
                        <span class="small">Nama Aplikasi Aktif</span>
                        <span class="text-white fw-bold small"><?= e($current_app_name) ?></span>
                    </li>
                    <li class="list-group-item bg-transparent text-secondary px-0 py-2 d-flex justify-content-between align-items-center border-secondary border-opacity-25">
                        <span class="small">Penyimpanan Logo</span>
                        <code class="text-info small"><?= e($active_logo_rel) ?></code>
                    </li>
                    <li class="list-group-item bg-transparent text-secondary px-0 py-2 d-flex justify-content-between align-items-center border-secondary border-opacity-25">
                        <span class="small">Penggunaan Logo</span>
                        <span class="badge bg-success bg-opacity-25 text-success small">Multi-Platform (Admin, Kasir, Struk)</span>
                    </li>
                    <li class="list-group-item bg-transparent text-secondary px-0 py-2 d-flex justify-content-between align-items-center border-secondary border-opacity-25">
                        <span class="small">Format Struk Thermal</span>
                        <span class="text-white small">58mm (PT-210) & Standar</span>
                    </li>
                    <li class="list-group-item bg-transparent text-secondary px-0 py-2 d-flex justify-content-between align-items-center border-secondary border-opacity-25">
                        <span class="small">Akses Kasir Cepat</span>
                        <a href="../qios/" target="_blank" class="text-info small text-decoration-none">
                            <i class="bi bi-box-arrow-up-right me-1"></i>/qios
                        </a>
                    </li>
                    <li class="list-group-item bg-transparent text-secondary px-0 py-2 d-flex justify-content-between align-items-center border-secondary border-opacity-25">
                        <span class="small">Host Web Server</span>
                        <span class="badge bg-secondary font-monospace"><?= e($_SERVER['HTTP_HOST'] ?? '127.0.0.1') ?></span>
                    </li>
                    <li class="list-group-item bg-transparent text-secondary px-0 py-2 d-flex justify-content-between align-items-center border-secondary border-opacity-25">
                        <span class="small">Database MySQL</span>
                        <span class="badge bg-success bg-opacity-25 text-success font-monospace"><?= e($GLOBALS['active_db_host'] ?? ($db_host ?? '127.0.0.1')) ?>:3306</span>
                    </li>
                </ul>

                <button type="button" class="btn btn-outline-info btn-sm w-100 rounded-pill mt-3 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#modalAdminServerSetting">
                    <i class="bi bi-hdd-network me-1"></i> Pengaturan IP Server & Database
                </button>

                <div class="alert alert-dark bg-darker border-secondary border-opacity-50 rounded-3 mt-3 mb-0 p-3">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill text-warning fs-5"></i>
                        <div class="small text-secondary" style="font-size: 0.75rem; line-height: 1.4;">
                            <strong>Tips Identitas:</strong> Setelah mengubah nama aplikasi atau logo, seluruh tampilan kasir dan nota cetak akan langsung menyesuaikan identitas toko Anda secara real-time.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- KARTU 3: PILIHAN TEMA TAMPILAN APLIKASI -->
    <div class="col-12 mt-4">
        <div class="card shadow-sm rounded-4 border-secondary border-opacity-50">
            <div class="card-header bg-dark bg-opacity-50 border-secondary border-opacity-25 py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary bg-opacity-25 text-info p-2 rounded-3 fs-6">
                        <i class="bi bi-palette-fill"></i>
                    </span>
                    <div>
                        <h6 class="fw-bold text-white mb-0">Tema Tampilan Aplikasi</h6>
                        <small class="text-secondary">Pilih tema tampilan untuk seluruh sistem (Terang, Kalem, Lembut, atau Gelap)</small>
                    </div>
                </div>
                <div>
                    <span class="badge bg-secondary bg-opacity-25 text-light px-3 py-2 rounded-pill small">
                        Tema Aktif: <strong class="text-info"><?= e($active_theme_data['name'] ?? 'Midnight Slate') ?></strong>
                    </span>
                </div>
            </div>
            <div class="card-body p-4">
                <form action="pengaturan.php" method="POST">
                    <input type="hidden" name="action" value="update_theme">

                    <div class="row g-3 mb-4">
                        <?php foreach ($all_themes as $th_key => $th): ?>
                            <?php $is_active = ($th_key === $current_theme_key); ?>
                            <div class="col-md-6 col-lg-3 col-12">
                                <label class="theme-card-option d-block h-100 p-3 rounded-4 border position-relative <?= $is_active ? 'border-primary shadow' : 'border-secondary border-opacity-50' ?>" style="background: <?= $th['preview']['card'] ?>; cursor: pointer; transition: all 0.2s;">
                                    <input type="radio" name="theme" value="<?= e($th_key) ?>" class="d-none" <?= $is_active ? 'checked' : '' ?> onchange="this.form.submit()">

                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <span class="badge mb-1" style="background: <?= $th['preview']['accent'] ?>; color: <?= $th['mode'] === 'light' ? '#ffffff' : '#000000' ?>; font-weight: 700;">
                                                <?= e($th['badge']) ?>
                                            </span>
                                            <h6 class="fw-bold mb-0" style="color: <?= $th['preview']['text'] ?>;">
                                                <?= e($th['name']) ?>
                                            </h6>
                                        </div>
                                        <?php if ($is_active): ?>
                                            <span class="badge bg-success rounded-pill px-2 py-1 small">
                                                <i class="bi bi-check-circle-fill me-1"></i> Aktif
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-50 text-light rounded-pill px-2 py-1 small">
                                                Pilih
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <p class="small mb-3" style="color: <?= $th['mode'] === 'light' ? '#64748b' : '#94a3b8' ?>; font-size: 0.8rem; min-height: 38px;">
                                        <?= e($th['description']) ?>
                                    </p>

                                    <!-- Swatch Warna Mini Pratinjau -->
                                    <div class="p-2 rounded-3 d-flex align-items-center justify-content-between border" style="background: <?= $th['preview']['bg'] ?>; border-color: rgba(255,255,255,0.1) !important;">
                                        <div class="d-flex align-items-center gap-1">
                                            <span class="d-inline-block rounded-circle" style="width: 14px; height: 14px; background: <?= $th['preview']['bg'] ?>; border: 1px solid rgba(255,255,255,0.3);" title="Background"></span>
                                            <span class="d-inline-block rounded-circle" style="width: 14px; height: 14px; background: <?= $th['preview']['card'] ?>; border: 1px solid rgba(255,255,255,0.3);" title="Card"></span>
                                            <span class="d-inline-block rounded-circle" style="width: 14px; height: 14px; background: <?= $th['preview']['accent'] ?>;" title="Accent"></span>
                                        </div>
                                        <span class="badge rounded-pill" style="font-size: 0.7rem; background: <?= $th['preview']['accent'] ?>; color: <?= $th['mode'] === 'light' ? '#fff' : '#000' ?>;">
                                            Pratinjau POS
                                        </span>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-2 border-top border-secondary border-opacity-25">
                        <span class="text-secondary small">
                            <i class="bi bi-info-circle me-1"></i> Klik pada kartu tema untuk langsung menerapkan perubahan ke seluruh sistem.
                        </span>
                        <button type="submit" class="btn btn-primary px-4 fw-bold rounded-pill">
                            <i class="bi bi-check2-circle me-1"></i> Terapkan Tema Pilihan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

    <!-- KARTU 4: PEMBARUAN SISTEM OTOMATIS (GIT & GITHUB) -->
    <div class="col-12 mt-4">
        <div class="card shadow-sm rounded-4 border-secondary border-opacity-50">
            <div class="card-header bg-dark bg-opacity-75 border-secondary border-opacity-50 py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-success bg-opacity-25 text-success p-2 rounded-3 fs-6">
                        <i class="bi bi-cloud-arrow-down-fill"></i>
                    </span>
                    <div>
                        <h6 class="fw-bold text-white mb-0">Pembaruan Sistem (System Update)</h6>
                        <small class="text-secondary">Cek dan sinkronisasikan aplikasi langsung dari GitHub (Raspberry Pi & Server)</small>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-dark border border-secondary text-info font-monospace px-3 py-2" id="badgeCommitLocal">
                        <i class="bi bi-git me-1"></i>Memuat Versi...
                    </span>
                    <button type="button" class="btn btn-outline-info btn-sm rounded-pill px-3 shadow-sm" id="btnCheckUpdate" onclick="checkSystemUpdate()">
                        <i class="bi bi-arrow-repeat me-1" id="iconCheckUpdate"></i> Cek Pembaruan
                    </button>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row g-3 align-items-center">
                    <div class="col-md-7">
                        <div class="p-3 rounded-3 bg-dark bg-opacity-50 border border-secondary border-opacity-25">
                            <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-secondary border-opacity-25">
                                <span class="text-secondary small">Versi Lokal Terpasang</span>
                                <span class="font-monospace fw-bold text-white small" id="txtLocalCommit">-</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-secondary border-opacity-25">
                                <span class="text-secondary small">Waktu Pembaruan Terakhir</span>
                                <span class="text-white small" id="txtLocalDate">-</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="text-secondary small me-2">Catatan Perubahan (Commit)</span>
                                <span class="text-light small text-end fst-italic" id="txtLocalMsg">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div id="updateStatusAlert" class="alert alert-secondary border-0 rounded-4 p-3 mb-0">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-shield-check text-info fs-4" id="iconStatusUpdate"></i>
                                <div>
                                    <h6 class="fw-bold mb-0 text-white" id="titleStatusUpdate">Status Sistem</h6>
                                    <small class="text-secondary" id="descStatusUpdate">Klik tombol "Cek Pembaruan" untuk memeriksa update di GitHub.</small>
                                </div>
                            </div>
                            <div id="boxActionUpdate" class="d-none mt-3 pt-2 border-top border-secondary border-opacity-25 text-end">
                                <button type="button" class="btn btn-success btn-sm rounded-pill px-3 fw-bold shadow" id="btnExecuteUpdate" onclick="executeSystemUpdate()">
                                    <i class="bi bi-download me-1"></i> Pasang Pembaruan Sekarang
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Log Output Panel (Hidden by default) -->
                <div id="boxUpdateLog" class="d-none mt-3">
                    <label class="form-label text-secondary small fw-semibold mb-1">Log Proses Pembaruan:</label>
                    <pre class="bg-black text-success p-3 rounded-3 font-monospace small mb-0 border border-secondary border-opacity-25" style="max-height: 180px; overflow-y: auto;" id="txtUpdateLog"></pre>
                </div>
            </div>
        </div>
    </div>

<!-- Modal Pengaturan IP Server Admin -->
<div class="modal fade" id="modalAdminServerSetting" tabindex="-1" aria-labelledby="modalAdminServerSettingLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-start shadow-lg rounded-4">
            <div class="modal-header pb-3">
                <h5 class="modal-title fw-bold d-flex align-items-center" id="modalAdminServerSettingLabel">
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
                    <label for="adminInputModalIp" class="form-label small fw-bold">Alamat IP / Domain Server Baru:</label>
                    <div class="input-group">
                        <select class="form-select" id="adminSelectModalProto" style="max-width: 105px;">
                            <option value="http://">http://</option>
                            <option value="https://">https://</option>
                        </select>
                        <input type="text" class="form-control font-monospace" id="adminInputModalIp" 
                               placeholder="Contoh: 172.16.5.11 atau localhost" 
                               value="<?= e(explode(':', $_SERVER['HTTP_HOST'] ?? '127.0.0.1')[0]) ?>">
                    </div>
                    <div class="form-text text-secondary small mt-1">
                        <i class="bi bi-arrow-repeat text-primary me-1"></i>Database server akan otomatis mengikuti alamat IP server ini. Pastikan laptop kasir dan server dalam satu jaringan WiFi/LAN yang sama.
                    </div>
                </div>

                <!-- Box Hasil Pengujian / Status -->
                <div id="adminTestStatusBox" class="d-none mb-3"></div>
            </div>
            <div class="modal-footer pt-3 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-primary btn-sm px-3 rounded-pill fw-semibold" onclick="testAdminServerConnection()">
                    <span id="adminTestSpinner" class="spinner-border spinner-border-sm me-1 d-none" role="status"></span>
                    <i id="adminTestIcon" class="bi bi-broadcast me-1"></i> Tes Koneksi
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-primary btn-sm px-3 fw-bold rounded-pill shadow-sm" onclick="saveAndApplyAdminIp()">
                        <i class="bi bi-check-circle-fill me-1"></i> Simpan & Hubungkan
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
        </div>
    </div>
</div>

<script>
function previewLogo(event) {
    const input = event.target;
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('previewImg');
            if (preview) {
                preview.src = e.target.result;
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function getAdminModalTargetUrl() {
    let proto = document.getElementById('adminSelectModalProto').value;
    let ip = document.getElementById('adminInputModalIp').value.trim();
    ip = ip.replace(/^https?:\/\//, '').replace(/\/+$/, '');
    if (!ip) ip = '127.0.0.1';
    return proto + ip + '/';
}

function getAdminModalTargetIp() {
    let ip = document.getElementById('adminInputModalIp').value.trim();
    ip = ip.replace(/^https?:\/\//, '').replace(/\/+$/, '');
    return ip.split(':')[0] || '127.0.0.1';
}

async function testAdminServerConnection() {
    const targetUrl = getAdminModalTargetUrl();
    const targetIp = getAdminModalTargetIp();
    const box = document.getElementById('adminTestStatusBox');
    const spinner = document.getElementById('adminTestSpinner');
    const icon = document.getElementById('adminTestIcon');

    spinner.classList.remove('d-none');
    icon.classList.add('d-none');
    box.className = 'alert alert-info py-2 small mb-3';
    box.classList.remove('d-none');
    box.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Menguji koneksi Web Server & Database MySQL ke <code>' + targetIp + '</code>...';

    try {
        const testApiUrl = '../api/server_config.php?action=test&target=' + encodeURIComponent(targetUrl);
        const resp = await fetch(testApiUrl);
        const data = await resp.json();

        if (data && (data.web || data.database)) {
            const webOk = data.web && data.web.ok;
            const dbOk = data.database && data.database.ok;

            let html = '<div class="fw-bold mb-2"><i class="bi bi-clipboard-pulse me-1 text-primary"></i> Hasil Diagnosa Koneksi:</div>';
            
            html += '<div class="d-flex align-items-center justify-content-between p-2 rounded-3 mb-2 ' + (webOk ? 'alert alert-success py-2 mb-2' : 'alert alert-danger py-2 mb-2') + '">';
            html += '<span><i class="bi ' + (webOk ? 'bi-check-circle-fill' : 'bi-x-circle-fill') + ' me-2"></i> Web Server:</span>';
            html += '<span class="badge ' + (webOk ? 'badge-status-active' : 'bg-danger') + ' px-3 py-1 rounded-pill">' + (webOk ? 'OK (Aktif)' : 'Gagal') + '</span>';
            html += '</div>';

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
    } catch (e) {}

    box.className = 'alert alert-warning py-2 small mb-3';
    box.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Pengujian selesai. Pastikan alamat server benar sebelum menyimpan.';
    spinner.classList.add('d-none');
    icon.classList.remove('d-none');
}

async function saveAndApplyAdminIp() {
    const targetUrl = getAdminModalTargetUrl();
    const targetIp = getAdminModalTargetIp();
    const box = document.getElementById('adminTestStatusBox');
    box.classList.remove('d-none');
    box.className = 'alert alert-info py-2 small mb-3';
    box.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Menyimpan konfigurasi Web App & Database MySQL ke <code>config.ini</code>...';

    let redirectUrl = targetUrl + 'admin/pengaturan.php';

    try {
        const resp = await fetch('../api/server_config.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save', target: targetUrl })
        });
        const resJson = await resp.json();
        if (resJson && resJson.server_url) {
            redirectUrl = rtrimUrl(resJson.server_url) + '/admin/pengaturan.php';
        }
    } catch (e) {}

    try {
        if (window.pywebview && window.pywebview.api && window.pywebview.api.save_server_ip) {
            await window.pywebview.api.save_server_ip(targetUrl);
            return;
        }
    } catch (e) {}

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

function rtrimUrl(url) {
    return url.replace(/\/+$/, '');
}

// ==========================================
// QIOS SYSTEM UPDATER LOGIC
// ==========================================
async function loadLocalGitInfo() {
    try {
        const resp = await fetch('api_updater.php?action=info', { credentials: 'same-origin' });
        const res = await resp.json();
        if (res.status === 'success' && res.data) {
            const d = res.data;
            document.getElementById('badgeCommitLocal').innerHTML = '<i class="bi bi-git me-1"></i>Commit: ' + d.commit + ' (' + d.branch + ')';
            document.getElementById('txtLocalCommit').textContent = d.commit + ' [' + d.branch + ']';
            document.getElementById('txtLocalDate').textContent = d.date;
            document.getElementById('txtLocalMsg').textContent = d.message;
        }
    } catch (e) {
        console.error('Gagal memuat info git lokal:', e);
    }
}

async function checkSystemUpdate() {
    const btn = document.getElementById('btnCheckUpdate');
    const icon = document.getElementById('iconCheckUpdate');
    const alertBox = document.getElementById('updateStatusAlert');
    const titleBox = document.getElementById('titleStatusUpdate');
    const descBox = document.getElementById('descStatusUpdate');
    const iconBox = document.getElementById('iconStatusUpdate');
    const boxAction = document.getElementById('boxActionUpdate');

    btn.disabled = true;
    icon.className = 'spinner-border spinner-border-sm me-1';
    
    alertBox.className = 'alert alert-info border-0 rounded-4 p-3 mb-0';
    iconBox.className = 'bi bi-arrow-repeat text-info fs-4';
    titleBox.textContent = 'Memeriksa GitHub...';
    descBox.textContent = 'Menghubungkan ke repositori GitHub programerbiasa/QiOS...';
    boxAction.classList.add('d-none');

    try {
        const resp = await fetch('api_updater.php?action=check', { credentials: 'same-origin' });
        const res = await resp.json();

        if (res.status === 'success') {
            if (res.local) {
                document.getElementById('badgeCommitLocal').innerHTML = '<i class="bi bi-git me-1"></i>Commit: ' + res.local.commit + ' (' + res.local.branch + ')';
                document.getElementById('txtLocalCommit').textContent = res.local.commit + ' [' + res.local.branch + ']';
                document.getElementById('txtLocalDate').textContent = res.local.date;
                document.getElementById('txtLocalMsg').textContent = res.local.message;
            }

            if (res.has_update) {
                alertBox.className = 'alert alert-warning border-0 rounded-4 p-3 mb-0';
                iconBox.className = 'bi bi-exclamation-circle-fill text-warning fs-4';
                titleBox.textContent = 'Pembaruan Tersedia!';
                descBox.innerHTML = 'Versi baru ditemukan di GitHub: <strong>' + res.remote.commit + '</strong><br>' +
                                    '<span class="small text-white">' + res.remote.message + '</span> (' + res.remote.date + ')';
                boxAction.classList.remove('d-none');
            } else {
                alertBox.className = 'alert alert-success border-0 rounded-4 p-3 mb-0';
                iconBox.className = 'bi bi-check-circle-fill text-success fs-4';
                titleBox.textContent = 'Sistem Terkini';
                descBox.textContent = 'Selamat! Aplikasi QiOS Anda sudah menggunakan versi paling mutakhir.';
                boxAction.classList.add('d-none');
            }
        } else {
            alertBox.className = 'alert alert-danger border-0 rounded-4 p-3 mb-0';
            iconBox.className = 'bi bi-x-circle-fill text-danger fs-4';
            titleBox.textContent = 'Pengecekan Gagal';
            descBox.textContent = res.message || 'Tidak dapat menghubungi server GitHub.';
            boxAction.classList.add('d-none');
        }
    } catch (e) {
        alertBox.className = 'alert alert-danger border-0 rounded-4 p-3 mb-0';
        iconBox.className = 'bi bi-x-circle-fill text-danger fs-4';
        titleBox.textContent = 'Koneksi Terputus';
        descBox.textContent = 'Pastikan server terhubung ke internet. Error: ' + e.message;
        boxAction.classList.add('d-none');
    } finally {
        btn.disabled = false;
        icon.className = 'bi bi-arrow-repeat me-1';
    }
}

async function executeSystemUpdate() {
    if (!confirm('Apakah Anda yakin ingin memperbarui aplikasi QiOS sekarang? Sistem akan menyinkronkan file dari GitHub.')) {
        return;
    }

    const btn = document.getElementById('btnExecuteUpdate');
    const logBox = document.getElementById('boxUpdateLog');
    const logText = document.getElementById('txtUpdateLog');
    const alertBox = document.getElementById('updateStatusAlert');
    const titleBox = document.getElementById('titleStatusUpdate');
    const descBox = document.getElementById('descStatusUpdate');
    const iconBox = document.getElementById('iconStatusUpdate');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Mengunduh & Memasang Update...';

    logBox.classList.remove('d-none');
    logText.textContent = 'Memulai proses update sistem...\nMenghubungi repositori GitHub...\n';

    try {
        const resp = await fetch('api_updater.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=update'
        });
        const res = await resp.json();

        if (res.log) {
            logText.textContent += res.log + '\n';
        }

        if (res.status === 'success') {
            alertBox.className = 'alert alert-success border-0 rounded-4 p-3 mb-0';
            iconBox.className = 'bi bi-check-circle-fill text-success fs-4';
            titleBox.textContent = 'Pembaruan Berhasil!';
            descBox.textContent = res.message || 'Sistem telah berhasil diperbarui.';
            btn.innerHTML = '<i class="bi bi-check me-1"></i> Selesai!';

            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            alertBox.className = 'alert alert-danger border-0 rounded-4 p-3 mb-0';
            iconBox.className = 'bi bi-exclamation-triangle-fill text-danger fs-4';
            titleBox.textContent = 'Pembaruan Gagal';
            descBox.textContent = res.message || 'Gagal menerapkan update.';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i> Coba Lagi';
        }
    } catch (e) {
        logText.textContent += 'Error: ' + e.message + '\n';
        alertBox.className = 'alert alert-danger border-0 rounded-4 p-3 mb-0';
        iconBox.className = 'bi bi-x-circle-fill text-danger fs-4';
        titleBox.textContent = 'Gagal Memproses Update';
        descBox.textContent = 'Terjadi kesalahan jaringan atau server timeout.';
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i> Coba Lagi';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    loadLocalGitInfo();
});

</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
