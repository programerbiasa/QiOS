<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_admin();

// ==========================================
// HANDLE OPERASI CRUD KATEGORI (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. TAMBAH KATEGORI
    if ($action === 'create') {
        $nama_kategori = trim($_POST['nama_kategori'] ?? '');
        $deskripsi     = trim($_POST['deskripsi'] ?? '');

        if ($nama_kategori === '') {
            $_SESSION['flash_error'] = 'Nama kategori tidak boleh kosong!';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO kategori (nama_kategori, deskripsi, created_at) VALUES (?, ?, NOW())");
                $stmt->execute([$nama_kategori, $deskripsi]);
                $_SESSION['flash_success'] = "Kategori '{$nama_kategori}' berhasil ditambahkan!";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Gagal menambah kategori: ' . $e->getMessage();
            }
        }
        header('Location: kategori.php');
        exit;
    }

    // 2. UBAH KATEGORI (UPDATE)
    if ($action === 'update') {
        $id            = (int)($_POST['id'] ?? 0);
        $nama_kategori = trim($_POST['nama_kategori'] ?? '');
        $deskripsi     = trim($_POST['deskripsi'] ?? '');

        if ($id <= 0 || $nama_kategori === '') {
            $_SESSION['flash_error'] = 'Data kategori tidak valid!';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE kategori SET nama_kategori = ?, deskripsi = ? WHERE id = ?");
                $stmt->execute([$nama_kategori, $deskripsi, $id]);
                $_SESSION['flash_success'] = "Kategori '{$nama_kategori}' berhasil diperbarui!";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Gagal memperbarui kategori: ' . $e->getMessage();
            }
        }
        header('Location: kategori.php');
        exit;
    }

    // 3. HAPUS KATEGORI (DELETE)
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt_cek = $pdo->prepare("SELECT nama_kategori FROM kategori WHERE id = ?");
                $stmt_cek->execute([$id]);
                $kat = $stmt_cek->fetch();

                if ($kat) {
                    $stmt_del = $pdo->prepare("DELETE FROM kategori WHERE id = ?");
                    $stmt_del->execute([$id]);
                    $_SESSION['flash_success'] = "Kategori '{$kat['nama_kategori']}' berhasil dihapus.";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Gagal menghapus kategori: ' . $e->getMessage();
            }
        }
        header('Location: kategori.php');
        exit;
    }
}

// Ambil Daftar Kategori beserta jumlah produk di dalamnya
try {
    $stmt = $pdo->query("
        SELECT k.*, COUNT(p.id) AS total_produk 
        FROM kategori k
        LEFT JOIN produk p ON k.id = p.kategori_id
        GROUP BY k.id
        ORDER BY k.nama_kategori ASC
    ");
    $kategori_items = $stmt->fetchAll();
} catch (PDOException $e) {
    $kategori_items = [];
    $error_msg = $e->getMessage();
}

$admin_title = 'Manajemen Kategori';
require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold text-white mb-1">
            <i class="bi bi-tags text-warning me-2"></i>Manajemen Kategori Barang
        </h3>
        <p class="text-secondary mb-0">Kelola kelompok jenis produk untuk memudahkan pencarian di kasir POS.</p>
    </div>
    <div>
        <button type="button" class="btn btn-warning rounded-pill px-4 shadow-sm text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#modalTambahKategori">
            <i class="bi bi-plus-lg me-1"></i> Tambah Kategori
        </button>
    </div>
</div>

<?php if (isset($error_msg)): ?>
    <div class="alert alert-danger shadow-sm"><?= e($error_msg) ?></div>
<?php endif; ?>

<!-- Tabel Daftar Kategori -->
<div class="card border-secondary shadow rounded-4 overflow-hidden mb-5">
    <div class="table-responsive">
        <table class="table table-hover table-dark align-middle mb-0">
            <thead class="table-secondary">
                <tr>
                    <th scope="col" style="width: 50px;" class="text-center">No</th>
                    <th scope="col">Nama Kategori</th>
                    <th scope="col">Deskripsi</th>
                    <th scope="col" class="text-center">Jumlah Produk</th>
                    <th scope="col" class="text-center" style="width: 140px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($kategori_items)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-secondary">
                            <i class="bi bi-tag fs-1 d-block mb-2"></i>
                            Belum ada kategori yang dibuat.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($kategori_items as $k): 
                    ?>
                        <tr>
                            <td class="text-center text-secondary fw-semibold"><?= $no++ ?></td>
                            <td>
                                <span class="badge bg-primary bg-opacity-25 text-info fs-6 px-3 py-2">
                                    <i class="bi bi-tag-fill me-1"></i><?= e($k['nama_kategori']) ?>
                                </span>
                            </td>
                            <td class="text-secondary small">
                                <?= !empty($k['deskripsi']) ? e($k['deskripsi']) : '-' ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary rounded-pill px-3 py-1">
                                    <?= (int)$k['total_produk'] ?> Barang
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-info" title="Ubah Kategori" onclick='openEditModal(<?= json_encode($k) ?>)'>
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" title="Hapus Kategori" onclick="openDeleteModal(<?= (int)$k['id'] ?>, '<?= e(addslashes($k['nama_kategori'])) ?>', <?= (int)$k['total_produk'] ?>)">
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

<!-- MODAL TAMBAH KATEGORI -->
<div class="modal fade" id="modalTambahKategori" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">
            <div class="modal-header border-secondary bg-primary bg-opacity-25">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Tambah Kategori Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="kategori.php" method="POST">
                <input type="hidden" name="action" value="create">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="create_nama_kat" class="form-label small fw-semibold">Nama Kategori <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bg-dark border-secondary text-white" id="create_nama_kat" name="nama_kategori" placeholder="Contoh: Minuman, Makanan, Perlengkapan..." required>
                    </div>
                    <div class="mb-2">
                        <label for="create_deskripsi_kat" class="form-label small fw-semibold">Deskripsi</label>
                        <textarea class="form-control bg-dark border-secondary text-white" id="create_deskripsi_kat" name="deskripsi" rows="3" placeholder="Keterangan singkat..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold">Simpan Kategori</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL EDIT KATEGORI -->
<div class="modal fade" id="modalEditKategori" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">
            <div class="modal-header border-secondary bg-info bg-opacity-25">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Ubah Kategori</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="kategori.php" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_kat_id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="edit_nama_kat" class="form-label small fw-semibold">Nama Kategori <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bg-dark border-secondary text-white" id="edit_nama_kat" name="nama_kategori" required>
                    </div>
                    <div class="mb-2">
                        <label for="edit_deskripsi_kat" class="form-label small fw-semibold">Deskripsi</label>
                        <textarea class="form-control bg-dark border-secondary text-white" id="edit_deskripsi_kat" name="deskripsi" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-info rounded-pill px-4 fw-bold">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL HAPUS KATEGORI -->
<div class="modal fade" id="modalHapusKategori" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4 text-center p-3">
            <div class="modal-body">
                <div class="text-danger mb-3">
                    <i class="bi bi-exclamation-octagon display-4"></i>
                </div>
                <h5 class="fw-bold">Hapus Kategori?</h5>
                <p class="text-secondary small mb-2">Hapus kategori <strong id="hapus_kat_nama" class="text-white"></strong>?</p>
                <div class="text-warning small mb-3" id="hapus_warning_box"></div>
                <form action="kategori.php" method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="hapus_kat_id">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger btn-sm rounded-pill px-3">Ya, Hapus</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function openEditModal(kat) {
    document.getElementById('edit_kat_id').value = kat.id;
    document.getElementById('edit_nama_kat').value = kat.nama_kategori;
    document.getElementById('edit_deskripsi_kat').value = kat.deskripsi || '';
    new bootstrap.Modal(document.getElementById('modalEditKategori')).show();
}

function openDeleteModal(id, nama, totalProduk) {
    document.getElementById('hapus_kat_id').value = id;
    document.getElementById('hapus_kat_nama').innerText = nama;
    const warn = document.getElementById('hapus_warning_box');
    if (totalProduk > 0) {
        warn.innerText = `Perhatian: Ada ${totalProduk} produk yang terhubung. Produk tersebut akan menjadi 'Tanpa Kategori'.`;
    } else {
        warn.innerText = '';
    }
    new bootstrap.Modal(document.getElementById('modalHapusKategori')).show();
}
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
