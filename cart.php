<?php
$page_title = 'Keranjang Belanja';
require_once __DIR__ . '/config/database.php';

// Inisialisasi Keranjang di Session jika belum ada
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// ==========================================
// HANDLE AKSI KERANJANG (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // AKSI: TAMBAH PRODUK KE KERANJANG
    if ($action === 'add') {
        $produk_id = (int)($_POST['produk_id'] ?? 0);
        $qty_input = (int)($_POST['qty'] ?? 1);
        if ($qty_input <= 0) $qty_input = 1;

        if ($produk_id > 0) {
            // Ambil data produk terkini dari database
            $stmt = $pdo->prepare("SELECT id, nama_produk, harga, stok, foto FROM produk WHERE id = ?");
            $stmt->execute([$produk_id]);
            $produk = $stmt->fetch();

            if ($produk) {
                $stok_tersedia = (int)$produk['stok'];
                $qty_sekarang = isset($_SESSION['cart'][$produk_id]) ? (int)$_SESSION['cart'][$produk_id]['jumlah'] : 0;
                $qty_baru = $qty_sekarang + $qty_input;

                if ($stok_tersedia <= 0) {
                    $_SESSION['flash_error'] = "Maaf, stok produk '{$produk['nama_produk']}' sudah habis.";
                } elseif ($qty_baru > $stok_tersedia) {
                    $_SESSION['cart'][$produk_id] = [
                        'id' => $produk['id'],
                        'nama_produk' => $produk['nama_produk'],
                        'harga' => (float)$produk['harga'],
                        'jumlah' => $stok_tersedia,
                        'foto' => $produk['foto'],
                        'stok_maks' => $stok_tersedia
                    ];
                    $_SESSION['flash_error'] = "Jumlah disesuaikan ke stok maksimal yang tersedia ({$stok_tersedia} pcs).";
                } else {
                    $_SESSION['cart'][$produk_id] = [
                        'id' => $produk['id'],
                        'nama_produk' => $produk['nama_produk'],
                        'harga' => (float)$produk['harga'],
                        'jumlah' => $qty_baru,
                        'foto' => $produk['foto'],
                        'stok_maks' => $stok_tersedia
                    ];
                    $_SESSION['flash_success'] = "'{$produk['nama_produk']}' berhasil ditambahkan ke keranjang!";
                }
            } else {
                $_SESSION['flash_error'] = "Produk tidak ditemukan.";
            }
        }
        header('Location: cart.php');
        exit;
    }

    // AKSI: UBAH JUMLAH (UPDATE QTY)
    if ($action === 'update') {
        $produk_id = (int)($_POST['produk_id'] ?? 0);
        $qty = (int)($_POST['qty'] ?? 0);

        if ($produk_id > 0 && isset($_SESSION['cart'][$produk_id])) {
            if ($qty <= 0) {
                // Jika 0 atau negatif, hapus dari keranjang
                unset($_SESSION['cart'][$produk_id]);
                $_SESSION['flash_success'] = "Produk dihapus dari keranjang.";
            } else {
                // Cek stok database
                $stmt = $pdo->prepare("SELECT stok, nama_produk FROM produk WHERE id = ?");
                $stmt->execute([$produk_id]);
                $db_stok = $stmt->fetch();

                if ($db_stok) {
                    $stok_tersedia = (int)$db_stok['stok'];
                    if ($qty > $stok_tersedia) {
                        $_SESSION['cart'][$produk_id]['jumlah'] = $stok_tersedia;
                        $_SESSION['flash_error'] = "Jumlah melebihi stok tersedia. Disesuaikan menjadi {$stok_tersedia} pcs.";
                    } else {
                        $_SESSION['cart'][$produk_id]['jumlah'] = $qty;
                        $_SESSION['flash_success'] = "Jumlah berhasil diperbarui.";
                    }
                }
            }
        }
        header('Location: cart.php');
        exit;
    }

    // AKSI: HAPUS SATU ITEM
    if ($action === 'delete') {
        $produk_id = (int)($_POST['produk_id'] ?? 0);
        if ($produk_id > 0 && isset($_SESSION['cart'][$produk_id])) {
            $nama = $_SESSION['cart'][$produk_id]['nama_produk'];
            unset($_SESSION['cart'][$produk_id]);
            $_SESSION['flash_success'] = "'{$nama}' berhasil dihapus dari keranjang.";
        }
        header('Location: cart.php');
        exit;
    }

    // AKSI: KOSONGKAN SELURUH KERANJANG
    if ($action === 'clear') {
        $_SESSION['cart'] = [];
        $_SESSION['flash_success'] = "Keranjang belanja berhasil dikosongkan.";
        header('Location: cart.php');
        exit;
    }
}

// Sinkronisasi data keranjang dengan database (cek perubahan harga & stok terkini)
$total_bayar = 0;
$items_valid = [];

if (!empty($_SESSION['cart'])) {
    $ids = array_keys($_SESSION['cart']);
    if (!empty($ids)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id, nama_produk, harga, stok, foto FROM produk WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $db_products = $stmt->fetchAll();

        $db_map = [];
        foreach ($db_products as $p) {
            $db_map[$p['id']] = $p;
        }

        foreach ($_SESSION['cart'] as $pid => $item) {
            if (isset($db_map[$pid])) {
                $db_p = $db_map[$pid];
                $stok_real = (int)$db_p['stok'];
                $qty = min((int)$item['jumlah'], $stok_real);
                
                if ($stok_real <= 0) {
                    // Stok habis di DB, skip item
                    continue;
                }
                
                $subtotal = $qty * (float)$db_p['harga'];
                $total_bayar += $subtotal;

                $items_valid[$pid] = [
                    'id' => $db_p['id'],
                    'nama_produk' => $db_p['nama_produk'],
                    'harga' => (float)$db_p['harga'],
                    'jumlah' => $qty,
                    'stok_maks' => $stok_real,
                    'foto' => $db_p['foto'],
                    'subtotal' => $subtotal
                ];
            }
        }
        $_SESSION['cart'] = $items_valid;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="row">
    <div class="col-12 mb-3">
        <h3 class="fw-bold text-dark">
            <i class="bi bi-cart3 text-primary me-2"></i>Keranjang Belanja
        </h3>
        <p class="text-muted">Periksa kembali item pesanan Anda sebelum melanjutkan ke pembayaran.</p>
    </div>
</div>

<?php if (empty($_SESSION['cart'])): ?>
    <div class="card p-5 text-center border-0 shadow-sm rounded-4">
        <div class="mb-3">
            <i class="bi bi-cart-x text-muted display-1"></i>
        </div>
        <h4 class="fw-bold text-dark">Keranjang Belanja Masih Kosong</h4>
        <p class="text-muted mb-4">Anda belum menambahkan produk apapun ke dalam keranjang.</p>
        <div>
            <a href="index.php" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold">
                <i class="bi bi-arrow-left me-1"></i> Mulai Belanja Sekarang
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="row g-4">
        <!-- Tabel Keranjang -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="min-width: 200px;">Produk</th>
                                <th scope="col" class="text-center">Harga</th>
                                <th scope="col" class="text-center" style="width: 160px;">Jumlah</th>
                                <th scope="col" class="text-end">Subtotal</th>
                                <th scope="col" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($_SESSION['cart'] as $item): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-3 bg-light p-2 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                                <img src="<?= e(function_exists('get_app_logo') ? get_app_logo() : 'assets/images/logo.png') ?>" alt="Icon" style="max-width: 100%; max-height: 100%;">
                                            </div>
                                            <div>
                                                <h6 class="mb-0 fw-bold text-dark"><?= e($item['nama_produk']) ?></h6>
                                                <small class="text-muted">Stok Maks: <?= (int)$item['stok_maks'] ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center fw-semibold text-secondary">
                                        <?= rupiah($item['harga']) ?>
                                    </td>
                                    <td class="text-center">
                                        <form action="cart.php" method="POST" class="d-flex align-items-center justify-content-center gap-1">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="produk_id" value="<?= (int)$item['id'] ?>">
                                            <input type="number" name="qty" class="form-control form-control-sm text-center fw-bold" style="width: 70px;" min="1" max="<?= (int)$item['stok_maks'] ?>" value="<?= (int)$item['jumlah'] ?>" onchange="this.form.submit()">
                                        </form>
                                    </td>
                                    <td class="text-end fw-bold text-success">
                                        <?= rupiah($item['subtotal']) ?>
                                    </td>
                                    <td class="text-center">
                                        <form action="cart.php" method="POST" onsubmit="return confirm('Hapus item ini dari keranjang?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="produk_id" value="<?= (int)$item['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-circle" title="Hapus">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-footer bg-white p-3 d-flex justify-content-between align-items-center">
                    <a href="index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                        <i class="bi bi-arrow-left me-1"></i> Lanjut Belanja
                    </a>
                    <form action="cart.php" method="POST" onsubmit="return confirm('Kosongkan semua barang di keranjang?');">
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                            <i class="bi bi-trash3 me-1"></i> Kosongkan Keranjang
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Ringkasan Pesanan & Tombol Checkout -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 p-4 sticky-top" style="top: 90px;">
                <h5 class="fw-bold mb-3 text-dark">Ringkasan Belanja</h5>
                
                <div class="d-flex justify-content-between mb-2 text-muted">
                    <span>Total Item:</span>
                    <span class="fw-bold text-dark"><?= count($_SESSION['cart']) ?> macam barang</span>
                </div>
                <div class="d-flex justify-content-between mb-3 text-muted">
                    <span>Biaya Layanan:</span>
                    <span class="text-success fw-bold">Gratis (Rp 0)</span>
                </div>
                
                <hr class="my-3">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <span class="fs-5 fw-bold text-dark">Total Tagihan:</span>
                    <span class="fs-4 fw-bold text-primary"><?= rupiah($total_bayar) ?></span>
                </div>

                <a href="checkout.php" class="btn btn-success btn-lg w-100 rounded-pill fw-bold shadow-sm py-3 d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-check2-circle fs-5"></i> Lanjut ke Checkout
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
