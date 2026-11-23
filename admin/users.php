<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_admin();

$admin_user = current_user();



// Tangani aksi Form: Tambah, Ubah, Hapus User

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';



    // 1. TAMBAH PENGGUNA (CREATE)
    if ($action === 'create') {
        $username     = trim($_POST['username'] ?? '');
        $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
        $password     = $_POST['password'] ?? '';
        $role         = $_POST['role'] ?? 'user';

        // Admin maupun Superadmin diizinkan membuat akun role Superadmin
        if (!in_array($role, ['superadmin', 'admin', 'user', 'kasir'])) {
            $role = 'user';
        }
        if ($role === 'kasir') {
            $role = 'user';
        }

        if (empty($username) || empty($nama_lengkap) || empty($password)) {
            $_SESSION['flash_error'] = 'Username, Nama Lengkap, dan Password wajib diisi!';
        } else {
            try {
                // Cek ketersediaan username
                $stmt_cek = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $stmt_cek->execute([$username]);
                if ($stmt_cek->fetch()) {
                    $_SESSION['flash_error'] = "Username '{$username}' sudah digunakan. Silakan gunakan username lain.";
                } else {
                    $password_hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (username, password, password_plain, nama_lengkap, role, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$username, $password_hash, $password, $nama_lengkap, $role]);
                    $_SESSION['flash_success'] = "Pengguna '{$nama_lengkap}' berhasil ditambahkan!";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Database Error: ' . $e->getMessage();
            }
        }
        header('Location: users.php');
        exit;
    }

    // 2. UBAH PENGGUNA (UPDATE)
    if ($action === 'update') {
        $id           = (int)($_POST['id'] ?? 0);
        $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
        $new_password = $_POST['new_password'] ?? '';

        $stmt_target = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt_target->execute([$id]);
        $target_user = $stmt_target->fetch();

        if (!$target_user || empty($nama_lengkap)) {
            $_SESSION['flash_error'] = 'Data yang dikirim tidak valid!';
        } else {
            $role = $_POST['role'] ?? $target_user['role'];
            // Admin dan Superadmin boleh mengubah data / menaikkan role pengguna
            if (!in_array($role, ['superadmin', 'admin', 'user', 'kasir'])) {
                $role = $target_user['role'];
            }
            if ($role === 'kasir') {
                $role = 'user';
            }

            try {
                if (!empty($new_password)) {
                    $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE users SET nama_lengkap = ?, role = ?, password = ?, password_plain = ? WHERE id = ?");
                    $stmt->execute([$nama_lengkap, $role, $password_hash, $new_password, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET nama_lengkap = ?, role = ? WHERE id = ?");
                    $stmt->execute([$nama_lengkap, $role, $id]);
                }

                // Jika profil yang diedit adalah akun sendiri, sinkronkan nama & role di session aktif
                if ($id === (int)$admin_user['id']) {
                    $_SESSION['nama_lengkap'] = $nama_lengkap;
                    $_SESSION['role'] = $role;
                }
                $_SESSION['flash_success'] = "Data pengguna berhasil diperbarui!";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Database Error: ' . $e->getMessage();
            }
        }
        header('Location: users.php');
        exit;
    }

    // 3. HAPUS PENGGUNA (DELETE)

    if ($action === 'delete') {

        $id = (int)($_POST['id'] ?? 0);



        if ($id === (int)$admin_user['id']) {

            $_SESSION['flash_error'] = 'Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif login!';

        } elseif ($id > 0) {

            try {

                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");

                $stmt->execute([$id]);

                $_SESSION['flash_success'] = "Pengguna berhasil dihapus!";

            } catch (PDOException $e) {

                $_SESSION['flash_error'] = 'Gagal menghapus pengguna. Akun mungkin memiliki riwayat transaksi.';

            }

        }

        header('Location: users.php');

        exit;

    }

    // 4. PUTUSKAN SESI / SETEL OFFLINE
    if ($action === 'force_offline') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id === (int)$admin_user['id']) {
            $_SESSION['flash_error'] = 'Anda tidak dapat memutuskan sesi Anda sendiri dari sini. Silakan gunakan tombol Logout.';
        } elseif ($id > 0) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET active_session_token = NULL,
                        active_device_name = NULL,
                        last_active_at = NULL
                    WHERE id = ?
                ");
                $stmt->execute([$id]);
                $_SESSION['flash_success'] = 'Sesi pengguna berhasil diputus dan status diubah menjadi Offline.';
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Database Error: ' . $e->getMessage();
            }
        }
        header('Location: users.php');
        exit;
    }

}



// Ambil semua daftar pengguna dari database

try {

    $stmt = $pdo->query("

        SELECT 
            id, username, nama_lengkap, role, created_at,
            password_plain,
            active_device_name, last_active_at, last_seen,
            active_session_token,
            CASE 
                WHEN active_session_token IS NOT NULL 
                     AND (
                         (last_active_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, last_active_at, NOW()) <= 5)
                         OR 
                         (last_seen IS NOT NULL AND TIMESTAMPDIFF(MINUTE, last_seen, NOW()) <= 5)
                     ) THEN 1
                ELSE 0
            END AS is_online
        FROM users 
        ORDER BY 
            CASE role 
                WHEN 'superadmin' THEN 1 
                WHEN 'admin' THEN 2 
                ELSE 3 
            END ASC, id ASC

    ");

    $users_list = $stmt->fetchAll();
} catch (PDOException $e) {
    $users_list = [];
}

$admin_title = 'Hak Akses Pengguna';
require_once __DIR__ . '/includes/admin_header.php';
?>



<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">

    <div>

        <h2 class="fw-bold mb-1 text-white">

            <i class="bi bi-person-gear text-info me-2"></i>Hak Akses Pengguna

        </h2>

        <p class="text-secondary mb-0">Kelola akun kasir dan administrator sistem QiOS Cendekia.</p>

    </div>

    <div class="d-flex gap-2">

        <a href="users.php" class="btn btn-outline-info rounded-pill px-3 shadow-sm" title="Segarkan Data">

            <i class="bi bi-arrow-clockwise me-1"></i> Refresh

        </a>

        <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalTambahUser">

            <i class="bi bi-person-plus-fill me-1"></i> Tambah Pengguna

        </button>

    </div>

</div>



<!-- Kartu Ringkasan Akun -->

<div class="row g-3 mb-4">

    <div class="col-md-4">

        <div class="stat-card d-flex align-items-center gap-3">

            <div class="stat-icon bg-primary bg-opacity-25 text-primary">

                <i class="bi bi-people-fill"></i>

            </div>

            <div>

                <span class="text-secondary small fw-semibold text-uppercase">Total Pengguna</span>

                <h4 class="fw-bold text-white mb-0"><?= count($users_list) ?> Akun</h4>

            </div>

        </div>

    </div>

    <div class="col-md-4">

        <div class="stat-card d-flex align-items-center gap-3">

            <div class="stat-icon bg-success bg-opacity-25 text-success">

                <i class="bi bi-broadcast"></i>

            </div>

            <div>

                <span class="text-secondary small fw-semibold text-uppercase">Petugas Online</span>

                <h4 class="fw-bold text-success mb-0">

                    <?php

                    $online_count = 0;

                    foreach ($users_list as $u) {

                        if ($u['is_online'] == 1 || $u['id'] == $admin_user['id']) {

                            $online_count++;

                        }

                    }

                    echo $online_count;

                    ?> Aktif

                </h4>

            </div>

        </div>

    </div>

    <div class="col-md-4">

        <div class="stat-card d-flex align-items-center gap-3">

            <div class="stat-icon bg-info bg-opacity-25 text-info">

                <i class="bi bi-person-badge"></i>

            </div>

            <div>

                <span class="text-secondary small fw-semibold text-uppercase">Akun Anda</span>

                <h4 class="fw-bold text-info mb-0"><?= e($admin_user['nama_lengkap']) ?></h4>

            </div>

        </div>

    </div>

</div>



<!-- Tabel Pengguna -->

<div class="card border-secondary shadow-sm rounded-4 overflow-hidden mb-4">

    <div class="card-header bg-dark border-secondary py-3 d-flex justify-content-between align-items-center">

        <h5 class="fw-bold text-white mb-0">

            <i class="bi bi-person-lines-fill text-info me-2"></i>Daftar Petugas & Administrator

        </h5>

        <span class="badge bg-secondary">Multi-akses Bebas Aktif</span>

    </div>

    <div class="table-responsive">

        <table class="table table-dark table-hover align-middle mb-0">

            <thead class="table-dark text-secondary border-secondary">
                <tr>
                    <th scope="col" style="width: 50px;" class="text-center">ID</th>
                    <th scope="col">Nama Lengkap & Akun</th>
                    <th scope="col" style="min-width: 150px;">Password</th>
                    <th scope="col">Status</th>
                    <th scope="col">Perangkat Terakhir</th>
                    <th scope="col" class="text-center">Hak Akses / Role</th>
                    <th scope="col">Aktivitas Terakhir</th>
                    <th scope="col" class="text-center" style="width: 140px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users_list as $u): ?>
                    <?php
                    $is_self = ($u['id'] == $admin_user['id']);
                    $is_logged_in = ($u['is_online'] == 1 || $is_self);
                    $avatar_bg = 'bg-success';
                    if ($u['role'] === 'superadmin') $avatar_bg = 'bg-danger';
                    elseif ($u['role'] === 'admin') $avatar_bg = 'bg-primary';
                    ?>
                    <tr>
                        <td class="text-center text-secondary fw-bold">#<?= (int)$u['id'] ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold <?= $avatar_bg ?>" style="width: 38px; height: 38px; font-size: 14px;">
                                    <?= strtoupper(substr($u['nama_lengkap'], 0, 1)) ?>
                                </div>
                                <div>
                                    <strong class="text-white d-block"><?= e($u['nama_lengkap']) ?></strong>
                                    <code class="text-secondary small">@<?= e($u['username']) ?></code>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($admin_user['role'] === 'superadmin'): ?>
                                <div class="d-inline-flex align-items-center gap-1 bg-dark bg-opacity-75 border border-secondary rounded-pill px-2 py-1 shadow-sm">
                                    <span class="font-monospace text-warning fw-bold small" id="pass_txt_<?= $u['id'] ?>" data-plain="<?= e($u['password_plain'] ?: 'Belum diset') ?>">••••••••</span>
                                    <button type="button" class="btn btn-sm btn-link text-info p-0 ms-1" onclick="togglePassRow(<?= $u['id'] ?>)" title="Lihat/Sembunyikan Password Asli">
                                        <i class="bi bi-eye" id="pass_icon_<?= $u['id'] ?>"></i>
                                    </button>
                                </div>
                            <?php elseif ($is_self): ?>
                                <div class="d-inline-flex align-items-center gap-1 bg-dark bg-opacity-75 border border-secondary rounded-pill px-2 py-1 shadow-sm">
                                    <span class="font-monospace text-info fw-bold small" id="pass_txt_<?= $u['id'] ?>" data-plain="<?= e($u['password_plain'] ?: 'Belum diset') ?>">••••••••</span>
                                    <button type="button" class="btn btn-sm btn-link text-info p-0 ms-1" onclick="togglePassRow(<?= $u['id'] ?>)" title="Lihat/Sembunyikan Password Saya">
                                        <i class="bi bi-eye" id="pass_icon_<?= $u['id'] ?>"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="d-inline-flex align-items-center gap-1 bg-dark bg-opacity-50 border border-secondary border-opacity-50 rounded-pill px-2 py-1 text-secondary" title="Terkunci: Hanya Super Administrator yang dapat melihat password pengguna lain">
                                    <span class="font-monospace small text-muted">••••••••</span>
                                    <i class="bi bi-lock-fill text-secondary ms-1" style="font-size: 0.8rem;"></i>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($is_logged_in): ?>
                                <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 px-2 py-1 fs-6 d-inline-flex align-items-center gap-1">
                                    <i class="bi bi-circle-fill" style="font-size: 0.55rem;"></i> Aktif
                                </span>
                                <?php if ($is_self): ?>
                                    <span class="badge bg-info text-dark ms-1" style="font-size: 0.65rem;">Sesi Anda</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-dark border border-secondary text-secondary px-2 py-1 d-inline-flex align-items-center gap-1">
                                    <i class="bi bi-circle" style="font-size: 0.55rem;"></i> Offline
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($u['active_device_name'])): ?>
                                <div class="text-white small fw-semibold">
                                    <i class="bi bi-display text-info me-1"></i><?= e($u['active_device_name']) ?>
                                </div>
                            <?php elseif ($is_self): ?>
                                <div class="text-white small fw-semibold">
                                    <i class="bi bi-browser-chrome text-info me-1"></i>Browser Web Admin
                                </div>
                            <?php else: ?>
                                <span class="text-secondary small">-</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-center">
                            <?php if ($u['role'] === 'superadmin'): ?>
                                <span class="badge px-3 py-1 fs-6 shadow-sm" style="background: linear-gradient(135deg, #7c3aed 0%, #db2777 100%); color: #ffffff;">
                                    <i class="bi bi-shield-shaded me-1"></i> Super Administrator
                                </span>
                            <?php elseif ($u['role'] === 'admin'): ?>
                                <span class="badge bg-primary px-3 py-1 fs-6">
                                    <i class="bi bi-shield-check me-1"></i> Administrator
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 px-3 py-1 fs-6">
                                    <i class="bi bi-person-fill me-1"></i> User
                                </span>
                            <?php endif; ?>
                        </td>

                        <td class="text-secondary small">

                            <?php if (!empty($u['last_seen'])): ?>

                                <i class="bi bi-clock me-1 text-info"></i><?= date('H:i:s', strtotime($u['last_seen'])) ?> WITA

                            <?php else: ?>

                                <span class="text-muted">-</span>

                            <?php endif; ?>

                        </td>

                        <td class="text-center">

                            <div class="btn-group btn-group-sm">

                                <?php if ($is_self): ?>

                                    <button type="button" class="btn btn-outline-info" title="Ubah Profil Saya" onclick='openEditModal(<?= json_encode($u) ?>)'>

                                        <i class="bi bi-pencil-square"></i>

                                    </button>

                                    <span class="badge bg-secondary bg-opacity-25 text-info align-self-center px-2 py-1 ms-1 small">Akun Anda</span>

                                <?php else: ?>

                                    <button type="button" class="btn btn-outline-info" title="Ubah Pengguna" onclick='openEditModal(<?= json_encode($u) ?>)'>

                                        <i class="bi bi-pencil-square"></i>

                                    </button>

                                    <?php if ($is_logged_in): ?>
                                        <button type="button" class="btn btn-outline-warning" title="Putuskan Sesi / Setel Offline" onclick="openForceOfflineModal(<?= (int)$u['id'] ?>, '<?= e(addslashes($u['nama_lengkap'])) ?>')">
                                            <i class="bi bi-power"></i>
                                        </button>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-outline-danger" title="Hapus Pengguna" onclick="openDeleteModal(<?= (int)$u['id'] ?>, '<?= e(addslashes($u['nama_lengkap'])) ?>')">

                                        <i class="bi bi-trash"></i>

                                    </button>

                                <?php endif; ?>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>



<!-- ======================================================== -->

<!-- MODAL 1: TAMBAH USER -->

<!-- ======================================================== -->

<div class="modal fade" id="modalTambahUser" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">

            <div class="modal-header border-secondary bg-primary bg-opacity-25">

                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-2"></i>Tambah Pengguna Baru</h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>

            <form action="users.php" method="POST">

                <input type="hidden" name="action" value="create">

                <div class="modal-body p-4">

                    <div class="mb-3">

                        <label for="create_username" class="form-label small fw-semibold">Username Login <span class="text-danger">*</span></label>

                        <div class="input-group">

                            <span class="input-group-text bg-dark border-secondary text-secondary">@</span>

                            <input type="text" class="form-control bg-dark border-secondary text-white" id="create_username" name="username" placeholder="Contoh: kasir2" required>

                        </div>

                    </div>

                    <div class="mb-3">

                        <label for="create_nama" class="form-label small fw-semibold">Nama Lengkap Petugas <span class="text-danger">*</span></label>

                        <input type="text" class="form-control bg-dark border-secondary text-white" id="create_nama" name="nama_lengkap" placeholder="Contoh: Siti Rahma" required>

                    </div>

                    <div class="mb-3">
                        <label for="create_role" class="form-label small fw-semibold">Hak Akses / Role <span class="text-danger">*</span></label>
                        <select class="form-select bg-dark border-secondary text-white" id="create_role" name="role">
                            <option value="user" selected>User (Akses Kasir POS)</option>
                            <option value="admin">Administrator (Panel Kontrol Sistem)</option>
                            <option value="superadmin">Super Administrator (Akses Penuh)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="create_password" class="form-label small fw-semibold">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control bg-dark border-secondary text-white" id="create_password" name="password" placeholder="Minimal 6 karakter" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleInputPassword('create_password', this)" title="Lihat/Sembunyikan Password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                </div>

                <div class="modal-footer border-secondary">

                    <button type="button" class="btn btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Batal</button>

                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold">Simpan Pengguna</button>

                </div>

            </form>

        </div>

    </div>

</div>



<!-- ======================================================== -->

<!-- MODAL 2: UBAH USER -->

<!-- ======================================================== -->

<div class="modal fade" id="modalEditUser" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">

            <div class="modal-header border-secondary bg-info bg-opacity-25">

                <h5 class="modal-title fw-bold text-info"><i class="bi bi-pencil-square me-2"></i>Ubah Data Pengguna</h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

            </div>

            <form action="users.php" method="POST">

                <input type="hidden" name="action" value="update">

                <input type="hidden" name="id" id="edit_id">

                <div class="modal-body p-4">

                    <div class="mb-3">

                        <label class="form-label small fw-semibold">Username Login</label>

                        <input type="text" class="form-control bg-dark border-secondary text-secondary" id="edit_username" readonly disabled>

                        <small class="text-muted" style="font-size: 0.75rem;">Username unik tidak dapat diubah.</small>

                    </div>

                    <div class="mb-3">

                        <label for="edit_nama" class="form-label small fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>

                        <input type="text" class="form-control bg-dark border-secondary text-white" id="edit_nama" name="nama_lengkap" required>

                    </div>

                    <div class="mb-3">
                        <label for="edit_role" class="form-label small fw-semibold">Hak Akses / Role <span class="text-danger">*</span></label>
                        <select class="form-select bg-dark border-secondary text-white" id="edit_role" name="role">
                            <option value="user">User (Akses Kasir POS)</option>
                            <option value="admin">Administrator (Panel Kontrol Sistem)</option>
                            <option value="superadmin">Super Administrator (Akses Penuh)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="edit_password" class="form-label small fw-semibold">Password Baru (Opsional)</label>
                        <div class="input-group">
                            <input type="password" class="form-control bg-dark border-secondary text-white" id="edit_password" name="new_password" placeholder="Kosongkan jika password tidak ingin diubah">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleInputPassword('edit_password', this)" title="Lihat/Sembunyikan Password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                </div>

                <div class="modal-footer border-secondary">

                    <button type="button" class="btn btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Batal</button>

                    <button type="submit" class="btn btn-info rounded-pill px-4 fw-semibold text-dark">Simpan Perubahan</button>

                </div>

            </form>

        </div>

    </div>

</div>



<!-- ======================================================== -->

<!-- MODAL 3: HAPUS USER -->

<!-- ======================================================== -->

<div class="modal fade" id="modalDeleteUser" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">

            <div class="modal-body p-4 text-center">

                <div class="text-danger mb-3">

                    <i class="bi bi-exclamation-triangle-fill display-3"></i>

                </div>

                <h5 class="fw-bold">Konfirmasi Hapus Pengguna</h5>

                <p class="text-secondary small mb-4">

                    Apakah Anda yakin ingin menghapus akun <strong id="del_nama_user" class="text-white"></strong>? Tindakan ini tidak dapat dibatalkan.

                </p>

                <form action="users.php" method="POST">

                    <input type="hidden" name="action" value="delete">

                    <input type="hidden" name="id" id="del_user_id">

                    <div class="d-flex justify-content-center gap-2">

                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Batal</button>

                        <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">Ya, Hapus</button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</div>



<script>

const CURRENT_ADMIN_ID = <?= (int)$admin_user['id'] ?>;

function openEditModal(user) {

    document.getElementById('edit_id').value = user.id;

    document.getElementById('edit_username').value = '@' + user.username;

    document.getElementById('edit_nama').value = user.nama_lengkap;

    const roleSelect = document.getElementById('edit_role');
    let userRole = user.role;
    if (userRole === 'kasir') userRole = 'user';
    roleSelect.value = userRole;
    if (user.id == CURRENT_ADMIN_ID) {
        roleSelect.disabled = true;
    } else {
        roleSelect.disabled = false;
    }

    document.getElementById('edit_password').value = '';

    new bootstrap.Modal(document.getElementById('modalEditUser')).show();

}



function openDeleteModal(id, nama) {

    document.getElementById('del_user_id').value = id;

    document.getElementById('del_nama_user').innerText = nama;

    new bootstrap.Modal(document.getElementById('modalDeleteUser')).show();

}


function openForceOfflineModal(id, nama) {
    document.getElementById('offline_user_id').value = id;
    document.getElementById('offline_nama_user').innerText = nama;
    new bootstrap.Modal(document.getElementById('modalForceOffline')).show();
}

function togglePassRow(id) {
    const textEl = document.getElementById('pass_txt_' + id);
    const iconEl = document.getElementById('pass_icon_' + id);
    if (!textEl || !iconEl) return;
    const plain = textEl.getAttribute('data-plain');
    if (textEl.innerText === '••••••••') {
        textEl.innerText = plain;
        iconEl.classList.remove('bi-eye');
        iconEl.classList.add('bi-eye-slash');
    } else {
        textEl.innerText = '••••••••';
        iconEl.classList.remove('bi-eye-slash');
        iconEl.classList.add('bi-eye');
    }
}

function toggleInputPassword(inputId, btn) {
    const input = document.getElementById(inputId);
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

<!-- Modal Konfirmasi Putuskan Sesi -->
<div class="modal fade" id="modalForceOffline" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content bg-dark border-secondary text-white" style="border-radius: 14px;">
            <div class="modal-header border-secondary pb-2">
                <h6 class="modal-title fw-bold text-warning d-flex align-items-center gap-2">
                    <i class="bi bi-power"></i> Putuskan Sesi
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="users.php" method="POST">
                <input type="hidden" name="action" value="force_offline">
                <input type="hidden" name="id" id="offline_user_id">
                <div class="modal-body py-3">
                    <p class="small mb-1 text-light">Putuskan sesi login aktif untuk:</p>
                    <strong class="d-block text-warning" id="offline_nama_user"></strong>
                    <div class="text-secondary small mt-2" style="font-size: 0.75rem;">
                        Status akun akan segera berubah menjadi <strong>Offline</strong>.
                    </div>
                </div>
                <div class="modal-footer border-secondary pt-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning btn-sm fw-bold">Setel Offline</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>

