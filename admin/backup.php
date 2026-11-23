<?php
$admin_title = 'Backup & Restore Database';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_admin();

// Path Direktori Penyimpanan Backup
$backup_dir = __DIR__ . '/../backups';
if (!is_dir($backup_dir)) {
    @mkdir($backup_dir, 0775, true);
}

// Helper Format Ukuran File
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// ========================================================
// 1. PROSES DOWNLOAD FILE BACKUP (GET)
// ========================================================
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $file = basename($_GET['file'] ?? '');
    $filepath = $backup_dir . '/' . $file;

    if (!empty($file) && file_exists($filepath) && str_ends_with($file, '.sql')) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        $_SESSION['flash_error'] = 'File backup tidak ditemukan atau format file tidak valid!';
        header('Location: backup.php');
        exit;
    }
}

// ========================================================
// 2. PROSES POST ACTION (CREATE, RESTORE, DELETE)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- A. BUAT BACKUP DATABASE (CREATE) ---
    if ($action === 'create') {
        $download_now = isset($_POST['download_now']) && $_POST['download_now'] === '1';
        $filename = 'backup_' . $db_name . '_' . date('Y-m-d_His') . '.sql';
        $filepath = $backup_dir . '/' . $filename;

        $success = false;
        $error_msg = '';

        // Metode 1: Menggunakan mysqldump CLI (Cepat & Menyeluruh)
        if (function_exists('exec')) {
            $cmd = sprintf(
                'mysqldump --host=%s --port=%s --user=%s --password=%s --routines --triggers --single-transaction %s > %s 2>&1',
                escapeshellarg($db_host),
                escapeshellarg($db_port),
                escapeshellarg($db_user),
                escapeshellarg($db_pass),
                escapeshellarg($db_name),
                escapeshellarg($filepath)
            );
            $output = [];
            $retval = 0;
            @exec($cmd, $output, $retval);

            if ($retval === 0 && file_exists($filepath) && filesize($filepath) > 0) {
                $success = true;
            } else {
                $error_msg = implode("\n", $output);
            }
        }

        // Metode 2: Fallback Pure PHP PDO jika mysqldump gagal
        if (!$success) {
            try {
                $fp = fopen($filepath, 'w');
                if (!$fp) {
                    throw new Exception("Tidak dapat membuat file di direktori {$backup_dir}");
                }

                // Tulis Header SQL
                fwrite($fp, "-- ========================================================\n");
                fwrite($fp, "-- QiOS Cendekia - Database Backup (PHP PDO Engine)\n");
                fwrite($fp, "-- Database: `{$db_name}`\n");
                fwrite($fp, "-- Tanggal Pembuatan: " . date('Y-m-d H:i:s') . "\n");
                fwrite($fp, "-- ========================================================\n\n");
                fwrite($fp, "SET NAMES utf8mb4;\n");
                fwrite($fp, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

                // Dapatkan semua tabel
                $tables_stmt = $pdo->query("SHOW TABLES");
                $tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($tables as $table) {
                    // Struktur Tabel
                    fwrite($fp, "-- --------------------------------------------------------\n");
                    fwrite($fp, "-- Struktur Tabel `{$table}`\n");
                    fwrite($fp, "-- --------------------------------------------------------\n");
                    fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");

                    $create_stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
                    $create_row = $create_stmt->fetch(PDO::FETCH_NUM);
                    fwrite($fp, $create_row[1] . ";\n\n");

                    // Data Tabel
                    $data_stmt = $pdo->query("SELECT * FROM `{$table}`");
                    $rows = $data_stmt->fetchAll(PDO::FETCH_NUM);

                    if (count($rows) > 0) {
                        fwrite($fp, "-- Data Tabel `{$table}`\n");
                        // Tulis per batch 100 baris
                        $chunks = array_chunk($rows, 100);
                        foreach ($chunks as $chunk) {
                            $insert_vals = [];
                            foreach ($chunk as $row) {
                                $vals = array_map(function($v) use ($pdo) {
                                    if ($v === null) return 'NULL';
                                    return $pdo->quote($v);
                                }, $row);
                                $insert_vals[] = "(" . implode(", ", $vals) . ")";
                            }
                            fwrite($fp, "INSERT INTO `{$table}` VALUES \n" . implode(",\n", $insert_vals) . ";\n");
                        }
                        fwrite($fp, "\n");
                    }
                }

                fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
                fwrite($fp, "-- Backup Selesai\n");
                fclose($fp);

                if (file_exists($filepath) && filesize($filepath) > 0) {
                    $success = true;
                }
            } catch (Exception $e) {
                $success = false;
                $error_msg = $e->getMessage();
            }
        }

        if ($success) {
            $fsize = format_bytes(filesize($filepath));
            $_SESSION['flash_success'] = "Backup database berhasil dibuat! File: {$filename} ({$fsize})";
            if ($download_now) {
                header('Location: backup.php?action=download&file=' . urlencode($filename));
                exit;
            }
        } else {
            @unlink($filepath);
            $_SESSION['flash_error'] = "Gagal membuat backup database! Detail: " . ($error_msg ?: 'Terjadi kesalahan sistem.');
        }

        header('Location: backup.php');
        exit;
    }

    // --- B. RESTORE DATABASE (IMPORT) ---
    if ($action === 'restore') {
        $restore_filepath = '';
        $source_name = '';

        // Skenario 1: Restore dari File yang Diupload
        if (!empty($_FILES['sql_file']['tmp_name'])) {
            $file_info = $_FILES['sql_file'];
            if ($file_info['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['flash_error'] = 'Terjadi kesalahan saat mengunggah file backup (Error Code: ' . $file_info['error'] . ')';
                header('Location: backup.php');
                exit;
            }

            if (!str_ends_with(strtolower($file_info['name']), '.sql')) {
                $_SESSION['flash_error'] = 'File yang diunggah harus memiliki format SQL (.sql)!';
                header('Location: backup.php');
                exit;
            }

            $restore_filepath = $file_info['tmp_name'];
            $source_name = htmlspecialchars($file_info['name']);
        }
        // Skenario 2: Restore dari File Backup yang ada di Server
        elseif (!empty($_POST['filename'])) {
            $chosen_file = basename($_POST['filename']);
            $target = $backup_dir . '/' . $chosen_file;

            if (!file_exists($target) || !str_ends_with($chosen_file, '.sql')) {
                $_SESSION['flash_error'] = 'File backup yang dipilih di server tidak ditemukan!';
                header('Location: backup.php');
                exit;
            }

            $restore_filepath = $target;
            $source_name = $chosen_file;
        } else {
            $_SESSION['flash_error'] = 'Silakan pilih file backup yang ingin di-restore!';
            header('Location: backup.php');
            exit;
        }

        // Proses Eksekusi Restore
        $restore_ok = false;
        $restore_err = '';

        // Metode 1: mysql CLI (Cepat & Handal untuk file besar)
        if (function_exists('exec')) {
            $cmd = sprintf(
                'mysql --host=%s --port=%s --user=%s --password=%s %s < %s 2>&1',
                escapeshellarg($db_host),
                escapeshellarg($db_port),
                escapeshellarg($db_user),
                escapeshellarg($db_pass),
                escapeshellarg($db_name),
                escapeshellarg($restore_filepath)
            );
            $output = [];
            $retval = 0;
            @exec($cmd, $output, $retval);

            if ($retval === 0) {
                $restore_ok = true;
            } else {
                $restore_err = implode("\n", $output);
            }
        }

        // Metode 2: Fallback Pure PHP PDO jika CLI gagal
        if (!$restore_ok) {
            try {
                $sql_content = file_get_contents($restore_filepath);
                if ($sql_content === false) {
                    throw new Exception("Gagal membaca file {$source_name}");
                }

                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                $pdo->exec($sql_content);
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
                $restore_ok = true;
            } catch (Exception $e) {
                $restore_ok = false;
                $restore_err = $e->getMessage();
            }
        }

        if ($restore_ok) {
            $_SESSION['flash_success'] = "Database '{$db_name}' berhasil dipulihkan (Restore) dari: {$source_name}!";
        } else {
            $_SESSION['flash_error'] = "Gagal memulihkan database! Detail: " . ($restore_err ?: 'Periksa sintaks SQL.');
        }

        header('Location: backup.php');
        exit;
    }

    // --- C. HAPUS FILE BACKUP (DELETE) ---
    if ($action === 'delete') {
        $del_file = basename($_POST['filename'] ?? '');
        $del_path = $backup_dir . '/' . $del_file;

        if (!empty($del_file) && file_exists($del_path) && str_ends_with($del_file, '.sql')) {
            if (@unlink($del_path)) {
                $_SESSION['flash_success'] = "File backup '{$del_file}' berhasil dihapus dari server.";
            } else {
                $_SESSION['flash_error'] = "Gagal menghapus file '{$del_file}'. Periksa hak akses file.";
            }
        } else {
            $_SESSION['flash_error'] = "File backup tidak ditemukan!";
        }

        header('Location: backup.php');
        exit;
    }
}

// ========================================================
// 3. AMBIL STATISTIK & DAFTAR FILE BACKUP DI SERVER
// ========================================================
$backup_files = [];
$total_backup_bytes = 0;

if (is_dir($backup_dir)) {
    $scanned = scandir($backup_dir);
    foreach ($scanned as $f) {
        if ($f !== '.' && $f !== '..' && str_ends_with(strtolower($f), '.sql')) {
            $f_path = $backup_dir . '/' . $f;
            $f_size = filesize($f_path);
            $f_time = filemtime($f_path);
            $total_backup_bytes += $f_size;

            $backup_files[] = [
                'name'      => $f,
                'size'      => $f_size,
                'size_fmt'  => format_bytes($f_size),
                'mtime'     => $f_time,
                'date_fmt'  => date('d M Y - H:i:s', $f_time) . ' WITA'
            ];
        }
    }
    // Urutkan file terbaru di paling atas
    usort($backup_files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
}

// Ambil Ukuran Database Aktif & Total Tabel
$db_info = ['tables' => 0, 'size_fmt' => '0 B'];
try {
    $q_info = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_tables,
            COALESCE(SUM(data_length + index_length), 0) AS total_bytes
        FROM information_schema.TABLES 
        WHERE table_schema = ?
    ");
    $q_info->execute([$db_name]);
    $res_info = $q_info->fetch();
    if ($res_info) {
        $db_info['tables'] = (int)$res_info['total_tables'];
        $db_info['size_fmt'] = format_bytes((float)$res_info['total_bytes']);
    }
} catch (PDOException $e) {
    // Abaikan jika information_schema terbatas
}

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="fw-bold mb-1 text-white">
            <i class="bi bi-database-fill-gear text-info me-2"></i>Backup & Restore Database
        </h2>
        <p class="text-secondary mb-0">Cadangkan seluruh data transaksi & produk QiOS Cendekia atau pulihkan data dari file SQL.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="backup.php" class="btn btn-outline-info rounded-pill px-3 shadow-sm" title="Segarkan Halaman">
            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
        </a>
    </div>
</div>

<!-- Kartu Informasi & Statistik Database -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card d-flex align-items-center gap-3">
            <div class="stat-icon bg-info bg-opacity-25 text-info">
                <i class="bi bi-database-check"></i>
            </div>
            <div>
                <span class="text-secondary small fw-semibold text-uppercase">Nama Database</span>
                <h5 class="fw-bold text-white mb-0 font-monospace"><?= e($db_name) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card d-flex align-items-center gap-3">
            <div class="stat-icon bg-primary bg-opacity-25 text-primary">
                <i class="bi bi-table"></i>
            </div>
            <div>
                <span class="text-secondary small fw-semibold text-uppercase">Total Tabel</span>
                <h5 class="fw-bold text-white mb-0"><?= $db_info['tables'] ?> Tabel</h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card d-flex align-items-center gap-3">
            <div class="stat-icon bg-warning bg-opacity-25 text-warning">
                <i class="bi bi-hdd-network"></i>
            </div>
            <div>
                <span class="text-secondary small fw-semibold text-uppercase">Ukuran Database</span>
                <h5 class="fw-bold text-warning mb-0"><?= $db_info['size_fmt'] ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card d-flex align-items-center gap-3">
            <div class="stat-icon bg-success bg-opacity-25 text-success">
                <i class="bi bi-archive-fill"></i>
            </div>
            <div>
                <span class="text-secondary small fw-semibold text-uppercase">File Backup Server</span>
                <h5 class="fw-bold text-success mb-0"><?= count($backup_files) ?> File (<?= format_bytes($total_backup_bytes) ?>)</h5>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Panel Buat Backup Baru -->
    <div class="col-lg-6">
        <div class="card border-secondary shadow-sm rounded-4 h-100">
            <div class="card-header bg-dark border-secondary py-3 d-flex align-items-center gap-2">
                <i class="bi bi-cloud-arrow-down-fill text-success fs-5"></i>
                <h5 class="fw-bold text-white mb-0">Buat Backup Baru</h5>
            </div>
            <div class="card-body p-4 d-flex flex-column justify-content-between">
                <div>
                    <p class="text-secondary small mb-3">
                        Sistem akan mengekspor seluruh struktur tabel dan data di database <code><?= e($db_name) ?></code> ke dalam sebuah file <strong>.SQL</strong> terenkapsulasi secara aman.
                    </p>
                    <ul class="text-secondary small ps-3 mb-4">
                        <li>Mencakup tabel produk, kategori, satuan, transaksi, dan akun pengguna.</li>
                        <li>Dilengkapi proteksi foreign key constraints (bebas bentrok relasi).</li>
                        <li>Dapat diunduh langsung atau disimpan di server untuk arsip jangka panjang.</li>
                    </ul>
                </div>

                <div class="d-flex flex-wrap gap-2 pt-2">
                    <form action="backup.php" method="POST" class="d-inline">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="download_now" value="0">
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm">
                            <i class="bi bi-hdd-fill me-1"></i> Simpan Backup di Server
                        </button>
                    </form>

                    <form action="backup.php" method="POST" class="d-inline">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="download_now" value="1">
                        <button type="submit" class="btn btn-outline-success rounded-pill px-4 fw-semibold shadow-sm">
                            <i class="bi bi-download me-1"></i> Buat & Download ke PC
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel Restore Database (Upload SQL File) -->
    <div class="col-lg-6">
        <div class="card border-secondary shadow-sm rounded-4 h-100">
            <div class="card-header bg-dark border-secondary py-3 d-flex align-items-center gap-2">
                <i class="bi bi-cloud-arrow-up-fill text-warning fs-5"></i>
                <h5 class="fw-bold text-white mb-0">Restore Database dari Komputer</h5>
            </div>
            <div class="card-body p-4">
                <div class="alert alert-warning border-warning border-opacity-50 bg-warning bg-opacity-10 text-warning py-2 px-3 small mb-3 d-flex align-items-center gap-2 rounded-3">
                    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
                    <div>
                        <strong>Peringatan Penting:</strong> Proses restore akan menimpa/memperbarui data database saat ini. Sangat disarankan membuat backup terbaru terlebih dahulu.
                    </div>
                </div>

                <form id="formUploadRestore" action="backup.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="restore">
                    
                    <div class="mb-3">
                        <label for="sql_file" class="form-label small fw-semibold text-secondary">
                            Pilih File Backup SQL (.sql)
                        </label>
                        <input class="form-control bg-dark border-secondary text-white" type="file" id="sql_file" name="sql_file" accept=".sql" required>
                    </div>

                    <button type="button" class="btn btn-warning text-dark fw-bold rounded-pill px-4" onclick="confirmUploadRestore()">
                        <i class="bi bi-arrow-repeat me-1"></i> Mulai Restore Database
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Tabel Daftar File Backup di Server -->
<div class="card border-secondary shadow-sm rounded-4 overflow-hidden mb-4">
    <div class="card-header bg-dark border-secondary py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="fw-bold text-white mb-0">
            <i class="bi bi-folder-check text-info me-2"></i>Daftar File Backup di Server
        </h5>
        <span class="badge bg-secondary">Lokasi: <code>/var/www/html/backups/</code></span>
    </div>

    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0">
            <thead class="table-dark text-secondary border-secondary">
                <tr>
                    <th scope="col" style="width: 50px;" class="text-center">No</th>
                    <th scope="col">Nama File Backup</th>
                    <th scope="col">Ukuran File</th>
                    <th scope="col">Waktu Pembuatan</th>
                    <th scope="col" class="text-center" style="width: 220px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($backup_files)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-secondary">
                            <i class="bi bi-inbox display-4 d-block mb-3 text-secondary opacity-50"></i>
                            <h6 class="fw-bold text-white mb-1">Belum Ada File Backup di Server</h6>
                            <p class="small text-secondary mb-0">Klik tombol <strong>Simpan Backup di Server</strong> di atas untuk membuat file backup pertama Anda.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($backup_files as $idx => $bf): ?>
                        <tr>
                            <td class="text-center text-secondary fw-semibold"><?= $idx + 1 ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-filetype-sql text-info fs-4"></i>
                                    <div>
                                        <strong class="text-white d-block font-monospace"><?= e($bf['name']) ?></strong>
                                        <span class="badge bg-dark border border-secondary text-info small" style="font-size: 0.7rem;">SQL Dump</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-secondary px-2 py-1"><?= $bf['size_fmt'] ?></span>
                            </td>
                            <td class="text-secondary small">
                                <i class="bi bi-clock me-1 text-info"></i><?= $bf['date_fmt'] ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <!-- Download File -->
                                    <a href="backup.php?action=download&file=<?= urlencode($bf['name']) ?>" class="btn btn-outline-success" title="Download File SQL ke Komputer">
                                        <i class="bi bi-download me-1"></i> Unduh
                                    </a>

                                    <!-- Restore File -->
                                    <button type="button" class="btn btn-outline-warning" title="Restore Data dari File Ini" onclick="confirmServerRestore('<?= e(addslashes($bf['name'])) ?>')">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Restore
                                    </button>

                                    <!-- Hapus File -->
                                    <button type="button" class="btn btn-outline-danger" title="Hapus File Backup" onclick="confirmDeleteBackup('<?= e(addslashes($bf['name'])) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL KONFIRMASI RESTORE DARI SERVER -->
<!-- ======================================================== -->
<div class="modal fade" id="modalRestoreServer" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">
            <div class="modal-body p-4 text-center">
                <div class="text-warning mb-3">
                    <i class="bi bi-exclamation-triangle-fill display-3"></i>
                </div>
                <h5 class="fw-bold text-white">Konfirmasi Restore Database</h5>
                <p class="text-secondary small mb-3">
                    Anda akan memulihkan database dari file: <br>
                    <code id="restore_server_filename" class="text-warning font-monospace fw-bold fs-6"></code>
                </p>
                <div class="alert alert-danger bg-danger bg-opacity-10 border-danger border-opacity-50 text-danger text-start small mb-4 py-2">
                    <i class="bi bi-shield-exclamation me-1"></i> Data saat ini akan ditimpa dengan data dari file backup tersebut.
                </div>
                <form action="backup.php" method="POST">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="filename" id="restore_server_input">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-warning text-dark fw-bold rounded-pill px-4">
                            <i class="bi bi-check-lg me-1"></i> Ya, Pulihkan Sekarang
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL KONFIRMASI RESTORE UPLOAD FILE -->
<!-- ======================================================== -->
<div class="modal fade" id="modalRestoreUpload" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">
            <div class="modal-body p-4 text-center">
                <div class="text-warning mb-3">
                    <i class="bi bi-arrow-repeat display-3"></i>
                </div>
                <h5 class="fw-bold text-white">Konfirmasi Unggah & Restore</h5>
                <p class="text-secondary small mb-3">
                    Anda akan memulihkan database dari file komputer: <br>
                    <strong id="restore_upload_filename" class="text-warning font-monospace"></strong>
                </p>
                <div class="alert alert-danger bg-danger bg-opacity-10 border-danger border-opacity-50 text-danger text-start small mb-4 py-2">
                    <i class="bi bi-shield-exclamation me-1"></i> Pastikan file SQL berasal dari sumber terpercaya dan sesuai skema QiOS Cendekia.
                </div>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-warning text-dark fw-bold rounded-pill px-4" onclick="document.getElementById('formUploadRestore').submit();">
                        <i class="bi bi-cloud-arrow-up-fill me-1"></i> Ya, Unggah & Restore
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL KONFIRMASI HAPUS BACKUP -->
<!-- ======================================================== -->
<div class="modal fade" id="modalDeleteBackup" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">
            <div class="modal-body p-4 text-center">
                <div class="text-danger mb-3">
                    <i class="bi bi-trash-fill display-3"></i>
                </div>
                <h5 class="fw-bold text-white">Hapus File Backup?</h5>
                <p class="text-secondary small mb-4">
                    Apakah Anda yakin ingin menghapus file backup <code id="delete_backup_filename" class="text-white font-monospace"></code> dari server? Tindakan ini permanen.
                </p>
                <form action="backup.php" method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="filename" id="delete_backup_input">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">
                            <i class="bi bi-trash me-1"></i> Ya, Hapus File
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmServerRestore(filename) {
    document.getElementById('restore_server_filename').innerText = filename;
    document.getElementById('restore_server_input').value = filename;
    new bootstrap.Modal(document.getElementById('modalRestoreServer')).show();
}

function confirmUploadRestore() {
    const fileInput = document.getElementById('sql_file');
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Silakan pilih file .sql terlebih dahulu!');
        fileInput.focus();
        return;
    }
    const fileName = fileInput.files[0].name;
    if (!fileName.toLowerCase().endsWith('.sql')) {
        alert('File yang dipilih harus berformat .sql!');
        return;
    }
    document.getElementById('restore_upload_filename').innerText = fileName;
    new bootstrap.Modal(document.getElementById('modalRestoreUpload')).show();
}

function confirmDeleteBackup(filename) {
    document.getElementById('delete_backup_filename').innerText = filename;
    document.getElementById('delete_backup_input').value = filename;
    new bootstrap.Modal(document.getElementById('modalDeleteBackup')).show();
}
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
