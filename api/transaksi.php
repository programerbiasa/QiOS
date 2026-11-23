<?php
require_once __DIR__ . '/init.php';

$method = $_SERVER['REQUEST_METHOD'];

// 1. BUAT TRANSAKSI / CHECKOUT BARU (POST)
if ($method === 'POST') {
    $input = get_json_input();

    $user_id      = (int)($input['user_id'] ?? 1);
    $nama_pembeli = trim($input['nama_pembeli'] ?? 'Umum');
    if ($nama_pembeli === '') $nama_pembeli = 'Umum';

    $uang_bayar   = (float)($input['uang_bayar'] ?? 0);
    $cart_items   = $input['items'] ?? [];

    if (empty($cart_items) || !is_array($cart_items)) {
        api_response(false, 'Keranjang belanja tidak boleh kosong!', null, 400);
    }

    try {
        $pdo->beginTransaction();

        $total_belanja   = 0;
        $items_validated = [];

        // 1. Kunci baris & validasi stok terkini
        foreach ($cart_items as $item) {
            $pid = (int)($item['id'] ?? ($item['produk_id'] ?? 0));
            $qty = (int)($item['qty'] ?? ($item['jumlah'] ?? 0));

            if ($pid <= 0 || $qty <= 0) continue;

            $stmt = $pdo->prepare("
                SELECT p.id, p.nama_produk, p.harga, p.stok, s.kode_satuan 
                FROM produk p
                LEFT JOIN satuan s ON p.satuan_id = s.id
                WHERE p.id = ? 
                FOR UPDATE
            ");
            $stmt->execute([$pid]);
            $prod = $stmt->fetch();

            if (!$prod) {
                throw new Exception("Produk ID #{$pid} tidak ditemukan di database.");
            }

            if ((int)$prod['stok'] < $qty) {
                $satuan = $prod['kode_satuan'] ?? 'pcs';
                throw new Exception("Stok '{$prod['nama_produk']}' tidak mencukupi! Sisa stok: {$prod['stok']} {$satuan}.");
            }

            $subtotal = $qty * (float)$prod['harga'];
            $total_belanja += $subtotal;

            $items_validated[] = [
                'produk_id'   => (int)$prod['id'],
                'nama_produk' => $prod['nama_produk'],
                'harga'       => (float)$prod['harga'],
                'jumlah'      => $qty,
                'subtotal'    => $subtotal,
                'stok_sisa'   => (int)$prod['stok'] - $qty
            ];
        }

        if (empty($items_validated)) {
            throw new Exception("Tidak ada item pesanan yang valid.");
        }

        // Validasi kecukupan uang bayar
        if ($uang_bayar < $total_belanja) {
            throw new Exception("Uang pembayaran kurang! Total: Rp " . number_format($total_belanja, 0, ',', '.') . ", Diterima: Rp " . number_format($uang_bayar, 0, ',', '.'));
        }

        $kembalian = $uang_bayar - $total_belanja;
        $kode_pesanan = 'TRX-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

        // 2. Simpan Header Pesanan
        $stmt_order = $pdo->prepare("
            INSERT INTO pesanan (kode_pesanan, user_id, nama_pembeli, total_harga, uang_bayar, kembalian, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'SELESAI', NOW())
        ");
        $stmt_order->execute([
            $kode_pesanan,
            $user_id,
            $nama_pembeli,
            $total_belanja,
            $uang_bayar,
            $kembalian
        ]);
        $pesanan_id = (int)$pdo->lastInsertId();

        // 3. Simpan Detail Pesanan & Potong Stok
        $stmt_detail = $pdo->prepare("
            INSERT INTO pesanan_detail (pesanan_id, produk_id, nama_produk, harga_satuan, jumlah, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt_update_stok = $pdo->prepare("
            UPDATE produk SET stok = stok - ? WHERE id = ?
        ");

        foreach ($items_validated as $it) {
            $stmt_detail->execute([
                $pesanan_id,
                $it['produk_id'],
                $it['nama_produk'],
                $it['harga'],
                $it['jumlah'],
                $it['subtotal']
            ]);
            $stmt_update_stok->execute([
                $it['jumlah'],
                $it['produk_id']
            ]);
        }

        $pdo->commit();

        // Ambil nama kasir
        $stmt_u = $pdo->prepare("SELECT nama_lengkap FROM users WHERE id = ?");
        $stmt_u->execute([$user_id]);
        $kasir_row = $stmt_u->fetch();
        $nama_kasir = $kasir_row['nama_lengkap'] ?? 'Petugas Kasir';

        // Kembalikan objek data terstruktur untuk aplikasi (bisa langsung dicetak via printer bluetooth)
        $receipt_data = [
            'transaksi_id' => $pesanan_id,
            'kode_pesanan' => $kode_pesanan,
            'waktu'        => date('d/m/Y H:i:s') . ' WIB',
            'kasir'        => $nama_kasir,
            'pelanggan'    => $nama_pembeli,
            'items'        => $items_validated,
            'total_harga'  => $total_belanja,
            'uang_bayar'   => $uang_bayar,
            'kembalian'    => $kembalian
        ];

        api_response(true, "Transaksi {$kode_pesanan} berhasil diproses!", $receipt_data, 201);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        api_response(false, $e->getMessage(), null, 400);
    }
}

// 2. RIWAYAT TRANSAKSI (GET)
if ($method === 'GET') {
    $limit     = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $pelanggan = trim($_GET['pelanggan'] ?? '');
    $date      = trim($_GET['date'] ?? '');

    $sql = "
        SELECT 
            p.id, 
            p.kode_pesanan, 
            p.user_id, 
            u.nama_lengkap AS nama_kasir,
            p.nama_pembeli, 
            p.total_harga, 
            p.uang_bayar, 
            p.kembalian, 
            p.status, 
            p.created_at
        FROM pesanan p
        LEFT JOIN users u ON p.user_id = u.id
        WHERE 1=1
    ";
    $params = [];

    if ($pelanggan !== '') {
        $sql .= " AND p.nama_pembeli = ?";
        $params[] = $pelanggan;
    }

    if ($date !== '') {
        $sql .= " AND DATE(p.created_at) = ?";
        $params[] = $date;
    }

    $sql .= " ORDER BY p.created_at DESC LIMIT {$limit}";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        foreach ($orders as &$ord) {
            $ord['id']          = (int)$ord['id'];
            $ord['user_id']     = (int)$ord['user_id'];
            $ord['total_harga'] = (float)$ord['total_harga'];
            $ord['uang_bayar']  = (float)$ord['uang_bayar'];
            $ord['kembalian']   = (float)$ord['kembalian'];
        }

        api_response(true, 'Data riwayat transaksi berhasil diambil', [
            'total' => count($orders),
            'items' => $orders
        ]);
    } catch (PDOException $e) {
        api_response(false, 'Database Error: ' . $e->getMessage(), null, 500);
    }
}

api_response(false, 'Metode HTTP tidak didukung', null, 405);
