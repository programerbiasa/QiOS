<?php
$page_title = 'Pemesanan Berhasil';
require_once __DIR__ . '/config/database.php';

$kode_pesanan = trim($_GET['kode'] ?? '');

if ($kode_pesanan === '') {
    header('Location: index.php');
    exit;
}

try {
    // Ambil data header pesanan
    $stmt = $pdo->prepare("SELECT * FROM pesanan WHERE kode_pesanan = ?");
    $stmt->execute([$kode_pesanan]);
    $pesanan = $stmt->fetch();

    if (!$pesanan) {
        $_SESSION['flash_error'] = 'Pesanan tidak ditemukan.';
        header('Location: index.php');
        exit;
    }

    // Ambil detail item pesanan
    $stmt_items = $pdo->prepare("SELECT * FROM pesanan_detail WHERE pesanan_id = ?");
    $stmt_items->execute([$pesanan['id']]);
    $items = $stmt_items->fetchAll();

} catch (PDOException $e) {
    die("Terjadi kesalahan: " . $e->getMessage());
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
@page {
    size: 58mm auto;
    margin: 0mm;
}
@media print {
    html, body {
        width: 58mm !important;
        max-width: 58mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
        color: #000000 !important;
        font-family: 'Courier New', Courier, monospace !important;
    }
    body * {
        visibility: hidden !important;
    }
    .receipt-container, .receipt-container * {
        visibility: visible !important;
    }
    .receipt-container {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 58mm !important;
        max-width: 58mm !important;
        padding: 2mm 3mm 14mm 2mm !important;
        margin: 0 !important;
        box-shadow: none !important;
        border: none !important;
        border-radius: 0 !important;
        font-size: 11px !important;
        line-height: 1.25 !important;
        box-sizing: border-box !important;
    }
    .receipt-container img {
        height: 36px !important;
    }
    .no-print, nav, footer {
        display: none !important;
    }
}
</style>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <!-- Banner Sukses -->
        <div class="text-center mb-4 no-print">
            <div class="d-inline-flex align-items-center justify-content-center bg-success text-white rounded-circle shadow-sm mb-3" style="width: 75px; height: 75px;">
                <i class="bi bi-check-lg display-5"></i>
            </div>
            <h2 class="fw-bold text-dark">Pemesanan Berhasil Diselesaikan!</h2>
            <p class="text-muted">Terima kasih atas pesanan Anda. Stok produk telah diperbarui secara otomatis.</p>
        </div>

        <!-- Kartu Struk / Bukti Pemesanan -->
        <div class="card border-0 shadow rounded-4 p-4 p-md-5 receipt-container bg-white mb-4">
            <!-- Header Struk -->
            <div class="text-center border-bottom pb-4 mb-4">
                <img src="<?= e(function_exists('get_app_logo') ? get_app_logo() : 'assets/images/logo.png') ?>" alt="Logo" style="height: 60px;" class="mb-2">
                <h4 class="fw-bold mb-0 text-dark">KIOS DIGITAL CENDEKIA</h4>
                <p class="text-muted small mb-0">Struk Resmi Pembelian Layanan Mandiri</p>
                <div class="mt-2 text-secondary small">
                    Waktu: <?= date('d M Y, H:i', strtotime($pesanan['created_at'])) ?> WIB
                </div>
            </div>

            <!-- Info Pesanan -->
            <div class="row g-2 mb-4 bg-light p-3 rounded-3 small">
                <div class="col-sm-6">
                    <span class="text-muted">Kode Pesanan:</span>
                    <div class="fw-bold text-primary font-monospace fs-6"><?= e($pesanan['kode_pesanan']) ?></div>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted">Nama Pembeli:</span>
                    <div class="fw-bold text-dark fs-6"><?= e($pesanan['nama_pembeli']) ?></div>
                </div>
                <div class="col-sm-6 mt-2">
                    <span class="text-muted">Status Transaksi:</span>
                    <div><span class="badge bg-success"><?= e($pesanan['status']) ?></span></div>
                </div>
                <div class="col-sm-6 mt-2">
                    <span class="text-muted">Metode Pembayaran:</span>
                    <div class="fw-semibold text-dark">Tunai / Kasir</div>
                </div>
            </div>

            <!-- Rincian Produk -->
            <h6 class="fw-bold text-dark mb-3">Rincian Barang Dipesan:</h6>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Nama Barang</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Harga Satuan</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td class="fw-semibold text-dark"><?= e($it['nama_produk']) ?></td>
                                <td class="text-center"><?= (int)$it['jumlah'] ?></td>
                                <td class="text-end text-muted"><?= rupiah($it['harga_satuan']) ?></td>
                                <td class="text-end fw-bold"><?= rupiah($it['subtotal']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="border-top border-dark">
                            <th colspan="3" class="fs-5 text-end pt-3">Total Pembayaran:</th>
                            <th class="fs-5 text-end text-success pt-3"><?= rupiah($pesanan['total_harga']) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="text-center text-muted small mt-4 pt-3 border-top">
                Simpan nomor transaksi ini atau cetak struk sebagai bukti pengambilan pesanan.
            </div>
        </div>

        <!-- Tombol Aksi -->
        <div class="d-flex justify-content-center gap-3 no-print">
            <button onclick="window.print()" class="btn btn-outline-dark px-4 py-2 rounded-pill fw-semibold">
                <i class="bi bi-printer me-2"></i>Cetak Struk
            </button>
            <a href="index.php" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold">
                <i class="bi bi-cart-plus me-2"></i>Belanja Lagi
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
