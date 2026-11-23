<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_admin();

// ==========================================
// HANDLE OPERASI CRUD SATUAN BARANG (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. TAMBAH SATUAN BARU
    if ($action === 'create') {
        $nama_satuan = trim($_POST['nama_satuan'] ?? '');
        $kode_satuan = trim($_POST['kode_satuan'] ?? '');
        $deskripsi   = trim($_POST['deskripsi'] ?? '');

        if ($nama_satuan === '') {
            $_SESSION['flash_error'] = 'Nama satuan tidak boleh kosong!';
        } else {
            if ($kode_satuan === '') {
                $kode_satuan = substr($nama_satuan, 0, 4);
            }
            try {
                // Cek duplikasi nama satuan
                $stmt_cek = $pdo->prepare("SELECT id FROM satuan WHERE LOWER(nama_satuan) = LOWER(?)");
                $stmt_cek->execute([$nama_satuan]);
                if ($stmt_cek->fetch()) {
                    $_SESSION['flash_error'] = "Satuan '{$nama_satuan}' sudah ada di database!";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO satuan (nama_satuan, kode_satuan, deskripsi, created_at) VALUES (?, ?, ?, NOW())");
                    $stmt->execute([$nama_satuan, $kode_satuan, $deskripsi]);
                    $_SESSION['flash_success'] = "Satuan baru '{$nama_satuan}' ({$kode_satuan}) berhasil ditambahkan!";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Gagal menambah satuan: ' . $e->getMessage();
            }
        }
        header('Location: satuan.php');
        exit;
    }

    // 2. UBAH SATUAN (UPDATE)
    if ($action === 'update') {
        $id          = (int)($_POST['id'] ?? 0);
        $nama_satuan = trim($_POST['nama_satuan'] ?? '');
        $kode_satuan = trim($_POST['kode_satuan'] ?? '');
        $deskripsi   = trim($_POST['deskripsi'] ?? '');

        if ($id <= 0 || $nama_satuan === '') {
            $_SESSION['flash_error'] = 'Data satuan tidak valid!';
        } else {
            if ($kode_satuan === '') {
                $kode_satuan = substr($nama_satuan, 0, 4);
            }
            try {
                // Cek duplikasi dengan ID lain
                $stmt_cek = $pdo->prepare("SELECT id FROM satuan WHERE LOWER(nama_satuan) = LOWER(?) AND id != ?");
                $stmt_cek->execute([$nama_satuan, $id]);
                if ($stmt_cek->fetch()) {
                    $_SESSION['flash_error'] = "Nama satuan '{$nama_satuan}' sudah digunakan oleh data lain!";
                } else {
                    $stmt = $pdo->prepare("UPDATE satuan SET nama_satuan = ?, kode_satuan = ?, deskripsi = ? WHERE id = ?");
                    $stmt->execute([$nama_satuan, $kode_satuan, $deskripsi, $id]);
                    $_SESSION['flash_success'] = "Satuan '{$nama_satuan}' berhasil diperbarui!";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Gagal memperbarui satuan: ' . $e->getMessage();
            }
        }
        header('Location: satuan.php');
        exit;
    }

    // 3. HAPUS SATUAN (DELETE)
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt_cek = $pdo->prepare("SELECT nama_satuan FROM satuan WHERE id = ?");
                $stmt_cek->execute([$id]);
                $sat = $stmt_cek->fetch();

                if ($sat) {
                    // Cek apakah satuan sedang digunakan oleh produk
                    $stmt_prod = $pdo->prepare("SELECT COUNT(*) AS total FROM produk WHERE satuan_id = ?");
                    $stmt_prod->execute([$id]);
                    $pakai = (int)$stmt_prod->fetch()['total'];

                    if ($pakai > 0) {
                        $_SESSION['flash_error'] = "Satuan '{$sat['nama_satuan']}' tidak dapat dihapus karena masih digunakan oleh {$pakai} produk. Silakan ubah satuan produk terkait terlebih dahulu.";
                    } else {
                        $stmt_del = $pdo->prepare("DELETE FROM satuan WHERE id = ?");
                        $stmt_del->execute([$id]);
                        $_SESSION['flash_success'] = "Satuan '{$sat['nama_satuan']}' berhasil dihapus.";
                    }
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Gagal menghapus satuan: ' . $e->getMessage();
            }
        }
        header('Location: satuan.php');
        exit;
    }
}

// Filter Pencarian
$search = trim($_GET['search'] ?? '');

try {
    $sql = "
        SELECT s.*, COUNT(p.id) AS total_produk 
        FROM satuan s
        LEFT JOIN produk p ON s.id = p.satuan_id
        WHERE 1=1
    ";
    $params = [];
    if ($search !== '') {
        $sql .= " AND (s.nama_satuan LIKE ? OR s.kode_satuan LIKE ? OR s.deskripsi LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    $sql .= " GROUP BY s.id ORDER BY s.nama_satuan ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $satuan_items = $stmt->fetchAll();
} catch (PDOException $e) {
    $satuan_items = [];
    $error_msg = $e->getMessage();
}

$admin_title = 'Manajemen Satuan Barang';
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold text-white mb-1">
            <i class="bi bi-rulers text-info me-2"></i>Manajemen Satuan Barang (UoM)
        </h3>
        <p class="text-secondary mb-0">Kelola master satuan jual-beli (Pcs, Botol, Bungkus, Dus, Kilogram, dll) untuk stok dan kasir.</p>
    </div>
    <div>
        <button type="button" class="btn btn-info rounded-pill px-4 shadow-sm text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#modalTambahSatuan">
            <i class="bi bi-plus-lg me-1"></i> Tambah Satuan Baru
        </button>
    </div>
</div>

<?php if (isset($error_msg)): ?>
    <div class="alert alert-danger shadow-sm"><?= e($error_msg) ?></div>
<?php endif; ?>

<!-- Pencarian Satuan -->
<div class="card border-secondary p-3 mb-4 rounded-4">
    <form action="satuan.php" method="GET" class="row g-2 align-items-center">
        <div class="col-md-6">
            <div class="input-group">
                <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control bg-dark border-secondary text-white" placeholder="Cari nama atau singkatan satuan..." value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-secondary w-100 rounded-3">Cari Satuan</button>
        </div>
        <?php if ($search !== ''): ?>
            <div class="col-md-3">
                <a href="satuan.php" class="btn btn-outline-secondary w-100 rounded-3">Reset Filter</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- Tabel Daftar Satuan -->
<div class="card border-secondary shadow rounded-4 overflow-hidden mb-4">
    <div class="card-header bg-dark border-secondary py-3 d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-white">
            <i class="bi bi-list-check text-info me-2"></i>Daftar Master Satuan Perjualbelian
        </h5>
        <span class="badge bg-secondary text-light">Total: <?= count($satuan_items) ?> Satuan</span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-dark align-middle mb-0">
            <thead class="table-secondary">
                <tr>
                    <th scope="col" style="width: 50px;" class="text-center">No</th>
                    <th scope="col" style="width: 130px;">Kode / Singkatan</th>
                    <th scope="col">Nama Satuan Lengkap</th>
                    <th scope="col">Keterangan / Penggunaan</th>
                    <th scope="col" class="text-center" style="width: 150px;">Produk Terkait</th>
                    <th scope="col" class="text-center" style="width: 140px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($satuan_items)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-secondary">
                            <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                            Tidak ada data satuan ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($satuan_items as $sat): 
                    ?>
                        <tr>
                            <td class="text-center text-secondary fw-semibold"><?= $no++ ?></td>
                            <td>
                                <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-50 px-2 py-1 font-monospace fw-bold">
                                    <?= e($sat['kode_satuan']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="fw-bold text-white"><?= e($sat['nama_satuan']) ?></span>
                            </td>
                            <td class="text-secondary small">
                                <?= !empty($sat['deskripsi']) ? e($sat['deskripsi']) : '<span class="text-muted fst-italic">-</span>' ?>
                            </td>
                            <td class="text-center">
                                <?php if ((int)$sat['total_produk'] > 0): ?>
                                    <a href="produk.php?search=<?= urlencode($sat['nama_satuan']) ?>" class="badge bg-primary text-decoration-none px-3 py-1">
                                        <?= (int)$sat['total_produk'] ?> Barang
                                    </a>
                                <?php else: ?>
                                    <span class="badge bg-dark border border-secondary text-secondary px-2 py-1">0 Barang</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="d-inline-flex gap-1">
                                    <button type="button" class="btn btn-outline-warning btn-sm rounded-pill px-3" 
                                            onclick='openEditSatuanModal(<?= json_encode($sat) ?>)'>
                                        <i class="bi bi-pencil-square me-1"></i> Ubah
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-2" 
                                            onclick="openHapusSatuanModal(<?= $sat['id'] ?>, '<?= e(addslashes($sat['nama_satuan'])) ?>', <?= (int)$sat['total_produk'] ?>)">
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

<!-- ========================================== -->
<!-- MODAL TAMBAH SATUAN BARU -->
<!-- ========================================== -->
<div class="modal fade" id="modalTambahSatuan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">
            <div class="modal-header border-secondary bg-dark">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2 text-info"></i>Tambah Satuan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="satuan.php" method="POST">
                <input type="hidden" name="action" value="create">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Nama Satuan Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="nama_satuan" class="form-control bg-dark border-secondary text-white" placeholder="Contoh: Karton, Renceng, Lusin" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Kode / Singkatan Satuan</label>
                        <input type="text" name="kode_satuan" class="form-control bg-dark border-secondary text-white font-monospace" placeholder="Contoh: Ktn, Rnc, Lsn (opsional)">
                        <small class="text-muted">Ditampilkan pada nota kasir dan struk thermal.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Deskripsi / Contoh Penggunaan</label>
                        <textarea name="deskripsi" class="form-control bg-dark border-secondary text-white" rows="2" placeholder="Keterangan peruntukan satuan..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-info rounded-pill px-4 fw-bold text-dark">Simpan Satuan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL UBAH SATUAN -->
<!-- ========================================== -->
<div class="modal fade" id="modalEditSatuan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">
            <div class="modal-header border-secondary bg-dark">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-warning"></i>Ubah Satuan Barang</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="satuan.php" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_satuan_id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Nama Satuan Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="nama_satuan" id="edit_nama_satuan" class="form-control bg-dark border-secondary text-white" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Kode / Singkatan Satuan</label>
                        <input type="text" name="kode_satuan" id="edit_kode_satuan" class="form-control bg-dark border-secondary text-white font-monospace">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Deskripsi / Contoh Penggunaan</label>
                        <textarea name="deskripsi" id="edit_deskripsi_satuan" class="form-control bg-dark border-secondary text-white" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-bold text-dark">Perbarui Satuan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL HAPUS SATUAN -->
<!-- ========================================== -->
<div class="modal fade" id="modalHapusSatuan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4 text-center p-3">
            <div class="modal-body">
                <div class="text-danger mb-3">
                    <i class="bi bi-exclamation-triangle display-4"></i>
                </div>
                <h5 class="fw-bold mb-1">Hapus Satuan?</h5>
                <p class="text-secondary small mb-3">Hapus satuan <strong id="hapus_satuan_label" class="text-white"></strong>?</p>
                <div id="hapus_warning_box" class="alert alert-warning py-1 small d-none mb-3"></div>
                <form action="satuan.php" method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="hapus_satuan_id">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" id="btnKonfirmHapus" class="btn btn-danger btn-sm rounded-pill px-3 fw-bold">Ya, Hapus</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function openEditSatuanModal(data) {
    document.getElementById('edit_satuan_id').value = data.id;
    document.getElementById('edit_nama_satuan').value = data.nama_satuan;
    document.getElementById('edit_kode_satuan').value = data.kode_satuan || '';
    document.getElementById('edit_deskripsi_satuan').value = data.deskripsi || '';
    new bootstrap.Modal(document.getElementById('modalEditSatuan')).show();
}

function openHapusSatuanModal(id, nama, totalProduk) {
    document.getElementById('hapus_satuan_id').value = id;
    document.getElementById('hapus_satuan_label').innerText = nama;
    
    var warnBox = document.getElementById('hapus_warning_box');
    var btnHapus = document.getElementById('btnKonfirmHapus');
    if (totalProduk > 0) {
        warnBox.classList.remove('d-none');
        warnBox.innerText = 'Peringatan: Satuan ini sedang digunakan oleh ' + totalProduk + ' produk. Hapus tidak diperbolehkan.';
        btnHapus.disabled = true;
    } else {
        warnBox.classList.add('d-none');
        warnBox.innerText = '';
        btnHapus.disabled = false;
    }
    new bootstrap.Modal(document.getElementById('modalHapusSatuan')).show();
}
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
