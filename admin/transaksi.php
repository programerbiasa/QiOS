<?php
$admin_title = 'Laporan Penjualan Barang';
require_once __DIR__ . '/includes/admin_header.php';

// Filter Pencarian & Rentang Tanggal
$search           = trim($_GET['search'] ?? '');
$pelanggan_filter = trim($_GET['pelanggan'] ?? '');
$date_start       = trim($_GET['date_start'] ?? '');
$date_end         = trim($_GET['date_end'] ?? '');
$kasir_id         = trim($_GET['kasir_id'] ?? '');

$sql = "
    SELECT 
        pd.id AS detail_id,
        pd.pesanan_id,
        pd.produk_id,
        pd.nama_produk,
        pd.harga_satuan,
        pd.jumlah,
        pd.subtotal,
        p.kode_pesanan,
        p.nama_pembeli,
        p.total_harga AS total_nota,
        p.uang_bayar,
        p.kembalian,
        p.created_at AS tanggal_transaksi,
        u.nama_lengkap AS nama_kasir,
        s.kode_satuan
    FROM pesanan_detail pd
    INNER JOIN pesanan p ON pd.pesanan_id = p.id
    LEFT JOIN users u ON p.user_id = u.id
    LEFT JOIN produk pr ON pd.produk_id = pr.id
    LEFT JOIN satuan s ON pr.satuan_id = s.id
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (p.kode_pesanan LIKE ? OR p.nama_pembeli LIKE ? OR pd.nama_produk LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($pelanggan_filter !== '') {
    $sql .= " AND p.nama_pembeli = ?";
    $params[] = $pelanggan_filter;
}

if ($date_start !== '') {
    $sql .= " AND DATE(p.created_at) >= ?";
    $params[] = $date_start;
}

if ($date_end !== '') {
    $sql .= " AND DATE(p.created_at) <= ?";
    $params[] = $date_end;
}

if ($kasir_id !== '') {
    $sql .= " AND p.user_id = ?";
    $params[] = (int)$kasir_id;
}

$sql .= " ORDER BY p.created_at DESC, pd.id ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $item_list = $stmt->fetchAll();

    // Hitung akumulasi laporan
    $total_barang_terjual = 0;
    $total_harga_jual     = 0;
    $order_map            = [];

    foreach ($item_list as $it) {
        $total_barang_terjual += (int)($it['jumlah'] ?? 0);
        $total_harga_jual     += (float)($it['subtotal'] ?? 0);

        $pid = $it['pesanan_id'];
        if (!isset($order_map[$pid])) {
            $order_map[$pid] = [
                'id'           => $pid,
                'kode_pesanan' => $it['kode_pesanan'],
                'nama_kasir'   => $it['nama_kasir'] ?? 'Kasir',
                'nama_pembeli' => $it['nama_pembeli'] ?? 'Umum',
                'created_at'   => $it['tanggal_transaksi'],
                'total_harga'  => (float)$it['total_nota'],
                'uang_bayar'   => (float)$it['uang_bayar'],
                'kembalian'    => (float)$it['kembalian'],
                'items'        => [],
            ];
        }
        $order_map[$pid]['items'][] = $it;
    }

    // Ambil daftar kasir untuk dropdown filter
    $stmt_kasir = $pdo->query("SELECT id, nama_lengkap FROM users WHERE role IN ('kasir', 'admin', 'superadmin') ORDER BY nama_lengkap ASC");
    $list_kasir = $stmt_kasir->fetchAll();

} catch (PDOException $e) {
    $item_list = [];
    $order_map = [];
    $list_kasir = [];
    $total_barang_terjual = 0;
    $total_harga_jual = 0;
    $error_msg = $e->getMessage();
}

// Meta Informasi Laporan
$admin_user       = current_user();
$app_name         = function_exists('get_app_name') ? get_app_name() : 'QiOS Cendekia';
$yayasan_name     = function_exists('get_setting') ? get_setting('nama_yayasan', '') : '';
$pelapor_nama     = $admin_user['nama_lengkap'] ?? 'Administrator';
$pelapor_role     = ucfirst($admin_user['role'] ?? 'Administrator');
$bulan_indo       = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$tgl_lap_hari_ini = date('j') . ' ' . ($bulan_indo[(int)date('n')] ?? date('F')) . ' ' . date('Y');

// Ringkasan Periode untuk Subtitle
$filter_notes = [];
if ($date_start !== '' && $date_end !== '') {
    $filter_notes[] = 'Periode: ' . date('d/m/Y', strtotime($date_start)) . ' s/d ' . date('d/m/Y', strtotime($date_end));
} elseif ($date_start !== '') {
    $filter_notes[] = 'Mulai: ' . date('d/m/Y', strtotime($date_start));
} elseif ($date_end !== '') {
    $filter_notes[] = 'Sampai: ' . date('d/m/Y', strtotime($date_end));
}
if ($pelanggan_filter !== '') {
    $filter_notes[] = 'Pelanggan: ' . $pelanggan_filter;
}
$report_subtitle = 'Laporan Rincian Penjualan Barang' . (!empty($filter_notes) ? ' (' . implode(' | ', $filter_notes) . ')' : '');

// Data JSON untuk JavaScript Cetak
$print_rows = [];
foreach ($item_list as $it) {
    $print_rows[] = [
        'tanggal_transaksi' => date('d/m/Y H:i', strtotime($it['tanggal_transaksi'])),
        'kode_nota'         => $it['kode_pesanan'] ?? '',
        'nama_barang'       => $it['nama_produk'] ?? 'Barang',
        'jumlah'            => (int)($it['jumlah'] ?? 1),
        'satuan'            => !empty($it['kode_satuan']) ? $it['kode_satuan'] : 'Pcs',
        'harga_jual'        => (float)($it['harga_satuan'] ?? 0),
        'total_harga_jual'  => (float)($it['subtotal'] ?? 0),
        'kasir'             => $it['nama_kasir'] ?? 'Kasir',
        'pelanggan'         => $it['nama_pembeli'] ?? 'Umum',
    ];
}
?>

<!-- HEADER & ACTION BAR -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3 no-print">
    <div>
        <h3 class="fw-bold text-white mb-1">
            <i class="bi bi-receipt-cutoff text-info me-2"></i>Laporan Penjualan Barang
        </h3>
        <p class="text-secondary mb-0">Rincian produk terjual, kuantitas, harga jual, dan rekapitulasi penjualan.</p>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="btn-group btn-group-sm rounded-pill overflow-hidden border border-secondary shadow-sm" role="group" aria-label="Orientasi Laporan">
            <input type="radio" class="btn-check" name="reportOrientation" id="orientPortrait" value="portrait" autocomplete="off" checked onchange="saveReportOrientation('portrait')">
            <label class="btn btn-outline-info px-3 py-2 fw-semibold border-0" for="orientPortrait" title="Orientasi Portrait (Tegak)">
                <i class="bi bi-file-earmark me-1"></i>Portrait
            </label>

            <input type="radio" class="btn-check" name="reportOrientation" id="orientLandscape" value="landscape" autocomplete="off" onchange="saveReportOrientation('landscape')">
            <label class="btn btn-outline-info px-3 py-2 fw-semibold border-0" for="orientLandscape" title="Orientasi Landscape (Mendatar)">
                <i class="bi bi-file-earmark me-1" style="display: inline-block; transform: rotate(90deg);"></i>Landscape
            </label>
        </div>

        <button type="button" onclick="cetakLaporanTransaksi()" class="btn btn-outline-info btn-sm rounded-pill px-3 py-2 shadow-sm fw-semibold">
            <i class="bi bi-printer me-1"></i> Cetak
        </button>
        <button type="button" onclick="exportLaporanTransaksi('pdf')" class="btn btn-outline-danger btn-sm rounded-pill px-3 py-2 shadow-sm fw-semibold">
            <i class="bi bi-file-earmark-pdf-fill me-1"></i> PDF
        </button>
        <button type="button" onclick="exportLaporanTransaksi('xlsx')" class="btn btn-outline-success btn-sm rounded-pill px-3 py-2 shadow-sm fw-semibold">
            <i class="bi bi-file-earmark-excel-fill me-1"></i> Excel
        </button>
    </div>
</div>

<?php if (isset($error_msg)): ?>
    <div class="alert alert-danger shadow-sm"><?= e($error_msg) ?></div>
<?php endif; ?>

<!-- KOP INFORMASI LAPORAN (Logo, Yayasan / Aplikasi, Tanggal Laporan, Pelapor) -->
<div class="card border-secondary shadow-sm rounded-4 p-3 mb-4 no-print" style="background-color: var(--bg-card);">
    <div class="row align-items-center g-3">
        <div class="col-auto">
            <div class="p-2 rounded-3 border border-secondary d-flex align-items-center justify-content-center" style="width: 64px; height: 64px; background-color: var(--bg-secondary);">
                <img src="<?= $logo_url_admin ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
            </div>
        </div>
        <div class="col">
            <div class="text-secondary small text-uppercase fw-bold letter-spacing-1">
                <?= !empty($yayasan_name) ? e($yayasan_name) : 'Kios & Kasir POS' ?>
            </div>
            <h4 class="fw-bold mb-1" style="color: var(--text-main);"><?= e($app_name) ?></h4>
            <div class="d-flex flex-wrap gap-3 small text-secondary">
                <span><i class="bi bi-calendar-event text-info me-1"></i><strong>Tanggal Laporan:</strong> <?= e($tgl_lap_hari_ini) ?></span>
                <span><i class="bi bi-person-badge text-warning me-1"></i><strong>Pelapor:</strong> <?= e($pelapor_nama) ?> (<?= e($pelapor_role) ?>)</span>
            </div>
        </div>
    </div>
</div>

<!-- Form Filter & Pencarian -->
<div class="card border-secondary p-3 mb-4 rounded-4 no-print">
    <form action="transaksi.php" method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-semibold text-secondary mb-1">Cari Barang / Nota / Pembeli</label>
            <input type="text" name="search" class="form-control form-control-sm bg-dark border-secondary text-white rounded-3" placeholder="Nama barang, kode nota, kasir..." value="<?= e($search) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-secondary mb-1">Pelanggan</label>
            <select name="pelanggan" class="form-select form-select-sm bg-dark border-secondary text-white rounded-3">
                <option value="">Semua Pelanggan</option>
                <option value="Umum" <?= ($pelanggan_filter === 'Umum') ? 'selected' : '' ?>>Umum</option>
                <option value="Siswa" <?= ($pelanggan_filter === 'Siswa') ? 'selected' : '' ?>>Siswa</option>
                <option value="Guru" <?= ($pelanggan_filter === 'Guru') ? 'selected' : '' ?>>Guru</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-secondary mb-1">Petugas Kasir</label>
            <select name="kasir_id" class="form-select form-select-sm bg-dark border-secondary text-white rounded-3">
                <option value="">Semua Petugas</option>
                <?php foreach ($list_kasir as $lk): ?>
                    <option value="<?= (int)$lk['id'] ?>" <?= ($kasir_id === (string)$lk['id']) ? 'selected' : '' ?>>
                        <?= e($lk['nama_lengkap']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-secondary mb-1">Tanggal Mulai</label>
            <input type="date" name="date_start" class="form-control form-control-sm bg-dark border-secondary text-white rounded-3" value="<?= e($date_start) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-secondary mb-1">Tanggal Sampai</label>
            <input type="date" name="date_end" class="form-control form-control-sm bg-dark border-secondary text-white rounded-3" value="<?= e($date_end) ?>">
        </div>
        <div class="col-md-1 d-flex gap-1 align-items-end">
            <button type="submit" class="btn btn-primary btn-sm rounded-3 w-100" title="Filter Laporan">
                <i class="bi bi-funnel"></i>
            </button>
            <?php if ($search !== '' || $pelanggan_filter !== '' || $kasir_id !== '' || $date_start !== '' || $date_end !== ''): ?>
                <a href="transaksi.php" class="btn btn-outline-secondary btn-sm rounded-3" title="Reset Filter"><i class="bi bi-arrow-counterclockwise"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Tabel Laporan Penjualan Barang -->
<div class="card border-secondary shadow rounded-4 overflow-hidden mb-5">
    <div class="table-responsive">
        <table class="table table-hover table-dark align-middle mb-0">
            <thead class="table-secondary">
                <tr>
                    <th scope="col" style="width: 50px;" class="text-center">No</th>
                    <th scope="col" style="width: 140px;">Tanggal Transaksi</th>
                    <th scope="col" style="width: 150px;">Kode Nota</th>
                    <th scope="col">Nama Barang yang Terjual</th>
                    <th scope="col" class="text-center" style="width: 110px;">Jumlah</th>
                    <th scope="col" class="text-end" style="width: 140px;">Harga Jual</th>
                    <th scope="col" class="text-end" style="width: 150px;">Total Harga Jual</th>
                    <th scope="col" style="width: 130px;">Kasir</th>
                    <th scope="col" class="text-center no-print" style="width: 90px;">Struk</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($item_list)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-secondary">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            Tidak ada data penjualan barang yang sesuai filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($item_list as $row): ?>
                        <?php 
                        $orderData = $order_map[$row['pesanan_id']] ?? null;
                        $uom = !empty($row['kode_satuan']) ? $row['kode_satuan'] : 'Pcs';
                        ?>
                        <tr>
                            <td class="text-center text-secondary small"><?= $no++ ?></td>
                            <td class="text-secondary small text-nowrap">
                                <?= date('d/m/Y H:i', strtotime($row['tanggal_transaksi'])) ?>
                            </td>
                            <td class="fw-bold font-monospace text-info small">
                                <?= e($row['kode_pesanan']) ?>
                            </td>
                            <td class="fw-semibold text-white">
                                <?= e($row['nama_produk']) ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary bg-opacity-50 text-light px-2 py-1">
                                    <?= (int)$row['jumlah'] ?> <?= e($uom) ?>
                                </span>
                            </td>
                            <td class="text-end text-light font-monospace">
                                <?= rupiah($row['harga_satuan']) ?>
                            </td>
                            <td class="text-end fw-bold text-success font-monospace">
                                <?= rupiah($row['subtotal']) ?>
                            </td>
                            <td class="small text-secondary text-truncate" style="max-width: 120px;" title="<?= e($row['nama_kasir'] ?? 'Kasir') ?>">
                                <i class="bi bi-person me-1"></i><?= e($row['nama_kasir'] ?? 'Kasir') ?>
                            </td>
                            <td class="text-center no-print">
                                <?php if ($orderData): ?>
                                    <button type="button" 
                                            class="btn btn-outline-info btn-sm rounded-pill px-2 py-1"
                                            title="Lihat Struk & Rincian Nota"
                                            onclick='showDetailModal(<?= json_encode($orderData, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($orderData['items'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <i class="bi bi-receipt"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($item_list)): ?>
                <tfoot class="table-dark border-top border-secondary">
                    <tr class="fw-bold">
                        <td colspan="4" class="text-end py-3 text-uppercase text-light">
                            <i class="bi bi-calculator me-2 text-info"></i>Total Keseluruhan :
                        </td>
                        <td class="text-center py-3 text-info fs-6">
                            <?= number_format($total_barang_terjual, 0, ',', '.') ?> <span class="small fw-normal text-secondary">Pcs</span>
                        </td>
                        <td class="text-end py-3 text-secondary">-</td>
                        <td class="text-end py-3 text-success font-monospace fs-6">
                            <?= rupiah($total_harga_jual) ?>
                        </td>
                        <td colspan="2" class="text-secondary small py-3 no-print">
                            (<?= count($order_map) ?> Nota)
                        </td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- MODAL DETAIL & STRUK NOTA -->
<div class="modal fade" id="modalDetailTransaksi" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-dark border-secondary text-white shadow-lg rounded-4">
            <div class="modal-header border-secondary bg-dark">
                <h5 class="modal-title fw-bold" id="detail_modal_title">
                    <i class="bi bi-receipt me-2 text-info"></i>Rincian Nota Transaksi
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-2 mb-3 p-3 rounded-3 border border-secondary small" style="background-color: var(--bg-secondary);">
                    <div class="col-sm-6">
                        <span class="text-secondary">Kode Nota Transaksi:</span>
                        <div class="fw-bold text-info font-monospace fs-6" id="modal_kode"></div>
                    </div>
                    <div class="col-sm-6">
                        <span class="text-secondary">Kasir yang Melayani:</span>
                        <div class="fw-bold fs-6" style="color: var(--text-main);" id="modal_kasir"></div>
                    </div>
                    <div class="col-sm-6 mt-2">
                        <span class="text-secondary">Nama Pelanggan:</span>
                        <div style="color: var(--text-main);" id="modal_pembeli"></div>
                    </div>
                    <div class="col-sm-6 mt-2">
                        <span class="text-secondary">Waktu Transaksi:</span>
                        <div class="text-secondary" id="modal_waktu"></div>
                    </div>
                </div>

                <h6 class="fw-bold text-white mb-2">Item Barang dalam Nota Ini:</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-dark table-striped align-middle">
                        <thead class="table-secondary">
                            <tr>
                                <th>Nama Barang</th>
                                <th class="text-center">Jumlah</th>
                                <th class="text-end">Harga Jual</th>
                                <th class="text-end">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody id="modal_items_table">
                            <!-- Injected by JavaScript -->
                        </tbody>
                        <tfoot>
                            <tr class="border-top border-secondary">
                                <th colspan="3" class="text-end fs-6 pt-3 text-secondary">Total Tagihan:</th>
                                <th class="text-end fs-6 text-success pt-3 font-monospace" id="modal_total_harga"></th>
                            </tr>
                            <tr>
                                <th colspan="3" class="text-end text-secondary">Uang Diterima:</th>
                                <th class="text-end text-white font-monospace" id="modal_uang_bayar"></th>
                            </tr>
                            <tr>
                                <th colspan="3" class="text-end text-secondary">Kembalian:</th>
                                <th class="text-end text-info font-monospace" id="modal_kembalian"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-secondary justify-content-between">
                <button type="button" class="btn btn-outline-info rounded-pill px-3" onclick="cetakStruk58mmFromAdmin()">
                    <i class="bi bi-printer me-1"></i> Cetak Struk (58mm)
                </button>
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentTrx = null;
let currentItems = null;

function formatRupiahJs(number) {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(number);
}

function showDetailModal(trx, items) {
    currentTrx = trx;
    currentItems = items;

    document.getElementById('modal_kode').innerText = trx.kode_pesanan;
    document.getElementById('modal_kasir').innerText = trx.nama_kasir || 'Kasir';
    document.getElementById('modal_pembeli').innerText = trx.nama_pembeli;
    document.getElementById('modal_waktu').innerText = trx.created_at;
    document.getElementById('modal_total_harga').innerText = formatRupiahJs(trx.total_harga);
    document.getElementById('modal_uang_bayar').innerText = formatRupiahJs(trx.uang_bayar || trx.total_harga);
    document.getElementById('modal_kembalian').innerText = formatRupiahJs(trx.kembalian || 0);

    var tbody = document.getElementById('modal_items_table');
    tbody.innerHTML = '';

    if (items && items.length > 0) {
        items.forEach(function(item) {
            var tr = document.createElement('tr');
            var uom = item.kode_satuan || 'Pcs';
            tr.innerHTML = `
                <td class="fw-semibold text-white">${item.nama_produk}</td>
                <td class="text-center">${item.jumlah} ${uom}</td>
                <td class="text-end text-secondary font-monospace">${formatRupiahJs(item.harga_satuan)}</td>
                <td class="text-end fw-bold text-success font-monospace">${formatRupiahJs(item.subtotal)}</td>
            `;
            tbody.appendChild(tr);
        });
    } else {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada detail item tersedia.</td></tr>';
    }

    new bootstrap.Modal(document.getElementById('modalDetailTransaksi')).show();
}

function cetakStruk58mmFromAdmin() {
    if (!currentTrx) return;
    const w = window.open('', '_blank', 'width=380,height=600');
    let itemsHtml = '';
    (currentItems || []).forEach(it => {
        itemsHtml += `
            <div style="margin-bottom: 3px;">
                <div style="font-weight: bold; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${it.nama_produk}</div>
                <div style="display: flex; justify-content: space-between; color: #222; font-size: 10px;">
                    <span>${it.jumlah} x ${formatRupiahJs(it.harga_satuan)}</span>
                    <span style="font-weight: bold;">${formatRupiahJs(it.subtotal)}</span>
                </div>
            </div>
        `;
    });

    const appName = <?= json_encode($app_name) ?>;
    const receiptHtml = `
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Struk 58mm - ${currentTrx.kode_pesanan}</title>
    <style>
        @page { size: auto; margin: 0; }
        body {
            width: 58mm;
            max-width: 58mm;
            margin: 0;
            padding: 2mm 3mm 14mm 2mm;
            box-sizing: border-box;
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            line-height: 1.25;
            color: #000;
            background: #fff;
        }
        .text-center { text-align: center; }
        .dashed { border-top: 1px dashed #000; margin: 4px 0; }
        .flex { display: flex; justify-content: space-between; }
        .bold { font-weight: bold; }
    </style>
</head>
<body onload="window.print(); setTimeout(() => window.close(), 1000);">
    <div class="text-center">
        <div style="font-size: 13px; font-weight: bold;">${appName.toUpperCase()}</div>
        <div style="font-size: 9px; color: #555;">Sistem POS Kasir Mandiri</div>
        <div style="font-size: 9px; color: #555;">${currentTrx.created_at}</div>
    </div>
    <div class="dashed"></div>
    <div style="font-size: 10px;">
        <div class="flex"><span>No.TRX:</span><span class="bold">${currentTrx.kode_pesanan}</span></div>
        <div class="flex"><span>Kasir :</span><span>${currentTrx.nama_kasir || 'Kasir'}</span></div>
        <div class="flex"><span>Plggn :</span><span>${currentTrx.nama_pembeli}</span></div>
    </div>
    <div class="dashed"></div>
    ${itemsHtml}
    <div class="dashed"></div>
    <div style="font-size: 11px;">
        <div class="flex bold" style="font-size: 12px;"><span>TOTAL:</span><span>${formatRupiahJs(currentTrx.total_harga)}</span></div>
        <div class="flex"><span>TUNAI:</span><span>${formatRupiahJs(currentTrx.uang_bayar || currentTrx.total_harga)}</span></div>
        <div class="flex bold"><span>KEMBALI:</span><span>${formatRupiahJs(currentTrx.kembalian || 0)}</span></div>
    </div>
    <div class="dashed"></div>
    <div class="text-center" style="font-size: 9.5px;">
        <div>*** TERIMA KASIH ***</div>
        <div>Barang yang sudah dibeli</div>
        <div>tidak dapat ditukar/dikembalikan</div>
    </div>
    <div style="height: 14mm;"></div>
</body>
</html>
    `;
    w.document.write(receiptHtml);
    w.document.close();
}

const reportRows = <?= json_encode($print_rows, JSON_UNESCAPED_UNICODE) ?>;
const reportMeta = <?= json_encode([
    'appName'     => $app_name,
    'yayasanName' => $yayasan_name,
    'reportDate'  => $tgl_lap_hari_ini,
    'pelapor'     => $pelapor_nama,
    'pelaporRole' => $pelapor_role,
    'subtitle'    => $report_subtitle,
    'logoPath'    => $logo_url_admin ?? ('../assets/images/logo.png?v=' . (int)$logo_v),
], JSON_UNESCAPED_UNICODE) ?>;

function escapeHtmlPrint(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function getSelectedReportOrientation() {
    const checked = document.querySelector('input[name="reportOrientation"]:checked');
    return (checked && checked.value === 'landscape') ? 'landscape' : 'portrait';
}

function saveReportOrientation(orient) {
    try {
        localStorage.setItem('qios_report_orientation', orient);
    } catch (e) {}
}

document.addEventListener('DOMContentLoaded', function() {
    try {
        const savedOrient = localStorage.getItem('qios_report_orientation');
        if (savedOrient === 'landscape') {
            const el = document.getElementById('orientLandscape');
            if (el) el.checked = true;
        }
    } catch (e) {}
});

function exportLaporanTransaksi(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('format', format);
    params.set('orientation', getSelectedReportOrientation());
    window.location.href = 'export_transaksi.php?' + params.toString();
}

function buildLaporanHtmlDoc(orientation) {
    const isLandscape = (orientation === 'landscape');
    const logoUrl = new URL(reportMeta.logoPath, window.location.href).href;
    let totalJumlah = 0;
    let totalHargaJual = 0;

    let rowsHtml = '';
    if (!reportRows || reportRows.length === 0) {
        rowsHtml = '<tr><td colspan="7" class="text-center" style="padding: 18px; color: #555;">Tidak ada data penjualan barang pada daftar.</td></tr>';
    } else {
        reportRows.forEach(function(row, idx) {
            totalJumlah += Number(row.jumlah || 0);
            totalHargaJual += Number(row.total_harga_jual || 0);

            rowsHtml += `
                <tr>
                    <td class="text-center">${idx + 1}</td>
                    <td class="text-center text-nowrap">${escapeHtmlPrint(row.tanggal_transaksi)}</td>
                    <td class="mono text-center">${escapeHtmlPrint(row.kode_nota)}</td>
                    <td>${escapeHtmlPrint(row.nama_barang)}</td>
                    <td class="text-center text-nowrap">${row.jumlah} ${escapeHtmlPrint(row.satuan || 'Pcs')}</td>
                    <td class="text-right mono text-nowrap">${formatRupiahJs(row.harga_jual)}</td>
                    <td class="text-right mono text-nowrap">${formatRupiahJs(row.total_harga_jual)}</td>
                </tr>
            `;
        });
    }

    const tfootHtml = (reportRows && reportRows.length > 0) ? `
        <tfoot>
            <tr>
                <td colspan="4" class="text-right bold">TOTAL KESELURUHAN :</td>
                <td class="text-center bold text-nowrap">${totalJumlah} Pcs</td>
                <td class="text-right bold">-</td>
                <td class="text-right mono bold text-nowrap">${formatRupiahJs(totalHargaJual)}</td>
            </tr>
        </tfoot>
    ` : '';

    const colWidths = isLandscape
        ? ['4%', '15%', '15%', '32%', '10%', '12%', '12%']
        : ['5%', '18%', '16%', '27%', '10%', '12%', '12%'];

    const colgroupHtml = '<colgroup>' + colWidths.map(w => `<col style="width: ${w};">`).join('') + '</colgroup>';

    const yayasanHeaderHtml = reportMeta.yayasanName 
        ? `<div class="yayasan-name">${escapeHtmlPrint(reportMeta.yayasanName)}</div>` 
        : '';

    return `<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Penjualan Barang - ${escapeHtmlPrint(reportMeta.appName)}</title>
    <style>
        @page {
            size: A4 ${orientation};
            margin: ${isLandscape ? '10mm 12mm' : '10mm 10mm'};
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            max-width: 100%;
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #000;
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .print-sheet {
            width: 100%;
            max-width: 100%;
            padding: 0 2px;
        }
        .report-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 2px solid #000;
        }
        .logo-box {
            width: 65px;
            height: 65px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .logo-box img {
            max-width: 65px;
            max-height: 65px;
            object-fit: contain;
        }
        .header-content {
            flex-grow: 1;
        }
        .yayasan-name {
            font-size: ${isLandscape ? '13px' : '12px'};
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #222;
        }
        .app-name {
            font-size: ${isLandscape ? '22px' : '19px'};
            font-weight: 800;
            line-height: 1.2;
            color: #000;
        }
        .report-sub {
            font-size: ${isLandscape ? '12px' : '11px'};
            color: #444;
            margin-top: 2px;
        }
        .report-meta-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: ${isLandscape ? '11px' : '10px'};
            color: #333;
        }
        table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            font-size: ${isLandscape ? '10.5px' : '9.5px'};
        }
        thead { display: table-header-group; }
        tfoot { display: table-row-group; }
        tr { page-break-inside: avoid; }
        th, td {
            border: 1px solid #000;
            padding: ${isLandscape ? '6px 7px' : '5px 4px'};
            overflow-wrap: break-word;
            word-wrap: break-word;
        }
        thead th {
            background-color: #ededed;
            font-weight: 700;
            text-align: center;
            color: #000;
        }
        tfoot td {
            background-color: #f7f7f7;
            font-weight: 700;
        }
        .bold { font-weight: 700; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-nowrap { white-space: nowrap; }
        .mono {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: ${isLandscape ? '10px' : '9px'};
        }
        .signature-wrap {
            margin-top: 24px;
            display: flex;
            justify-content: flex-end;
            page-break-inside: avoid;
        }
        .signature-box {
            width: 220px;
            text-align: left;
            font-size: ${isLandscape ? '12px' : '11px'};
            line-height: 1.4;
        }
        .signature-space {
            height: 48px;
        }
        .signature-name {
            font-weight: 700;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="print-sheet">
        <div class="report-header">
            <div class="logo-box">
                <img id="printLogoImg" src="${escapeHtmlPrint(logoUrl)}" alt="Logo">
            </div>
            <div class="header-content">
                ${yayasanHeaderHtml}
                <div class="app-name">${escapeHtmlPrint(reportMeta.appName)}</div>
                <div class="report-sub">${escapeHtmlPrint(reportMeta.subtitle)}</div>
            </div>
        </div>

        <div class="report-meta-info">
            <div><strong>Tanggal Laporan:</strong> ${escapeHtmlPrint(reportMeta.reportDate)}</div>
            <div><strong>Pelapor:</strong> ${escapeHtmlPrint(reportMeta.pelapor)} (${escapeHtmlPrint(reportMeta.pelaporRole)})</div>
        </div>

        <table>
            ${colgroupHtml}
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tanggal Transaksi</th>
                    <th>Kode Nota</th>
                    <th>Nama Barang yang Terjual</th>
                    <th>Jumlah</th>
                    <th>Harga Jual</th>
                    <th>Total Harga Jual</th>
                </tr>
            </thead>
            <tbody>
                ${rowsHtml}
            </tbody>
            ${tfootHtml}
        </table>

        <div class="signature-wrap">
            <div class="signature-box">
                <div>Tanggal: ${escapeHtmlPrint(reportMeta.reportDate)}</div>
                <div>Pelapor / Penanggung Jawab,</div>
                <div class="signature-space"></div>
                <div class="signature-name">${escapeHtmlPrint(reportMeta.pelapor)}</div>
                <div>${escapeHtmlPrint(reportMeta.pelaporRole)}</div>
            </div>
        </div>
    </div>
</body>
</html>`;
}

function cetakLaporanTransaksi() {
    const orientation = getSelectedReportOrientation();
    const isLandscape = (orientation === 'landscape');
    const htmlDoc = buildLaporanHtmlDoc(orientation);

    let frame = document.getElementById('printReportFrame');
    if (frame) frame.remove();

    frame = document.createElement('iframe');
    frame.id = 'printReportFrame';
    frame.style.position = 'fixed';
    frame.style.left = '-10000px';
    frame.style.top = '0';
    frame.style.width = isLandscape ? '1060px' : '760px';
    frame.style.height = '600px';
    frame.style.border = '0';
    frame.style.visibility = 'hidden';
    document.body.appendChild(frame);

    const doc = frame.contentWindow.document;
    doc.open();
    doc.write(htmlDoc);
    doc.close();

    let printed = false;
    const triggerPrint = () => {
        if (printed) return;
        printed = true;
        frame.contentWindow.focus();
        frame.contentWindow.print();
    };

    const img = doc.getElementById('printLogoImg');
    if (img && !img.complete) {
        img.onload = () => setTimeout(triggerPrint, 100);
        img.onerror = () => setTimeout(triggerPrint, 100);
        setTimeout(triggerPrint, 1200);
    } else {
        setTimeout(triggerPrint, 150);
    }
}
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
