<?php
$admin_title = 'Dashboard';
require_once __DIR__ . '/includes/admin_header.php';

try {
    // 1. Total Pendapatan
    $stmt_income = $pdo->query("SELECT COALESCE(SUM(total_harga), 0) AS total_pendapatan FROM pesanan WHERE status = 'SELESAI'");
    $total_pendapatan = (float)$stmt_income->fetch()['total_pendapatan'];

    // 2. Total Produk & Total Stok
    $stmt_prod_count = $pdo->query("SELECT COUNT(*) AS total_produk, COALESCE(SUM(stok), 0) AS total_stok FROM produk");
    $prod_stat = $stmt_prod_count->fetch();
    $total_produk = (int)$prod_stat['total_produk'];
    $total_stok = (int)$prod_stat['total_stok'];

    // 3. Total Kategori
    $stmt_kat_count = $pdo->query("SELECT COUNT(*) AS total_kategori FROM kategori");
    $total_kategori = (int)$stmt_kat_count->fetch()['total_kategori'];

    // 4. Total Transaksi
    $stmt_order_count = $pdo->query("SELECT COUNT(*) AS total_pesanan FROM pesanan");
    $total_pesanan = (int)$stmt_order_count->fetch()['total_pesanan'];

    // 5. Total User Kasir & Admin
    $stmt_user_count = $pdo->query("SELECT COUNT(*) AS total_users FROM users");
    $total_users = (int)$stmt_user_count->fetch()['total_users'];

    // 6. Riwayat Transaksi Terbaru (Limit 8)
    $stmt_recent = $pdo->query("
        SELECT p.*, u.nama_lengkap AS nama_kasir 
        FROM pesanan p
        LEFT JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC 
        LIMIT 8
    ");
    $recent_orders = $stmt_recent->fetchAll();

    // 7. Top 10 Barang Paling Sering Dibeli (Terlaris)
    $stmt_top = $pdo->query("
        SELECT 
            p.id AS produk_id,
            p.nama_produk,
            p.foto,
            p.harga,
            p.stok,
            k.nama_kategori,
            s.nama_satuan,
            s.kode_satuan,
            COALESCE(SUM(pd.jumlah), 0) AS total_terjual,
            COALESCE(COUNT(pd.id), 0) AS frekuensi_beli,
            COALESCE(SUM(pd.subtotal), 0) AS total_omset
        FROM produk p
        LEFT JOIN pesanan_detail pd ON p.id = pd.produk_id
        LEFT JOIN pesanan ord ON pd.pesanan_id = ord.id AND ord.status = 'SELESAI'
        LEFT JOIN kategori k ON p.kategori_id = k.id
        LEFT JOIN satuan s ON p.satuan_id = s.id
        GROUP BY p.id
        ORDER BY total_terjual DESC, frekuensi_beli DESC, p.nama_produk ASC
        LIMIT 10
    ");
    $top_products = $stmt_top->fetchAll();

} catch (PDOException $e) {
    $db_error = $e->getMessage();
}
?>

<div class="row align-items-center mb-4 g-2">
    <div class="col-md-8">
        <h3 class="fw-bold text-white mb-1">
            <i class="bi bi-speedometer2 text-info me-2"></i>Dashboard Administrasi
        </h3>
        <p class="text-secondary mb-0">Ringkasan performa penjualan kasir, stok inventori, dan statistik data QiOS Cendekia.</p>
    </div>
    <div class="col-md-4 text-md-end">
        <a href="backup.php" class="btn btn-outline-info rounded-pill px-3 shadow-sm" title="Cadangkan atau Pulihkan Database">
            <i class="bi bi-database-fill-gear me-1"></i> Backup Database
        </a>
    </div>
</div>

<?php if (isset($db_error)): ?>
    <div class="alert alert-danger shadow-sm">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($db_error) ?>
    </div>
<?php endif; ?>

<!-- TABEL 10 PRODUK PALING SERING DIBELI (TOP 10 TERLARIS) -->
<div class="card border-secondary shadow rounded-4 overflow-hidden mb-4">
    <div class="card-header bg-dark border-secondary py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h5 class="fw-bold mb-0 text-white">
                <i class="bi bi-fire text-warning me-2"></i>10 Barang Paling Sering Dibeli (Terlaris)
            </h5>
            <small class="text-secondary">Peringkat produk teratas berdasarkan total unit yang terjual dan frekuensi transaksi kasir.</small>
        </div>
        <a href="produk.php" class="btn btn-outline-warning btn-sm rounded-pill px-3 fw-semibold">
            <i class="bi bi-box-seam me-1"></i> Kelola Master Barang
        </a>
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-dark align-middle mb-0">
            <thead class="table-secondary">
                <tr>
                    <th scope="col" class="text-center" style="width: 70px;">Rank</th>
                    <th scope="col" style="width: 60px;">Foto</th>
                    <th scope="col">Nama Barang</th>
                    <th scope="col">Kategori</th>
                    <th scope="col" class="text-end">Harga Jual</th>
                    <th scope="col" class="text-center">Total Terjual</th>
                    <th scope="col" class="text-end">Total Omset</th>
                    <th scope="col" class="text-center">Sisa Stok</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($top_products)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-secondary">
                            <i class="bi bi-basket3 fs-3 d-block mb-1"></i>
                            Belum ada data penjualan produk.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $rank = 1;
                    foreach ($top_products as $tp): 
                    ?>
                        <tr>
                            <!-- Rank Badge -->
                            <td class="text-center">
                                <?php if ($rank === 1): ?>
                                    <span class="badge bg-warning text-dark fw-bold px-2 py-1 shadow-sm" title="Juara 1">
                                        <i class="bi bi-trophy-fill me-1"></i>#1
                                    </span>
                                <?php elseif ($rank === 2): ?>
                                    <span class="badge bg-light text-dark fw-bold px-2 py-1 shadow-sm" title="Peringkat 2">
                                        <i class="bi bi-award-fill me-1"></i>#2
                                    </span>
                                <?php elseif ($rank === 3): ?>
                                    <span class="badge text-white fw-bold px-2 py-1 shadow-sm" style="background: #cd7f32;" title="Peringkat 3">
                                        <i class="bi bi-award-fill me-1"></i>#3
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-dark border border-secondary text-secondary px-2 py-1 font-monospace">
                                        #<?= $rank ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Foto Barang -->
                            <td>
                                <?php if (!empty($tp['foto'])): ?>
                                    <img src="../<?= e($tp['foto']) ?>" alt="Foto" class="rounded-3 border border-secondary" style="width: 44px; height: 44px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="rounded-3 bg-secondary bg-opacity-25 d-flex align-items-center justify-content-center text-secondary border border-secondary" style="width: 44px; height: 44px;">
                                        <i class="bi bi-image fs-6"></i>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Nama Barang -->
                            <td>
                                <div class="fw-bold text-white"><?= e($tp['nama_produk']) ?></div>
                                <small class="text-secondary font-monospace">ID: #<?= (int)$tp['produk_id'] ?></small>
                            </td>

                            <!-- Kategori -->
                            <td>
                                <span class="badge bg-dark border border-secondary text-light">
                                    <?= e($tp['nama_kategori'] ?? 'Tanpa Kategori') ?>
                                </span>
                            </td>

                            <!-- Harga Jual -->
                            <td class="text-end font-monospace text-secondary">
                                <?= rupiah($tp['harga']) ?>
                            </td>

                            <!-- Total Terjual -->
                            <td class="text-center">
                                <?php 
                                $tp_uom = e($tp['kode_satuan'] ?? ($tp['nama_satuan'] ?? 'Pcs'));
                                if ((int)$tp['total_terjual'] > 0): ?>
                                    <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 fs-6 fw-bold px-3 py-1">
                                        <?= number_format($tp['total_terjual']) ?> <?= $tp_uom ?>
                                    </span>
                                    <div class="text-secondary small mt-1" style="font-size: 0.72rem;">
                                        <?= (int)$tp['frekuensi_beli'] ?>x transaksi
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-25 text-secondary px-2 py-1">
                                        0 <?= $tp_uom ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Total Omset -->
                            <td class="text-end fw-bold text-info font-monospace">
                                <?= rupiah($tp['total_omset']) ?>
                            </td>

                            <!-- Sisa Stok -->
                            <td class="text-center">
                                <?php if ((int)$tp['stok'] > 10): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                        <i class="bi bi-check-circle me-1"></i><?= number_format($tp['stok']) ?> <?= $tp_uom ?>
                                    </span>
                                <?php elseif ((int)$tp['stok'] > 0): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">
                                        <i class="bi bi-exclamation-circle me-1"></i><?= number_format($tp['stok']) ?> <?= $tp_uom ?> (Menipis)
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                        <i class="bi bi-x-circle me-1"></i>Habis
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php 
                        $rank++;
                    endforeach; 
                    ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Riwayat Transaksi Terbaru -->
<div class="card border-secondary shadow rounded-4 overflow-hidden mb-4">
    <div class="card-header bg-dark border-secondary py-3 d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-white">
            <i class="bi bi-clock-history text-info me-2"></i>Transaksi Kasir Terbaru
        </h5>
        <a href="transaksi.php" class="btn btn-outline-info btn-sm rounded-pill px-3">
            Lihat Laporan Lengkap <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-dark align-middle mb-0">
            <thead class="table-secondary">
                <tr>
                    <th scope="col">Kode Transaksi</th>
                    <th scope="col">Kasir / Petugas</th>
                    <th scope="col">Pelanggan</th>
                    <th scope="col">Total Belanja</th>
                    <th scope="col">Status</th>
                    <th scope="col">Waktu</th>
                    <th scope="col" class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_orders)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-secondary">
                            <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                            Belum ada riwayat transaksi kasir.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent_orders as $ord): ?>
                        <tr>
                            <td class="fw-bold font-monospace text-info">
                                <?= e($ord['kode_pesanan']) ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary text-light">
                                    <i class="bi bi-person me-1"></i><?= e($ord['nama_kasir'] ?? 'Kasir') ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                $plg = $ord['nama_pembeli'] ?? 'Umum';
                                if ($plg === 'Guru'): ?>
                                    <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-25 px-2">
                                        <i class="bi bi-mortarboard-fill me-1"></i> Guru
                                    </span>
                                <?php elseif ($plg === 'Siswa'): ?>
                                    <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-25 px-2">
                                        <i class="bi bi-backpack-fill me-1"></i> Siswa
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-25 text-light border border-secondary px-2">
                                        <i class="bi bi-person me-1"></i> <?= e($plg) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold text-success font-monospace">
                                <?= rupiah($ord['total_harga']) ?>
                            </td>
                            <td>
                                <span class="badge bg-success">
                                    <?= e($ord['status']) ?>
                                </span>
                            </td>
                            <td class="text-secondary small">
                                <?= date('d M Y, H:i', strtotime($ord['created_at'])) ?> WIB
                            </td>
                            <td class="text-center">
                                <a href="transaksi.php?search=<?= urlencode($ord['kode_pesanan']) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                                    <i class="bi bi-eye me-1"></i> Rincian
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
