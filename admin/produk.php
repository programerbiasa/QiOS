<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/includes/ProdukExcelManager.php';
require_admin();

// Handler Unduh Template Excel (.xlsx) untuk Tambah Barang Massal
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    ProdukExcelManager::downloadTemplate($pdo);
    exit;
}

// Ambil daftar kategori untuk dropdown
try {
    $stmt_kats = $pdo->query("SELECT * FROM kategori ORDER BY nama_kategori ASC");
    $list_kategori = $stmt_kats->fetchAll();
} catch (PDOException $e) {
    $list_kategori = [];
}

// Ambil daftar satuan untuk dropdown
try {
    $stmt_sats = $pdo->query("SELECT * FROM satuan ORDER BY nama_satuan ASC");
    $list_satuan = $stmt_sats->fetchAll();
} catch (PDOException $e) {
    $list_satuan = [];
}

/**
 * Helper Fungsi Menyimpan Foto Barang (Upload File, Kompresi Canvas, atau Jepret Kamera Base64)
 */
function handle_photo_upload_or_camera($file, $base64_data = '', $existing_photo = '', $hapus_foto = false) {
    $upload_dir = __DIR__ . '/../assets/images/produk/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0775, true);
    }

    // 0. Jika diminta menghapus foto (kembali ke logo bawaan/default)
    if ($hapus_foto) {
        if (!empty($existing_photo) && file_exists(__DIR__ . '/../' . $existing_photo) && strpos($existing_photo, 'produk/') !== false) {
            @unlink(__DIR__ . '/../' . $existing_photo);
        }
        return null;
    }

    // 1. Cek jika ada hasil jepretan kamera atau kompresi gambar via Base64 DataURL
    if (!empty($base64_data) && strpos($base64_data, 'data:image') === 0) {
        $parts = explode(',', $base64_data);
        if (count($parts) === 2) {
            $data = base64_decode($parts[1]);
            if ($data !== false && strlen($data) > 0) {
                $ext = 'jpg';
                if (strpos($parts[0], 'png') !== false) {
                    $ext = 'png';
                } elseif (strpos($parts[0], 'webp') !== false) {
                    $ext = 'webp';
                }

                $new_filename = 'prod_' . uniqid() . '_' . time() . '.' . $ext;
                $target_path  = $upload_dir . $new_filename;

                if (file_put_contents($target_path, $data)) {
                    @chmod($target_path, 0664);

                    // Hapus foto lama jika berbeda
                    if (!empty($existing_photo) && file_exists(__DIR__ . '/../' . $existing_photo) && strpos($existing_photo, 'produk/') !== false) {
                        @unlink(__DIR__ . '/../' . $existing_photo);
                    }
                    return 'assets/images/produk/' . $new_filename;
                } else {
                    throw new Exception("Gagal menyimpan file gambar ke direktori server (periksa izin direktori produk).");
                }
            }
        }
    }

    // 2. Cek jika ada file gambar yang diunggah langsung (fallback server crop persegi 1:1 & kompresi)
    if (isset($file) && !empty($file['tmp_name'])) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new Exception("Ukuran gambar melebihi batas upload server.");
            }
            if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                throw new Exception("Gagal mengunggah gambar (kode status: " . $file['error'] . ").");
            }
        } else {
            $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
            $file_name = $file['name'];
            $file_tmp  = $file['tmp_name'];
            $file_size = $file['size'];

            $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts)) {
                throw new Exception("Format gambar harus JPG, JPEG, PNG, atau WEBP.");
            }

            $new_filename = 'prod_' . uniqid() . '_' . time() . '.jpg';
            $target_path  = $upload_dir . $new_filename;

            // Crop tengah persegi 1:1 dan kompresi JPEG kualitas 82 via GD
            $saved = false;
            if (function_exists('imagecreatefromstring')) {
                $raw_bytes = file_get_contents($file_tmp);
                $src_img = @imagecreatefromstring($raw_bytes);
                if ($src_img !== false) {
                    $sw = imagesx($src_img);
                    $sh = imagesy($src_img);
                    $side = min($sw, $sh);
                    $sx = (int)(($sw - $side) / 2);
                    $sy = (int)(($sh - $side) / 2);
                    $tsize = min($side, 600);
                    $dst_img = imagecreatetruecolor($tsize, $tsize);
                    imagecopyresampled($dst_img, $src_img, 0, 0, $sx, $sy, $tsize, $tsize, $side, $side);
                    $saved = imagejpeg($dst_img, $target_path, 82);
                    imagedestroy($src_img);
                    imagedestroy($dst_img);
                }
            }

            if (!$saved) {
                if (!move_uploaded_file($file_tmp, $target_path)) {
                    throw new Exception("Gagal menyimpan file gambar ke server.");
                }
            }

            @chmod($target_path, 0664);

            if (!empty($existing_photo) && file_exists(__DIR__ . '/../' . $existing_photo) && strpos($existing_photo, 'produk/') !== false) {
                @unlink(__DIR__ . '/../' . $existing_photo);
            }

            return 'assets/images/produk/' . $new_filename;
        }
    }

    return $existing_photo;
}

// ==========================================
// HANDLE OPERASI CRUD BARANG / PRODUK (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. TAMBAH BARANG
    if ($action === 'create') {
        $nama_produk   = trim($_POST['nama_produk'] ?? '');
        $barcode       = trim($_POST['barcode'] ?? '');
        $kategori_id   = !empty($_POST['kategori_id']) ? (int)$_POST['kategori_id'] : null;
        $satuan_id     = !empty($_POST['satuan_id']) ? (int)$_POST['satuan_id'] : null;
        $harga_raw     = trim($_POST['harga'] ?? '0');
        $stok_raw      = trim($_POST['stok'] ?? '0');
        $deskripsi     = trim($_POST['deskripsi'] ?? '');
        $foto_base64   = trim($_POST['foto_base64'] ?? '');

        if ($nama_produk === '') {
            $_SESSION['flash_error'] = 'Nama barang wajib diisi!';
        } elseif (!is_numeric($harga_raw) || (float)$harga_raw < 0) {
            $_SESSION['flash_error'] = 'Harga tidak boleh bernilai negatif!';
        } elseif (!is_numeric($stok_raw) || (int)$stok_raw < 0) {
            $_SESSION['flash_error'] = 'Stok tidak boleh bernilai negatif!';
        } else {
            // Cek duplikasi barcode jika diisi
            if ($barcode !== '') {
                $stmt_cek_b = $pdo->prepare("SELECT id, nama_produk FROM produk WHERE barcode = ? LIMIT 1");
                $stmt_cek_b->execute([$barcode]);
                $dup = $stmt_cek_b->fetch();
                if ($dup) {
                    $_SESSION['flash_error'] = "Barcode '{$barcode}' sudah dipakai oleh barang: {$dup['nama_produk']}";
                    header('Location: produk.php');
                    exit;
                }
            }

            $harga = (float)$harga_raw;
            $stok  = (int)$stok_raw;

            try {
                $hapus_foto = !empty($_POST['hapus_foto']) && $_POST['hapus_foto'] === '1';
                // Simpan foto dari kamera atau upload file
                $foto_path = handle_photo_upload_or_camera($_FILES['foto'] ?? null, $foto_base64, '', $hapus_foto);

                $stmt = $pdo->prepare("
                    INSERT INTO produk (kategori_id, satuan_id, nama_produk, barcode, harga, stok, deskripsi, foto, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$kategori_id, $satuan_id, $nama_produk, $barcode !== '' ? $barcode : null, $harga, $stok, $deskripsi, $foto_path]);
                $_SESSION['flash_success'] = "Barang '{$nama_produk}' berhasil ditambahkan!";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Gagal menambah barang: ' . $e->getMessage();
            }
        }
        header('Location: produk.php');
        exit;
    }

    // 2. UBAH BARANG (UPDATE)
    if ($action === 'update') {
        $id            = (int)($_POST['id'] ?? 0);
        $nama_produk   = trim($_POST['nama_produk'] ?? '');
        $barcode       = trim($_POST['barcode'] ?? '');
        $kategori_id   = !empty($_POST['kategori_id']) ? (int)$_POST['kategori_id'] : null;
        $satuan_id     = !empty($_POST['satuan_id']) ? (int)$_POST['satuan_id'] : null;
        $harga_raw     = trim($_POST['harga'] ?? '0');
        $stok_raw      = trim($_POST['stok'] ?? '0');
        $deskripsi     = trim($_POST['deskripsi'] ?? '');
        $foto_base64   = trim($_POST['foto_base64'] ?? '');

        if ($id <= 0 || $nama_produk === '') {
            $_SESSION['flash_error'] = 'Data barang tidak valid!';
        } elseif (!is_numeric($harga_raw) || (float)$harga_raw < 0) {
            $_SESSION['flash_error'] = 'Harga tidak boleh bernilai negatif!';
        } elseif (!is_numeric($stok_raw) || (int)$stok_raw < 0) {
            $_SESSION['flash_error'] = 'Stok tidak boleh bernilai negatif!';
        } else {
            // Cek duplikasi barcode selain barang ini
            if ($barcode !== '') {
                $stmt_cek_b = $pdo->prepare("SELECT id, nama_produk FROM produk WHERE barcode = ? AND id != ? LIMIT 1");
                $stmt_cek_b->execute([$barcode, $id]);
                $dup = $stmt_cek_b->fetch();
                if ($dup) {
                    $_SESSION['flash_error'] = "Barcode '{$barcode}' sudah dipakai oleh barang: {$dup['nama_produk']}";
                    header('Location: produk.php');
                    exit;
                }
            }

            $harga = (float)$harga_raw;
            $stok  = (int)$stok_raw;

            try {
                // Ambil info foto lama dari DB
                $stmt_old = $pdo->prepare("SELECT foto FROM produk WHERE id = ?");
                $stmt_old->execute([$id]);
                $old_data = $stmt_old->fetch();
                $foto_path = $old_data['foto'] ?? '';

                $hapus_foto = !empty($_POST['hapus_foto']) && $_POST['hapus_foto'] === '1';
                // Simpan foto dari kamera atau file baru
                $foto_path = handle_photo_upload_or_camera($_FILES['foto'] ?? null, $foto_base64, $foto_path, $hapus_foto);

                $stmt = $pdo->prepare("
                    UPDATE produk 
                    SET kategori_id = ?, satuan_id = ?, nama_produk = ?, barcode = ?, harga = ?, stok = ?, deskripsi = ?, foto = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$kategori_id, $satuan_id, $nama_produk, $barcode !== '' ? $barcode : null, $harga, $stok, $deskripsi, $foto_path, $id]);
                $_SESSION['flash_success'] = "Barang '{$nama_produk}' berhasil diperbarui!";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Gagal memperbarui barang: ' . $e->getMessage();
            }
        }
        header('Location: produk.php');
        exit;
    }

    // 3. HAPUS BARANG (DELETE)
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt_cek = $pdo->prepare("SELECT nama_produk, foto FROM produk WHERE id = ?");
                $stmt_cek->execute([$id]);
                $prod = $stmt_cek->fetch();

                if ($prod) {
                    // Hapus file foto jika ada
                    if (!empty($prod['foto']) && file_exists(__DIR__ . '/../' . $prod['foto']) && strpos($prod['foto'], 'produk/') !== false) {
                        @unlink(__DIR__ . '/../' . $prod['foto']);
                    }

                    $stmt_del = $pdo->prepare("DELETE FROM produk WHERE id = ?");
                    $stmt_del->execute([$id]);
                    $_SESSION['flash_success'] = "Barang '{$prod['nama_produk']}' berhasil dihapus.";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Database Error: ' . $e->getMessage();
            }
        }
        header('Location: produk.php');
        exit;
    }

    // 4. IMPORT BARANG DARI EXCEL (.XLSX)
    if ($action === 'import_excel') {
        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Silakan pilih file template Excel (.xlsx) yang valid untuk diunggah!';
        } else {
            $fileName = $_FILES['excel_file']['name'];
            $fileTmp  = $_FILES['excel_file']['tmp_name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if ($ext !== 'xlsx') {
                $_SESSION['flash_error'] = 'Format file tidak didukung! Pastikan mengunggah file berekstensi .xlsx.';
            } else {
                $updateExisting = !empty($_POST['update_existing']) && $_POST['update_existing'] === '1';
                try {
                    $stats = ProdukExcelManager::importFromXlsx($pdo, $fileTmp, ['update_existing' => $updateExisting]);

                    $msg = "Import Excel Berhasil! {$stats['inserted']} barang baru ditambahkan";
                    if ($stats['updated'] > 0) {
                        $msg .= ", {$stats['updated']} barang diperbarui";
                    }
                    if ($stats['skipped'] > 0) {
                        $msg .= ", {$stats['skipped']} barang dilewati (sudah terdaftar)";
                    }
                    if ($stats['images_saved'] > 0) {
                        $msg .= ", {$stats['images_saved']} foto berhasil diekstrak dan disimpan";
                    }
                    $msg .= ".";

                    if (!empty($stats['errors'])) {
                        $msg .= " Catatan (" . count($stats['errors']) . " kendala baris): " . implode('; ', array_slice($stats['errors'], 0, 2));
                    }

                    $_SESSION['flash_success'] = $msg;
                } catch (Exception $e) {
                    $_SESSION['flash_error'] = 'Gagal memproses file Excel: ' . $e->getMessage();
                }
            }
        }
        header('Location: produk.php');
        exit;
    }
}

// Filter Pencarian & Kategori
$search_nama    = trim($_GET['nama_barang'] ?? ($_GET['search'] ?? ''));
$search_barcode = trim($_GET['barcode'] ?? '');
$filter_kat     = trim($_GET['kat'] ?? '');

$sql = "
    SELECT p.*, k.nama_kategori, s.nama_satuan, s.kode_satuan 
    FROM produk p 
    LEFT JOIN kategori k ON p.kategori_id = k.id 
    LEFT JOIN satuan s ON p.satuan_id = s.id 
    WHERE 1=1
";
$params = [];

if ($search_nama !== '') {
    $sql .= " AND (p.nama_produk LIKE ? OR p.deskripsi LIKE ?)";
    $params[] = "%{$search_nama}%";
    $params[] = "%{$search_nama}%";
}

if ($search_barcode !== '') {
    $sql .= " AND p.barcode LIKE ?";
    $params[] = "%{$search_barcode}%";
}

if ($filter_kat !== '') {
    $sql .= " AND p.kategori_id = ?";
    $params[] = (int)$filter_kat;
}

$sql .= " ORDER BY p.nama_produk ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $produk_items = $stmt->fetchAll();
} catch (PDOException $e) {
    $produk_items = [];
    $error_msg = $e->getMessage();
}

// Ambil total semua produk (tanpa filter) & daftar nama produk untuk autocomplete datalist
$total_semua_produk = 0;
$all_product_names = [];
try {
    $total_semua_produk = (int)$pdo->query("SELECT COUNT(*) FROM produk")->fetchColumn();
    $all_product_names = $pdo->query("SELECT DISTINCT nama_produk FROM produk ORDER BY nama_produk ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $ex) {}

$admin_title = 'Manajemen Barang';
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold text-white mb-1">
            <i class="bi bi-box-seam text-info me-2"></i>Manajemen Barang & Inventori
        </h3>
        <p class="text-secondary mb-0">Kelola master data produk, foto kamera / upload, harga jual, dan stok barang kios.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="produk.php?action=download_template" class="btn btn-outline-success rounded-pill px-3 shadow-sm fw-semibold d-inline-flex align-items-center gap-2" title="Unduh template Excel (.xlsx) dengan dropdown pilihan kategori & satuan">
            <i class="bi bi-file-earmark-excel-fill text-success fs-5"></i>
            <span>Download Template (.xlsx)</span>
        </a>
        <button type="button" class="btn btn-outline-info rounded-pill px-3 shadow-sm fw-semibold d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalImportExcel" title="Upload template Excel yang telah diisi untuk menambah banyak barang sekaligus">
            <i class="bi bi-file-earmark-arrow-up-fill text-info fs-5"></i>
            <span>Import Excel</span>
        </button>
        <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalTambahProduk">
            <i class="bi bi-plus-lg"></i>
            <span>Tambah Barang</span>
        </button>
    </div>
</div>

<?php if (isset($error_msg)): ?>
    <div class="alert alert-danger shadow-sm"><?= e($error_msg) ?></div>
<?php endif; ?>

<!-- Baris Filter & Pencarian Nama Barang -->
<div class="card border-secondary p-3 mb-4 rounded-4 shadow-sm">
    <form action="produk.php" method="GET" id="formFilterProduk" class="row g-3 align-items-end">
        <!-- 1. Input Pencarian Berdasarkan Nama Barang -->
        <div class="col-md-5 col-lg-5">
            <label for="search_nama_produk" class="form-label small fw-bold mb-1">
                <i class="bi bi-search text-primary me-1"></i>Pencarian Berdasarkan Nama Barang:
            </label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search text-primary"></i></span>
                <input type="text" name="nama_barang" id="search_nama_produk" list="datalist_nama_produk" 
                       class="form-control" placeholder="Ketik nama barang (contoh: Air, Kopi, Roti)..." 
                       value="<?= e($search_nama) ?>" autocomplete="off" autofocus>
                <button class="btn btn-outline-secondary" type="button" id="btnClearSearchNama" title="Hapus teks pencarian">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <!-- Datalist Saran Nama Barang (Auto-Suggest) -->
            <datalist id="datalist_nama_produk">
                <?php foreach ($all_product_names as $pname): ?>
                    <option value="<?= e($pname) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>

        <!-- 2. Dropdown Filter Kategori -->
        <div class="col-md-3 col-lg-3">
            <label for="filter_kategori" class="form-label small fw-bold mb-1">
                <i class="bi bi-tags-fill text-info me-1"></i>Kategori Barang:
            </label>
            <select name="kat" id="filter_kategori" class="form-select">
                <option value="">Semua Kategori</option>
                <?php foreach ($list_kategori as $lk): ?>
                    <option value="<?= (int)$lk['id'] ?>" <?= ($filter_kat === (string)$lk['id']) ? 'selected' : '' ?>>
                        <?= e($lk['nama_kategori']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- 3. Pencarian / Scan Barcode -->
        <div class="col-md-4 col-lg-4">
            <label for="search_barcode" class="form-label small fw-bold mb-1">
                <i class="bi bi-upc-scan text-success me-1"></i>Barcode / Scanner USB:
            </label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-upc-scan text-success"></i></span>
                <input type="text" name="barcode" id="search_barcode" class="form-control font-monospace" 
                       placeholder="Scan barcode USB..." value="<?= e($search_barcode) ?>" autocomplete="off">
                <button type="submit" class="btn btn-primary px-3 fw-semibold">
                    <i class="bi bi-funnel-fill me-1"></i>Filter
                </button>
                <?php if ($search_nama !== '' || $search_barcode !== '' || $filter_kat !== ''): ?>
                    <a href="produk.php" class="btn btn-outline-secondary" title="Reset semua filter">Reset</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Baris Informasi Hasil & Status Scanner -->
        <div class="col-12 pt-2 border-top border-secondary border-opacity-25 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="small text-secondary" id="searchResultCountText">
                <i class="bi bi-box-seam me-1 text-primary"></i>Menampilkan <strong><span id="count_visible_prod"><?= count($produk_items) ?></span></strong> dari total <strong><?= $total_semua_produk ?></strong> barang
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge badge-status-active px-3 py-1 rounded-pill" title="Pencarian nama barang langsung menyaring tabel secara real-time saat Anda mengetik">
                    <i class="bi bi-lightning-charge-fill me-1"></i>Pencarian Real-Time Aktif
                </span>
                <span class="badge bg-secondary px-3 py-1 rounded-pill" title="Sistem siap mendeteksi scanner barcode USB kapan saja">
                    <i class="bi bi-usb-symbol me-1"></i>USB Scanner Siap
                </span>
            </div>
        </div>
    </form>
</div>

<!-- Tabel Daftar Produk -->
<div class="card border-secondary shadow rounded-4 overflow-hidden mb-5">
    <div class="table-responsive">
        <table class="table table-hover table-dark align-middle mb-0" id="table_daftar_produk">
            <thead class="table-secondary">
                <tr>
                    <th scope="col" style="width: 50px;" class="text-center">No</th>
                    <th scope="col" style="width: 65px;" class="text-center">Foto</th>
                    <th scope="col">Nama Barang</th>
                    <th scope="col">Kategori</th>
                    <th scope="col">Harga Jual</th>
                    <th scope="col" class="text-center">Stok</th>
                    <th scope="col">Deskripsi</th>
                    <th scope="col" class="text-center" style="width: 130px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($produk_items)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-secondary">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            Tidak ada barang ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($produk_items as $prod): 
                    ?>
                        <tr class="product-data-row" 
                            data-nama="<?= strtolower(e($prod['nama_produk'])) ?>" 
                            data-barcode="<?= strtolower(e($prod['barcode'] ?? '')) ?>" 
                            data-kategori="<?= (int)($prod['kategori_id'] ?? 0) ?>" 
                            id="prod_row_<?= (int)$prod['id'] ?>">
                            <td class="text-center text-secondary fw-semibold row-no-cell"><?= $no++ ?></td>
                            <td class="text-center">
                                <div class="border border-secondary rounded p-1 d-inline-flex align-items-center justify-content-center overflow-hidden" style="width: 44px; height: 44px; background-color: var(--bg-secondary);">
                                    <?php if (!empty($prod['foto']) && file_exists(__DIR__ . '/../' . $prod['foto'])): ?>
                                        <img src="../<?= e($prod['foto']) ?>?v=<?= filemtime(__DIR__ . '/../' . $prod['foto']) ?>" alt="Foto" style="max-height: 100%; max-width: 100%; object-fit: cover;" class="rounded">
                                    <?php else: ?>
                                        <img src="<?= e($logo_url_admin ?? '../assets/images/logo.png') ?>" alt="Icon" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong class="prod-name-title d-block mb-1"><?= e($prod['nama_produk']) ?></strong>
                                <?php if (!empty($prod['barcode'])): ?>
                                    <span class="badge badge-barcode font-monospace" title="Barcode: <?= e($prod['barcode']) ?>">
                                        <i class="bi bi-upc-scan me-1"></i><?= e($prod['barcode']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-no-barcode font-monospace" title="Produk tidak memiliki kode barcode">
                                        <i class="bi bi-upc me-1"></i>Tanpa Barcode
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-dark border border-secondary text-info">
                                    <?= e($prod['nama_kategori'] ?? 'Tanpa Kategori') ?>
                                </span>
                            </td>
                            <td class="fw-bold text-success font-monospace">
                                <?= rupiah($prod['harga']) ?>
                            </td>
                            <td class="text-center">
                                <?php 
                                $satuan_label = e($prod['kode_satuan'] ?? ($prod['nama_satuan'] ?? 'Pcs'));
                                if ((int)$prod['stok'] <= 0): ?>
                                    <span class="badge bg-danger rounded-pill px-2">Habis</span>
                                <?php elseif ((int)$prod['stok'] < 5): ?>
                                    <span class="badge bg-warning text-dark rounded-pill px-2 fw-semibold"><?= (int)$prod['stok'] ?> <?= $satuan_label ?></span>
                                <?php else: ?>
                                    <span class="badge bg-success bg-opacity-25 text-success rounded-pill px-2 fw-semibold"><?= (int)$prod['stok'] ?> <?= $satuan_label ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-secondary small text-truncate" style="max-width: 200px;">
                                <?= !empty($prod['deskripsi']) ? e($prod['deskripsi']) : '-' ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-info" title="Ubah Barang" onclick='openEditModal(<?= json_encode($prod, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" title="Hapus Barang" onclick="openDeleteModal(<?= (int)$prod['id'] ?>, '<?= e(addslashes($prod['nama_produk'])) ?>')">
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
<!-- MODAL 1: TAMBAH PRODUK BARU -->
<!-- ======================================================== -->
<div class="modal fade" id="modalTambahProduk" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">
            <div class="modal-header border-secondary bg-primary bg-opacity-25">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Tambah Barang Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="closeCamera('create')"></button>
            </div>
            <form action="produk.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create">
                <!-- Data Base64 jika diambil dari kamera -->
                <input type="hidden" name="foto_base64" id="create_foto_base64" value="">
                <input type="hidden" name="hapus_foto" id="create_hapus_foto" value="0">

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <div class="mb-3">
                                <label for="create_nama" class="form-label small fw-semibold">Nama Barang <span class="text-danger">*</span></label>
                                <input type="text" class="form-control bg-dark border-secondary text-white" id="create_nama" name="nama_produk" placeholder="Contoh: Kopi Susu Aren" required>
                            </div>

                            <div class="mb-3">
                                <label for="create_barcode" class="form-label small fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-upc-scan text-info me-1"></i>Kode Barcode (USB Scanner / Ketik)</span>
                                    <span class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-50" style="font-size: 0.7rem;">
                                        <i class="bi bi-usb-symbol me-1"></i>Deteksi USB Auto
                                    </span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-dark border-secondary text-info"><i class="bi bi-upc"></i></span>
                                    <input type="text" class="form-control bg-dark border-secondary text-white font-monospace" id="create_barcode" name="barcode" placeholder="Scan barcode USB atau ketik manual...">
                                    <button type="button" class="btn btn-outline-secondary" onclick="generateAutoBarcode('create')" title="Buat Barcode Otomatis">
                                        <i class="bi bi-magic me-1"></i>Auto
                                    </button>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label for="create_kat" class="form-label small fw-semibold">Kategori Barang</label>
                                    <select class="form-select bg-dark border-secondary text-white" id="create_kat" name="kategori_id">
                                        <option value="">-- Pilih Kategori --</option>
                                        <?php foreach ($list_kategori as $lk): ?>
                                            <option value="<?= (int)$lk['id'] ?>"><?= e($lk['nama_kategori']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label for="create_satuan" class="form-label small fw-semibold">Satuan Barang <span class="text-danger">*</span></label>
                                    <select class="form-select bg-dark border-secondary text-white" id="create_satuan" name="satuan_id" required>
                                        <?php foreach ($list_satuan as $ls): ?>
                                            <option value="<?= (int)$ls['id'] ?>" <?= ($ls['kode_satuan'] === 'Pcs') ? 'selected' : '' ?>>
                                                <?= e($ls['nama_satuan']) ?> (<?= e($ls['kode_satuan']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label for="create_harga" class="form-label small fw-semibold">Harga Jual (Rp) <span class="text-danger">*</span></label>
                                    <input type="number" step="100" min="0" class="form-control bg-dark border-secondary text-white font-monospace" id="create_harga" name="harga" placeholder="0" required>
                                </div>
                                <div class="col-6">
                                    <label for="create_stok" class="form-label small fw-semibold">Stok Awal <span class="text-danger">*</span></label>
                                    <input type="number" min="0" class="form-control bg-dark border-secondary text-white font-monospace" id="create_stok" name="stok" placeholder="0" required>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label for="create_deskripsi" class="form-label small fw-semibold">Deskripsi Singkat</label>
                                <textarea class="form-control bg-dark border-secondary text-white" id="create_deskripsi" name="deskripsi" rows="2" placeholder="Detail produk..."></textarea>
                            </div>
                        </div>

                                                <!-- KOLOM FOTO / KAMERA (CREATE) -->
                        <div class="col-md-5">
                            <div class="card border-secondary p-3 rounded-4 h-100" style="background-color: var(--bg-secondary);">
                                <label class="form-label small fw-bold text-info mb-2 d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-image me-1"></i> Foto Barang</span>
                                    <span class="badge bg-dark border border-secondary text-info font-monospace" style="font-size: 0.68rem;">Format 1:1 Persegi</span>
                                </label>

                                <!-- Pilihan Sumber Foto: Upload File atau Kamera -->
                                <div class="d-flex gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="openCamera('create')">
                                        <i class="bi bi-camera-fill me-1"></i> Buka Kamera
                                    </button>
                                    <label class="btn btn-sm btn-outline-secondary flex-fill text-nowrap mb-0" for="create_foto">
                                        <i class="bi bi-folder2-open me-1"></i> Pilih File
                                    </label>

                                </div>

                                <!-- Banner Notifikasi HTTPS jika diakses via HTTP -->
                                <div class="https-camera-tip alert alert-info py-1 px-2 small mb-2 d-none" style="font-size: 0.72rem;">
                                    <i class="bi bi-shield-lock me-1"></i> Live kamera di browser butuh HTTPS. <a href="#" class="text-white fw-bold text-decoration-underline link-switch-https">Buka via HTTPS</a>
                                </div>

                                <!-- Input File Tersembunyi (capture camera mobile) -->
                                <input type="file" class="d-none" id="create_foto" name="foto" accept="image/png, image/jpeg, image/jpg, image/webp" capture="environment" onchange="previewUpload(this, 'create')">

                                <!-- Area Live Streaming Kamera dengan Kotak Pembidik Persegi 1:1 -->
                                <div id="create_camera_box" class="position-relative mb-2 rounded-4 overflow-hidden border border-info shadow" style="display: none; background: #000; width: 100%; aspect-ratio: 1 / 1; max-width: 290px; margin: 0 auto;">
                                    <video id="create_video" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>
                                    
                                    <!-- Kotak Pembidik Persegi 1:1 Pas Target -->
                                    <div class="position-absolute top-0 start-0 w-100 h-100 d-flex flex-column align-items-center justify-content-between p-2" style="pointer-events: none; z-index: 5;">
                                        <span class="badge bg-dark bg-opacity-75 text-info border border-info border-opacity-50 px-2 py-1 small rounded-pill shadow-sm mt-1" style="font-size: 0.68rem;">
                                            <i class="bi bi-aspect-ratio me-1"></i> Area Kotak Foto (1:1)
                                        </span>
                                        
                                        <!-- Frame Persegi (Target Viewfinder) -->
                                        <div class="position-relative border border-2 border-info rounded-3" style="width: 80%; height: 80%; box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.45);">
                                            <!-- Sudut-Sudut Reticle Presisi -->
                                            <span class="position-absolute top-0 start-0 border-top border-start border-3 border-white rounded-top" style="width: 16px; height: 16px; margin: -2px 0 0 -2px;"></span>
                                            <span class="position-absolute top-0 end-0 border-top border-end border-3 border-white rounded-top" style="width: 16px; height: 16px; margin: -2px -2px 0 0;"></span>
                                            <span class="position-absolute bottom-0 start-0 border-bottom border-start border-3 border-white rounded-bottom" style="width: 16px; height: 16px; margin: 0 0 -2px -2px;"></span>
                                            <span class="position-absolute bottom-0 end-0 border-bottom border-end border-3 border-white rounded-bottom" style="width: 16px; height: 16px; margin: 0 -2px -2px 0;"></span>
                                            <!-- Titik Fokus Tengah -->
                                            <span class="position-absolute top-50 start-50 translate-middle bg-info rounded-circle opacity-75 shadow-sm" style="width: 6px; height: 6px;"></span>
                                        </div>

                                        <small class="text-white text-center mb-5 fw-semibold" style="font-size: 0.68rem; text-shadow: 0 1px 3px rgba(0,0,0,0.9);">
                                            Posisikan barang pas di dalam kotak persegi
                                        </small>
                                    </div>

                                    <!-- Tombol Jepret di Bagian Bawah Kamera -->
                                    <div class="position-absolute bottom-0 start-0 w-100 p-2 d-flex justify-content-center gap-2 bg-dark bg-opacity-75" style="z-index: 10;">
                                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3 fw-bold shadow-sm" onclick="snapPhoto('create')">
                                            <i class="bi bi-camera-fill me-1"></i> Jepret Pas Kotak
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-light rounded-circle p-1" style="width: 32px; height: 32px;" onclick="switchCamera('create')" title="Balik Kamera">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-circle p-1" style="width: 32px; height: 32px;" onclick="closeCamera('create')" title="Tutup Kamera">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Kotak Pratinjau Foto Persegi 1:1 -->
                                <div id="create_preview_box" class="text-center p-3 border border-secondary rounded-4 bg-dark flex-grow-1 d-flex flex-column align-items-center justify-content-center" style="min-height: 180px;">
                                    <div class="position-relative d-inline-block mb-2">
                                        <img id="create_preview_img" src="<?= e($logo_url_admin ?? '../assets/images/logo.png') ?>" alt="Pratinjau" style="width: 140px; height: 140px; aspect-ratio: 1 / 1; object-fit: cover; border: 2px solid #334155;" class="rounded-3 shadow">
                                        <span class="badge bg-dark text-info border border-secondary position-absolute bottom-0 end-0 m-1" style="font-size: 0.65rem;">
                                            1:1 Persegi
                                        </span>
                                    </div>
                                    <small id="create_photo_status" class="text-muted d-block" style="font-size: 0.75rem;">Logo default (Belum ada foto)</small>
                                </div>
                            </div>
                        </div></div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary rounded-pill px-3" data-bs-dismiss="modal" onclick="closeCamera('create')">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold">Simpan Barang</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 2: UBAH (EDIT) PRODUK -->
<!-- ======================================================== -->
<div class="modal fade" id="modalEditProduk" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">
            <div class="modal-header border-secondary bg-info bg-opacity-25">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Ubah Data Barang</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="closeCamera('edit')"></button>
            </div>
            <form action="produk.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <!-- Data Base64 jika diambil dari kamera -->
                <input type="hidden" name="foto_base64" id="edit_foto_base64" value="">
                <input type="hidden" name="hapus_foto" id="edit_hapus_foto" value="0">

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <div class="mb-3">
                                <label for="edit_nama" class="form-label small fw-semibold">Nama Barang <span class="text-danger">*</span></label>
                                <input type="text" class="form-control bg-dark border-secondary text-white" id="edit_nama" name="nama_produk" required>
                            </div>

                            <div class="mb-3">
                                <label for="edit_barcode" class="form-label small fw-semibold d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-upc-scan text-info me-1"></i>Kode Barcode (USB Scanner / Ketik)</span>
                                    <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-50" style="font-size: 0.7rem;">
                                        <i class="bi bi-usb-symbol me-1"></i>Deteksi USB Auto
                                    </span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-dark border-secondary text-info"><i class="bi bi-upc"></i></span>
                                    <input type="text" class="form-control bg-dark border-secondary text-white font-monospace" id="edit_barcode" name="barcode" placeholder="Scan barcode USB atau ketik manual...">
                                    <button type="button" class="btn btn-outline-secondary" onclick="generateAutoBarcode('edit')" title="Buat Barcode Otomatis">
                                        <i class="bi bi-magic me-1"></i>Auto
                                    </button>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label for="edit_kat" class="form-label small fw-semibold">Kategori Barang</label>
                                    <select class="form-select bg-dark border-secondary text-white" id="edit_kat" name="kategori_id">
                                        <option value="">-- Tanpa Kategori --</option>
                                        <?php foreach ($list_kategori as $lk): ?>
                                            <option value="<?= (int)$lk['id'] ?>"><?= e($lk['nama_kategori']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label for="edit_satuan" class="form-label small fw-semibold">Satuan Barang <span class="text-danger">*</span></label>
                                    <select class="form-select bg-dark border-secondary text-white" id="edit_satuan" name="satuan_id" required>
                                        <?php foreach ($list_satuan as $ls): ?>
                                            <option value="<?= (int)$ls['id'] ?>">
                                                <?= e($ls['nama_satuan']) ?> (<?= e($ls['kode_satuan']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label for="edit_harga" class="form-label small fw-semibold">Harga Jual (Rp) <span class="text-danger">*</span></label>
                                    <input type="number" step="100" min="0" class="form-control bg-dark border-secondary text-white font-monospace" id="edit_harga" name="harga" required>
                                </div>
                                <div class="col-6">
                                    <label for="edit_stok" class="form-label small fw-semibold">Jumlah Stok <span class="text-danger">*</span></label>
                                    <input type="number" min="0" class="form-control bg-dark border-secondary text-white font-monospace" id="edit_stok" name="stok" required>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label for="edit_deskripsi" class="form-label small fw-semibold">Deskripsi</label>
                                <textarea class="form-control bg-dark border-secondary text-white" id="edit_deskripsi" name="deskripsi" rows="2"></textarea>
                            </div>
                        </div>

                                                <!-- KOLOM FOTO / KAMERA (EDIT) -->
                        <div class="col-md-5">
                            <div class="card border-secondary p-3 rounded-4 h-100" style="background-color: var(--bg-secondary);">
                                <label class="form-label small fw-bold text-info mb-2 d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-image me-1"></i> Ganti Foto Barang</span>
                                    <span class="badge bg-dark border border-secondary text-info font-monospace" style="font-size: 0.68rem;">Format 1:1 Persegi</span>
                                </label>

                                <!-- Pilihan Sumber Foto: Upload File atau Kamera -->
                                <div class="d-flex gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="openCamera('edit')">
                                        <i class="bi bi-camera-fill me-1"></i> Buka Kamera
                                    </button>
                                    <label class="btn btn-sm btn-outline-secondary flex-fill text-nowrap mb-0" for="edit_foto">
                                        <i class="bi bi-folder2-open me-1"></i> Pilih File
                                    </label>
                                    <button type="button" class="btn btn-sm btn-outline-danger d-none" id="edit_btn_hapus_foto" onclick="hapusFotoEdit()" title="Hapus foto produk dan gunakan logo default">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>

                                <!-- Banner Notifikasi HTTPS jika diakses via HTTP -->
                                <div class="https-camera-tip alert alert-info py-1 px-2 small mb-2 d-none" style="font-size: 0.72rem;">
                                    <i class="bi bi-shield-lock me-1"></i> Live kamera di browser butuh HTTPS. <a href="#" class="text-white fw-bold text-decoration-underline link-switch-https">Buka via HTTPS</a>
                                </div>

                                <!-- Input File Tersembunyi (capture camera mobile) -->
                                <input type="file" class="d-none" id="edit_foto" name="foto" accept="image/png, image/jpeg, image/jpg, image/webp" capture="environment" onchange="previewUpload(this, 'edit')">

                                <!-- Area Live Streaming Kamera dengan Kotak Pembidik Persegi 1:1 -->
                                <div id="edit_camera_box" class="position-relative mb-2 rounded-4 overflow-hidden border border-info shadow" style="display: none; background: #000; width: 100%; aspect-ratio: 1 / 1; max-width: 290px; margin: 0 auto;">
                                    <video id="edit_video" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>
                                    
                                    <!-- Kotak Pembidik Persegi 1:1 Pas Target -->
                                    <div class="position-absolute top-0 start-0 w-100 h-100 d-flex flex-column align-items-center justify-content-between p-2" style="pointer-events: none; z-index: 5;">
                                        <span class="badge bg-dark bg-opacity-75 text-info border border-info border-opacity-50 px-2 py-1 small rounded-pill shadow-sm mt-1" style="font-size: 0.68rem;">
                                            <i class="bi bi-aspect-ratio me-1"></i> Area Kotak Foto (1:1)
                                        </span>
                                        
                                        <!-- Frame Persegi (Target Viewfinder) -->
                                        <div class="position-relative border border-2 border-info rounded-3" style="width: 80%; height: 80%; box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.45);">
                                            <!-- Sudut-Sudut Reticle Presisi -->
                                            <span class="position-absolute top-0 start-0 border-top border-start border-3 border-white rounded-top" style="width: 16px; height: 16px; margin: -2px 0 0 -2px;"></span>
                                            <span class="position-absolute top-0 end-0 border-top border-end border-3 border-white rounded-top" style="width: 16px; height: 16px; margin: -2px -2px 0 0;"></span>
                                            <span class="position-absolute bottom-0 start-0 border-bottom border-start border-3 border-white rounded-bottom" style="width: 16px; height: 16px; margin: 0 0 -2px -2px;"></span>
                                            <span class="position-absolute bottom-0 end-0 border-bottom border-end border-3 border-white rounded-bottom" style="width: 16px; height: 16px; margin: 0 -2px -2px 0;"></span>
                                            <!-- Titik Fokus Tengah -->
                                            <span class="position-absolute top-50 start-50 translate-middle bg-info rounded-circle opacity-75 shadow-sm" style="width: 6px; height: 6px;"></span>
                                        </div>

                                        <small class="text-white text-center mb-5 fw-semibold" style="font-size: 0.68rem; text-shadow: 0 1px 3px rgba(0,0,0,0.9);">
                                            Posisikan barang pas di dalam kotak persegi
                                        </small>
                                    </div>

                                    <!-- Tombol Jepret di Bagian Bawah Kamera -->
                                    <div class="position-absolute bottom-0 start-0 w-100 p-2 d-flex justify-content-center gap-2 bg-dark bg-opacity-75" style="z-index: 10;">
                                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3 fw-bold shadow-sm" onclick="snapPhoto('edit')">
                                            <i class="bi bi-camera-fill me-1"></i> Jepret Pas Kotak
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-light rounded-circle p-1" style="width: 32px; height: 32px;" onclick="switchCamera('edit')" title="Balik Kamera">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-circle p-1" style="width: 32px; height: 32px;" onclick="closeCamera('edit')" title="Tutup Kamera">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Kotak Pratinjau Foto Persegi 1:1 -->
                                <div id="edit_preview_box" class="text-center p-3 border border-secondary rounded-4 bg-dark flex-grow-1 d-flex flex-column align-items-center justify-content-center" style="min-height: 180px;">
                                    <div class="position-relative d-inline-block mb-2">
                                        <img id="edit_preview_img" src="<?= e($logo_url_admin ?? '../assets/images/logo.png') ?>" alt="Pratinjau" style="width: 140px; height: 140px; aspect-ratio: 1 / 1; object-fit: cover; border: 2px solid #334155;" class="rounded-3 shadow">
                                        <span class="badge bg-dark text-info border border-secondary position-absolute bottom-0 end-0 m-1" style="font-size: 0.65rem;">
                                            1:1 Persegi
                                        </span>
                                    </div>
                                    <small id="edit_photo_status" class="text-muted d-block" style="font-size: 0.75rem;">Logo default (Belum ada foto)</small>
                                </div>
                            </div>
                        </div></div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary rounded-pill px-3" data-bs-dismiss="modal" onclick="closeCamera('edit')">Batal</button>
                    <button type="submit" class="btn btn-info rounded-pill px-4 fw-bold">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 3: HAPUS PRODUK -->
<!-- ======================================================== -->
<div class="modal fade" id="modalHapusProduk" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4 text-center p-3">
            <div class="modal-body">
                <div class="text-danger mb-3">
                    <i class="bi bi-trash3 display-4"></i>
                </div>
                <h5 class="fw-bold">Hapus Barang?</h5>
                <p class="text-secondary small mb-3">Hapus barang <strong id="hapus_nama_label" class="text-white"></strong>?</p>
                <form action="produk.php" method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="hapus_id">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger btn-sm rounded-pill px-3">Ya, Hapus</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 4: IMPORT PRODUK MASSAL VIA EXCEL (.XLSX) -->
<!-- ======================================================== -->
<div class="modal fade" id="modalImportExcel" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">
            <div class="modal-header border-secondary bg-success bg-opacity-25">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-file-earmark-excel me-2 text-success"></i>Import Barang Massal (.xlsx)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="produk.php" method="POST" enctype="multipart/form-data" id="formImportExcel" onsubmit="document.getElementById('btnSubmitImport').innerHTML = '<span class=\'spinner-border spinner-border-sm me-2\'></span>Memproses...'; document.getElementById('btnSubmitImport').disabled = true;">
                <input type="hidden" name="action" value="import_excel">

                <div class="modal-body p-4">
                    <!-- Petunjuk & Panduan Singkat -->
                    <div class="card bg-secondary bg-opacity-10 border border-secondary border-opacity-50 p-3 rounded-3 mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                            <h6 class="fw-bold text-info mb-0">
                                <i class="bi bi-info-circle me-1"></i> Format &amp; Panduan Pengisian Template
                            </h6>
                            <a href="produk.php?action=download_template" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-semibold">
                                <i class="bi bi-download me-1"></i> Download Template (.xlsx)
                            </a>
                        </div>
                        <p class="small text-secondary mb-2">
                            Gunakan template resmi agar seluruh kolom dan dropdown pilihan terbaca akurat:
                        </p>
                        <ul class="small text-secondary ps-3 mb-0">
                            <li><strong>Nama Barang</strong>: Wajib diisi (nama item produk).</li>
                            <li><strong>Barcode</strong>: Opsional, bisa diisi hasil scan barcode atau ketik manual.</li>
                            <li><strong>Kategori</strong>: Klik sel untuk memilih kategori dari <em>dropdown list</em> bawaan Excel. (Jika Anda mengetik kategori baru, sistem otomatis mendaftarkannya).</li>
                            <li><strong>Satuan Barang</strong>: Klik sel untuk memilih satuan dari <em>dropdown list</em> bawaan Excel (Pcs, Btl, Bks, Dus, Pak, Cup, Kg, dll).</li>
                            <li><strong>Harga Jual &amp; Stok Awal</strong>: Isi angka tanpa tanda titik/koma (contoh: <code>15000</code>).</li>
                            <li><strong>Foto Produk</strong>: Anda dapat <u>menyisipkan gambar langsung</u> (<em>Insert &gt; Picture</em>) ke dalam sel kolom Foto di Excel! Sistem QiOS akan otomatis mengekstrak foto, mengoptimalkan ukuran gambar (persegi 600px), dan menyimpannya ke aplikasi.</li>
                        </ul>
                    </div>

                    <!-- Pilih File Excel -->
                    <div class="mb-4">
                        <label for="excel_file_input" class="form-label fw-semibold">
                            Pilih File Excel (.xlsx) yang Sudah Diisi <span class="text-danger">*</span>
                        </label>
                        <input type="file" name="excel_file" id="excel_file_input" class="form-control form-control-lg bg-dark border-secondary text-white" accept=".xlsx, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                        <div class="form-text text-secondary">
                            Mendukung file format <code>.xlsx</code> (Microsoft Excel 2007+, WPS Office, LibreOffice Calc, Google Sheets).
                        </div>
                    </div>

                    <!-- Opsi Timpa / Update Barang -->
                    <div class="card bg-dark border border-secondary p-3 rounded-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="update_existing" value="1" id="check_update_existing" checked>
                            <label class="form-check-label text-white fw-semibold" for="check_update_existing">
                                Perbarui data jika barcode atau nama barang sudah terdaftar
                            </label>
                            <div class="small text-secondary mt-1">
                                Jika dicentang: harga jual, stok, kategori, satuan, dan foto barang yang sudah ada akan diperbarui mengikuti data di Excel. Jika tidak dicentang, barang yang sudah ada akan dilewati.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold" id="btnSubmitImport">
                        <i class="bi bi-cloud-arrow-up-fill me-1"></i> Mulai Import Data
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- CANVAS TERSEMBUNYI UNTUK SNAPSHOT KAMERA -->
<canvas id="cameraCanvas" style="display: none;"></canvas>

<script>
// ==========================================
// PENGELOLA KAMERA (WebRTC & Mobile Camera)
// ==========================================
let activeStreams = { create: null, edit: null };
let currentFacingMode = { create: 'environment', edit: 'environment' };

// Buka Kamera
async function openCamera(prefix) {
    closeCamera(prefix); // Tutup stream lama jika ada
    const camBox = document.getElementById(prefix + '_camera_box');
    const previewBox = document.getElementById(prefix + '_preview_box');
    const video = document.getElementById(prefix + '_video');
    const fileInput = document.getElementById(prefix + '_foto');

    // Jika browser membatasi getUserMedia karena berjalan di HTTP (Non-HTTPS)
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        if (window.location.protocol !== 'https:') {
            const switchHttps = confirm("Browser Chrome/Edge membatasi akses video kamera langsung jika melalui HTTP biasa.\n\nKlik 'OK' untuk beralih ke mode HTTPS aman sekarang, atau klik 'Cancel' untuk membuka kamera bawaan perangkat.");
            if (switchHttps) {
                window.location.href = 'https://' + window.location.host + window.location.pathname;
                return;
            }
        }
        // Fallback otomatis: Buka kamera bawaan perangkat via native HTML5 capture
        if (fileInput) {
            fileInput.click();
        }
        return;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: currentFacingMode[prefix],
                width: { ideal: 1280 },
                height: { ideal: 720 }
            },
            audio: false
        });

        activeStreams[prefix] = stream;
        video.srcObject = stream;
        camBox.style.display = 'block';
        if (previewBox) previewBox.style.display = 'none';

    } catch (err) {
        console.warn('WebRTC getUserMedia Error:', err);
        // Jika izin kamera WebRTC diblokir, buka via kamera native perangkat
        if (fileInput) {
            fileInput.click();
        }
    }
}

// Tutup Kamera
function closeCamera(prefix) {
    if (activeStreams[prefix]) {
        activeStreams[prefix].getTracks().forEach(track => track.stop());
        activeStreams[prefix] = null;
    }
    const camBox = document.getElementById(prefix + '_camera_box');
    const previewBox = document.getElementById(prefix + '_preview_box');
    if (camBox) camBox.style.display = 'none';
    if (previewBox) previewBox.style.display = 'flex';
}

// Switch Kamera Depan / Belakang
function switchCamera(prefix) {
    currentFacingMode[prefix] = (currentFacingMode[prefix] === 'environment') ? 'user' : 'environment';
    openCamera(prefix);
}

// Jepret Foto dari Video Stream (Dipangkas Pas Kotak Persegi 1:1 & Dikompresi Ringan)
function snapPhoto(prefix) {
    const video = document.getElementById(prefix + '_video');
    const previewImg = document.getElementById(prefix + '_preview_img');
    const base64Input = document.getElementById(prefix + '_foto_base64');
    const fileInput = document.getElementById(prefix + '_foto');
    const statusText = document.getElementById(prefix + '_photo_status');
    const hapusInput = document.getElementById(prefix + '_hapus_foto');

    if (!video || !video.videoWidth) {
        alert('Kamera belum siap, tunggu sebentar...');
        return;
    }

    const vw = video.videoWidth;
    const vh = video.videoHeight;
    const minDim = Math.min(vw, vh);

    // Frame kotak pembidik di UI mengambil 80% dari area tengah persegi video
    const frameRatio = 0.80;
    const cropSize = minDim * frameRatio;
    const sx = (vw - cropSize) / 2;
    const sy = (vh - cropSize) / 2;

    // Target resolusi standar produk 600x600 px (ringan ~35-50 KB, sangat tajam & presisi 1:1)
    const targetSize = 600;
    const canvas = document.createElement('canvas');
    canvas.width = targetSize;
    canvas.height = targetSize;
    const ctx = canvas.getContext('2d');

    // Potong tepat di area kotak persegi
    ctx.drawImage(video, sx, sy, cropSize, cropSize, 0, 0, targetSize, targetSize);

    // Kompresi JPEG kualitas 0.82
    const dataUrl = canvas.toDataURL('image/jpeg', 0.82);

    if (base64Input) base64Input.value = dataUrl;
    if (previewImg) previewImg.src = dataUrl;
    if (fileInput) fileInput.value = '';
    if (hapusInput) hapusInput.value = '0';

    if (prefix === 'edit') {
        const btnHapus = document.getElementById('edit_btn_hapus_foto');
        if (btnHapus) btnHapus.classList.remove('d-none');
    }

    if (statusText) {
        const approxKb = Math.round((dataUrl.length * 3 / 4) / 1024);
        statusText.innerHTML = `<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i> Foto kotak persegi siap (${targetSize}x${targetSize}px, ~${approxKb} KB)</span>`;
    }

    closeCamera(prefix);
}

// Pratinjau saat memilih file dari Storage (Otomatis Crop Tengah Persegi 1:1 & Kompresi)
function previewUpload(input, prefix) {
    closeCamera(prefix);
    const img = document.getElementById(prefix + '_preview_img');
    const base64Input = document.getElementById(prefix + '_foto_base64');
    const statusText = document.getElementById(prefix + '_photo_status');
    const hapusInput = document.getElementById(prefix + '_hapus_foto');

    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    if (statusText) {
        statusText.innerHTML = '<span class="text-warning"><i class="bi bi-hourglass-split me-1"></i> Memotong persegi & mengompresi gambar...</span>';
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        const tempImg = new Image();
        tempImg.onload = function() {
            const iw = tempImg.naturalWidth || tempImg.width;
            const ih = tempImg.naturalHeight || tempImg.height;
            const side = Math.min(iw, ih);
            const sx = (iw - side) / 2;
            const sy = (ih - side) / 2;

            // Target resolusi 600x600 px persegi
            const targetSize = Math.min(side, 600);
            const canvas = document.createElement('canvas');
            canvas.width = targetSize;
            canvas.height = targetSize;
            const ctx = canvas.getContext('2d');

            // Potong tengah pas kotak persegi 1:1
            ctx.drawImage(tempImg, sx, sy, side, side, 0, 0, targetSize, targetSize);

            // Kompresi JPEG kualitas 0.82
            const compressedDataUrl = canvas.toDataURL('image/jpeg', 0.82);

            if (base64Input) base64Input.value = compressedDataUrl;
            if (img) img.src = compressedDataUrl;
            if (hapusInput) hapusInput.value = '0';

            if (prefix === 'edit') {
                const btnHapus = document.getElementById('edit_btn_hapus_foto');
                if (btnHapus) btnHapus.classList.remove('d-none');
            }

            if (statusText) {
                const approxKb = Math.round((compressedDataUrl.length * 3 / 4) / 1024);
                statusText.innerHTML = `<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i> Foto dipotong persegi 1:1 (${targetSize}x${targetSize}px, ~${approxKb} KB)</span>`;
            }
        };
        tempImg.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

// ==========================================
// MODAL HANDLERS
// ==========================================
function openEditModal(prod) {
    closeCamera('edit');
    document.getElementById('edit_id').value = prod.id;
    document.getElementById('edit_nama').value = prod.nama_produk;
    document.getElementById('edit_barcode').value = prod.barcode || '';
    document.getElementById('edit_kat').value = prod.kategori_id || '';
    document.getElementById('edit_satuan').value = prod.satuan_id || '';
    document.getElementById('edit_harga').value = Math.floor(prod.harga);
    document.getElementById('edit_stok').value = prod.stok;
    document.getElementById('edit_deskripsi').value = prod.deskripsi || '';
    
    // Reset inputs
    document.getElementById('edit_foto').value = '';
    document.getElementById('edit_foto_base64').value = '';
    const hapusInput = document.getElementById('edit_hapus_foto');
    if (hapusInput) hapusInput.value = '0';

    // Tampilkan foto barang saat ini dengan cache-buster timestamp
    const editImg = document.getElementById('edit_preview_img');
    const statusText = document.getElementById('edit_photo_status');
    const btnHapus = document.getElementById('edit_btn_hapus_foto');

    if (prod.foto && prod.foto !== '') {
        editImg.src = '../' + prod.foto + '?v=' + Date.now();
        statusText.innerHTML = '<span class="text-white-50"><i class="bi bi-image me-1"></i> Foto aktif saat ini</span>';
        if (btnHapus) btnHapus.classList.remove('d-none');
    } else {
        editImg.src = <?= json_encode($logo_url_admin ?? '../assets/images/logo.png') ?>;
        statusText.innerHTML = '<span class="text-muted"><i class="bi bi-info-circle me-1"></i> Logo default (Belum ada foto)</span>';
        if (btnHapus) btnHapus.classList.add('d-none');
    }

    new bootstrap.Modal(document.getElementById('modalEditProduk')).show();
}

function hapusFotoEdit() {
    closeCamera('edit');
    document.getElementById('edit_foto').value = '';
    document.getElementById('edit_foto_base64').value = '';
    const hapusInput = document.getElementById('edit_hapus_foto');
    if (hapusInput) hapusInput.value = '1';

    const editImg = document.getElementById('edit_preview_img');
    const statusText = document.getElementById('edit_photo_status');
    const btnHapus = document.getElementById('edit_btn_hapus_foto');

    editImg.src = <?= json_encode($logo_url_admin ?? '../assets/images/logo.png') ?>;
    if (statusText) {
        statusText.innerHTML = '<span class="text-danger fw-bold"><i class="bi bi-trash me-1"></i> Foto akan dihapus saat disimpan</span>';
    }
    if (btnHapus) btnHapus.classList.add('d-none');
}

function openDeleteModal(id, nama) {
    document.getElementById('hapus_id').value = id;
    document.getElementById('hapus_nama_label').innerText = nama;
    new bootstrap.Modal(document.getElementById('modalHapusProduk')).show();
}

// ==========================================
// DETEKTOR BARCODE SCANNER USB (HID KEYBOARD)
// ==========================================
let barcodeBuffer = '';
let lastKeyTime = 0;
let audioCtx = null;

function playBarcodeBeep() {
    try {
        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === 'suspended') audioCtx.resume();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(1400, audioCtx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(1900, audioCtx.currentTime + 0.08);
        gain.gain.setValueAtTime(0.12, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.1);
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.1);
    } catch (_) {}
}

function showBarcodeToast(message, isSuccess = true) {
    let toast = document.getElementById('barcodeScanToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'barcodeScanToast';
        toast.style.cssText = 'position:fixed;top:80px;right:25px;z-index:9999;padding:12px 20px;border-radius:12px;font-weight:bold;font-size:13px;box-shadow:0 8px 25px rgba(0,0,0,0.5);transition:all 0.3s ease;display:flex;align-items:center;gap:8px;';
        document.body.appendChild(toast);
    }
    toast.style.background = isSuccess ? '#065f46' : '#991b1b';
    toast.style.color = '#fff';
    toast.style.border = isSuccess ? '1px solid #10b981' : '1px solid #ef4444';
    toast.innerHTML = `<i class="bi bi-upc-scan fs-5"></i> <span>${message}</span>`;
    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0)';
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px)';
    }, 2800);
}

function generateAutoBarcode(prefix) {
    const input = document.getElementById(prefix + '_barcode');
    if (input) {
        const rand = '899' + Date.now().toString().slice(-7) + Math.floor(100 + Math.random() * 900);
        input.value = rand;
        playBarcodeBeep();
        showBarcodeToast('Barcode otomatis dibuat: ' + rand, true);
    }
}

// Global listener untuk menangkap ketikan super cepat dari hardware Barcode Scanner USB
window.addEventListener('keydown', function(e) {
    const currentTime = Date.now();
    const timeDiff = currentTime - lastKeyTime;
    lastKeyTime = currentTime;

    // Jika scanner USB mengirim tombol Enter
    if (e.key === 'Enter') {
        if (barcodeBuffer.length >= 3 && timeDiff < 120) {
            e.preventDefault();
            const scannedCode = barcodeBuffer.trim();
            barcodeBuffer = '';
            handleScannedBarcode(scannedCode);
            return;
        }
        barcodeBuffer = '';
        return;
    }

    // Tangkap karakter alfanumerik tunggal
    if (e.key.length === 1) {
        if (timeDiff > 120) {
            barcodeBuffer = e.key;
        } else {
            barcodeBuffer += e.key;
        }
    }
}, true);

function handleScannedBarcode(code) {
    playBarcodeBeep();

    // 1. Jika Modal Tambah Barang sedang terbuka
    const modalTambah = document.getElementById('modalTambahProduk');
    if (modalTambah && modalTambah.classList.contains('show')) {
        const input = document.getElementById('create_barcode');
        if (input) {
            input.value = code;
            input.focus();
            input.style.border = '2px solid #10b981';
            setTimeout(() => input.style.border = '', 1500);
            showBarcodeToast('Barcode USB terdeteksi ke form tambah: ' + code, true);
        }
        return;
    }

    // 2. Jika Modal Edit Barang sedang terbuka
    const modalEdit = document.getElementById('modalEditProduk');
    if (modalEdit && modalEdit.classList.contains('show')) {
        const input = document.getElementById('edit_barcode');
        if (input) {
            input.value = code;
            input.focus();
            input.style.border = '2px solid #10b981';
            setTimeout(() => input.style.border = '', 1500);
            showBarcodeToast('Barcode USB terdeteksi ke form edit: ' + code, true);
        }
        return;
    }

    // 3. Jika di Halaman Utama (Daftar Produk)
    const barcodeInput = document.getElementById('search_barcode') || document.getElementById('product_search_input');
    if (barcodeInput) {
        barcodeInput.value = code;
        showBarcodeToast('Barcode USB dipindai: ' + code + ' (Memfilter...)', true);
        
        // Cek apakah ada baris tabel dengan barcode ini langsung di layar
        const cleanCode = code.toLowerCase();
        const matchedRow = document.querySelector(`tr[data-barcode="${cleanCode}"]`) || document.querySelector(`tr[data-barcode="${code}"]`);
        if (matchedRow) {
            matchedRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            matchedRow.style.backgroundColor = 'rgba(16, 185, 129, 0.3)';
            matchedRow.style.outline = '2px solid #10b981';
            setTimeout(() => {
                matchedRow.style.backgroundColor = '';
                matchedRow.style.outline = '';
            }, 3000);
        } else {
            barcodeInput.closest('form').submit();
        }
    }
}

// ==========================================
// PENCARIAN REAL-TIME NAMA BARANG
// ==========================================
const inputSearchNama = document.getElementById('search_nama_produk');
const btnClearSearchNama = document.getElementById('btnClearSearchNama');
const selectFilterKat = document.getElementById('filter_kategori');
const inputSearchBarcode = document.getElementById('search_barcode');
const countVisibleEl = document.getElementById('count_visible_prod');
const prodRows = document.querySelectorAll('.product-data-row');
const tbodyProd = document.querySelector('#table_daftar_produk tbody');

function filterProductRowsLive() {
    const qNama = inputSearchNama ? inputSearchNama.value.toLowerCase().trim() : '';
    const qBarcode = inputSearchBarcode ? inputSearchBarcode.value.toLowerCase().trim() : '';
    const qKat = selectFilterKat ? selectFilterKat.value : '';

    let visibleCount = 0;

    prodRows.forEach((row) => {
        const nama = (row.getAttribute('data-nama') || '').toLowerCase();
        const barcode = (row.getAttribute('data-barcode') || '').toLowerCase();
        const kat = row.getAttribute('data-kategori') || '';

        const matchNama = !qNama || nama.includes(qNama);
        const matchBarcode = !qBarcode || barcode.includes(qBarcode);
        const matchKat = !qKat || kat === qKat;

        if (matchNama && matchBarcode && matchKat) {
            row.style.display = '';
            visibleCount++;
            const noCell = row.querySelector('.row-no-cell');
            if (noCell) noCell.textContent = visibleCount;
        } else {
            row.style.display = 'none';
        }
    });

    if (countVisibleEl) {
        countVisibleEl.textContent = visibleCount;
    }

    // Tampilkan pesan kosong jika tidak ada yang cocok
    let emptyRow = document.getElementById('live_search_empty_row');
    if (visibleCount === 0 && prodRows.length > 0) {
        if (!emptyRow) {
            emptyRow = document.createElement('tr');
            emptyRow.id = 'live_search_empty_row';
            emptyRow.innerHTML = `
                <td colspan="8" class="text-center py-5 text-secondary">
                    <i class="bi bi-search fs-1 d-block mb-2 text-primary"></i>
                    Tidak ada barang ditemukan dengan nama "<strong class="text-body-emphasis">${escapeHtml(qNama || qBarcode)}</strong>".
                </td>
            `;
            if (tbodyProd) tbodyProd.appendChild(emptyRow);
        } else {
            emptyRow.style.display = '';
            const st = emptyRow.querySelector('strong');
            if (st) st.textContent = qNama || qBarcode;
        }
    } else if (emptyRow) {
        emptyRow.style.display = 'none';
    }
}

if (inputSearchNama) {
    inputSearchNama.addEventListener('input', filterProductRowsLive);
    inputSearchNama.addEventListener('change', filterProductRowsLive);
}

if (btnClearSearchNama) {
    btnClearSearchNama.addEventListener('click', function() {
        if (inputSearchNama) {
            inputSearchNama.value = '';
            filterProductRowsLive();
            inputSearchNama.focus();
        }
    });
}

if (inputSearchBarcode) {
    inputSearchBarcode.addEventListener('input', filterProductRowsLive);
}

if (selectFilterKat) {
    selectFilterKat.addEventListener('change', filterProductRowsLive);
}

// Shortcut keyboard: tekan F2 untuk langsung fokus ke input pencarian nama barang
window.addEventListener('keydown', function(e) {
    if (e.key === 'F2') {
        if (inputSearchNama) {
            e.preventDefault();
            inputSearchNama.focus();
            inputSearchNama.select();
        }
    }
});

// DOM Initialization: Tampilkan banner info HTTPS jika diakses lewat HTTP
document.addEventListener('DOMContentLoaded', function() {
    if (window.location.protocol !== 'https:') {
        document.querySelectorAll('.https-camera-tip').forEach(el => el.classList.remove('d-none'));
        document.querySelectorAll('.link-switch-https').forEach(el => {
            el.href = 'https://' + window.location.host + window.location.pathname;
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
