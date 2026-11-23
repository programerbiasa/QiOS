<?php
$page_title = 'Checkout Pemesanan';
require_once __DIR__ . '/config/database.php';

// Pastikan keranjang tidak kosong
if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['flash_error'] = 'Keranjang belanja Anda masih kosong. Silakan pilih produk terlebih dahulu.';
    header('Location: index.php');
    exit;
}

$error_checkout = '';
$nama_pembeli = '';

// Hitung total belanja terkini
$total_tagihan = 0;
foreach ($_SESSION['cart'] as $item) {
    $total_tagihan += ((float)$item['harga'] * (int)$item['jumlah']);
}

// ==========================================
// PROSES SUBMIT CHECKOUT (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_pembeli = trim($_POST['nama_pembeli'] ?? '');

    // Validasi Input Nama Pembeli
    if ($nama_pembeli === '') {
        $error_checkout = 'Mohon masukkan nama pembeli / pemesan.';
    } elseif (strlen($nama_pembeli) < 2 || strlen($nama_pembeli) > 100) {
        $error_checkout = 'Nama pembeli harus antara 2 hingga 100 karakter.';
    } else {
        // Mulai Transaksi Database (Atomic Transaction)
        try {
            $pdo->beginTransaction();

            // 1. Verifikasi stok setiap item secara akurat dengan FOR UPDATE
            $total_verifikasi = 0;
            $items_to_save = [];

            foreach ($_SESSION['cart'] as $pid => $item) {
                $pid = (int)$pid;
                $qty = (int)$item['jumlah'];

                $stmt = $pdo->prepare("SELECT id, nama_produk, harga, stok FROM produk WHERE id = ? FOR UPDATE");
                $stmt->execute([$pid]);
                $prod = $stmt->fetch();

                if (!$prod) {
                    throw new Exception("Produk '{$item['nama_produk']}' tidak lagi tersedia di katalog.");
                }

                if ((int)$prod['stok'] < $qty) {
                    throw new Exception("Stok untuk '{$prod['nama_produk']}' tidak mencukupi. Tersisa: {$prod['stok']} pcs, dipesan: {$qty} pcs.");
                }

                $subtotal = $qty * (float)$prod['harga'];
                $total_verifikasi += $subtotal;

                $items_to_save[] = [
                    'produk_id' => $prod['id'],
                    'nama_produk' => $prod['nama_produk'],
                    'harga' => (float)$prod['harga'],
                    'jumlah' => $qty,
                    'subtotal' => $subtotal,
                    'stok_baru' => (int)$prod['stok'] - $qty
                ];
            }

            // 2. Generate Kode Transaksi Unik (Format: TRX-YYYYMMDD-XXXX)
            $kode_pesanan = 'TRX-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

            // 3. Simpan ke Header Pesanan
            $stmt_order = $pdo->prepare("
                INSERT INTO pesanan (kode_pesanan, nama_pembeli, total_harga, status, created_at)
                VALUES (?, ?, ?, 'SELESAI', NOW())
            ");
            $stmt_order->execute([$kode_pesanan, $nama_pembeli, $total_verifikasi]);
            $pesanan_id = $pdo->lastInsertId();

            // 4. Simpan Detail Item Pesanan & Kurangi Stok Produk Otomatis
            $stmt_detail = $pdo->prepare("
                INSERT INTO pesanan_detail (pesanan_id, produk_id, nama_produk, harga_satuan, jumlah, subtotal)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt_update_stok = $pdo->prepare("
                UPDATE produk SET stok = ? WHERE id = ?
            ");

            foreach ($items_to_save as $row) {
                // Insert detail
                $stmt_detail->execute([
                    $pesanan_id,
                    $row['produk_id'],
                    $row['nama_produk'],
                    $row['harga'],
                    $row['jumlah'],
                    $row['subtotal']
                ]);

                // Kurangi stok produk
                $stmt_update_stok->execute([
                    $row['stok_baru'],
                    $row['produk_id']
                ]);
            }

            // Sukses, lakukan commit
            $pdo->commit();

            // Kosongkan keranjang belanja
            unset($_SESSION['cart']);

            // Redirect ke halaman sukses dengan membawa kode pesanan
            header('Location: order-success.php?kode=' . urlencode($kode_pesanan));
            exit;

        } catch (Exception $e) {
            // Batalkan transaksi jika terjadi error/stok tidak cukup
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_checkout = $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="mb-4">
            <a href="cart.php" class="btn btn-sm btn-outline-secondary rounded-pill">
                <i class="bi bi-arrow-left me-1"></i> Kembali ke Keranjang
            </a>
            <h3 class="fw-bold text-dark mt-2">
                <i class="bi bi-credit-card text-primary me-2"></i>Selesaikan Pemesanan (Checkout)
            </h3>
            <p class="text-muted">Masukkan data pembeli untuk menyelesaikan transaksi Anda di Kios Cendekia.</p>
        </div>

        <?php if (!empty($error_checkout)): ?>
            <div class="alert alert-danger shadow-sm alert-dismissible fade show mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill fs-4 me-2"></i>
                    <div>
                        <strong>Gagal Menyelesaikan Pesanan:</strong><br>
                        <?= e($error_checkout) ?>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Form Input Data Pembeli -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                    <h5 class="fw-bold text-dark mb-3">
                        <i class="bi bi-person-badge text-primary me-2"></i>Informasi Pembeli
                    </h5>
                    
                    <form action="checkout.php" method="POST" id="checkoutForm">
                        <div class="mb-3">
                            <label for="nama_pembeli" class="form-label fw-semibold">Nama Lengkap Pembeli <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg rounded-3" id="nama_pembeli" name="nama_pembeli" placeholder="Contoh: Budi Santoso / Siswa 1" value="<?= e($nama_pembeli) ?>" required autofocus>
                            <div class="form-text">Nama ini akan dicantumkan pada struk transaksi pembelian.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Metode Pembayaran</label>
                            <div class="p-3 border rounded-3 bg-light d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-cash-coin fs-3 text-success"></i>
                                    <div>
                                        <div class="fw-bold text-dark">Bayar di Kasir / Tunai</div>
                                        <small class="text-muted">Pembayaran langsung di counter Kios</small>
                                    </div>
                                </div>
                                <span class="badge bg-success">Default</span>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold py-3 shadow-sm d-flex align-items-center justify-content-center gap-2">
                            <i class="bi bi-check-circle-fill"></i> Konfirmasi & Pesan Sekarang
                        </button>
                    </form>
                </div>
            </div>

            <!-- Rincian Pesanan -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm rounded-4 p-4 h-100 bg-white">
                    <h5 class="fw-bold text-dark mb-3">
                        <i class="bi bi-receipt text-primary me-2"></i>Rincian Barang Belanjaan
                    </h5>

                    <ul class="list-group list-group-flush mb-3">
                        <?php foreach ($_SESSION['cart'] as $item): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-bottom">
                                <div>
                                    <div class="fw-bold text-dark"><?= e($item['nama_produk']) ?></div>
                                    <small class="text-muted"><?= (int)$item['jumlah'] ?> x <?= rupiah($item['harga']) ?></small>
                                </div>
                                <span class="fw-semibold text-secondary">
                                    <?= rupiah((float)$item['harga'] * (int)$item['jumlah']) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="mt-auto pt-3 border-top">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fs-5 fw-bold text-dark">Total Yang Harus Dibayar:</span>
                            <span class="fs-4 fw-bold text-success"><?= rupiah($total_tagihan) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
