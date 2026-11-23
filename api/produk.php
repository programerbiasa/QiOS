<?php
require_once __DIR__ . '/init.php';

$method = $_SERVER['REQUEST_METHOD'];
$base_url = get_base_url();

// 1. DETAIL SINGLE PRODUK
if ($method === 'GET' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("
            SELECT 
                p.id, 
                p.kategori_id, 
                k.nama_kategori,
                p.satuan_id, 
                s.nama_satuan, 
                s.kode_satuan,
                p.nama_produk, 
                p.barcode,
                p.harga, 
                p.stok, 
                p.deskripsi, 
                p.foto,
                p.created_at,
                p.updated_at
            FROM produk p
            LEFT JOIN kategori k ON p.kategori_id = k.id
            LEFT JOIN satuan s ON p.satuan_id = s.id
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $prod = $stmt->fetch();

        if ($prod) {
            $prod['id']          = (int)$prod['id'];
            $prod['kategori_id'] = (int)$prod['kategori_id'];
            $prod['satuan_id']   = (int)$prod['satuan_id'];
            $prod['harga']       = (float)$prod['harga'];
            $prod['stok']        = (int)$prod['stok'];
            $default_logo_api = function_exists('get_app_logo') ? get_app_logo('') : 'assets/images/logo.png';
            $prod['foto_url']    = !empty($prod['foto']) ? $base_url . $prod['foto'] : $base_url . $default_logo_api;
            api_response(true, 'Detail produk ditemukan', $prod);
        } else {
            api_response(false, 'Produk tidak ditemukan', null, 404);
        }
    } catch (PDOException $e) {
        api_response(false, 'Database Error: ' . $e->getMessage(), null, 500);
    }
}

// 2. DAFTAR PRODUK (LIST DENGAN FILTER & SEARCH)
if ($method === 'GET') {
    $search    = trim($_GET['search'] ?? '');
    $kat_id    = trim($_GET['kat_id'] ?? '');
    $in_stock  = trim($_GET['in_stock'] ?? '');

    $sql = "
        SELECT 
            p.id, 
            p.kategori_id, 
            k.nama_kategori,
            p.satuan_id, 
            s.nama_satuan, 
            s.kode_satuan,
            p.nama_produk, 
            p.barcode,
            p.harga, 
            p.stok, 
            p.deskripsi, 
            p.foto,
            p.created_at
        FROM produk p
        LEFT JOIN kategori k ON p.kategori_id = k.id
        LEFT JOIN satuan s ON p.satuan_id = s.id
        WHERE 1=1
    ";
    $params = [];

    if ($search !== '') {
        $sql .= " AND (p.nama_produk LIKE ? OR p.barcode LIKE ? OR p.barcode = ? OR p.deskripsi LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = $search;
        $params[] = "%{$search}%";
    }

    if ($kat_id !== '' && is_numeric($kat_id)) {
        $sql .= " AND p.kategori_id = ?";
        $params[] = (int)$kat_id;
    }

    if ($in_stock === '1' || $in_stock === 'true') {
        $sql .= " AND p.stok > 0";
    }

    $sql .= " ORDER BY p.nama_produk ASC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        foreach ($products as &$p) {
            $p['id']          = (int)$p['id'];
            $p['kategori_id'] = (int)$p['kategori_id'];
            $p['satuan_id']   = (int)$p['satuan_id'];
            $p['harga']       = (float)$p['harga'];
            $p['stok']        = (int)$p['stok'];
            $p['kode_satuan'] = $p['kode_satuan'] ?? 'Pcs';
            $p['nama_satuan'] = $p['nama_satuan'] ?? 'Pcs';
            $default_logo_api = function_exists('get_app_logo') ? get_app_logo('') : 'assets/images/logo.png';
            $p['foto_url']    = !empty($p['foto']) ? $base_url . $p['foto'] : $base_url . $default_logo_api;
        }

        api_response(true, 'Data produk berhasil diambil', [
            'total' => count($products),
            'items' => $products
        ]);
    } catch (PDOException $e) {
        api_response(false, 'Database Error: ' . $e->getMessage(), null, 500);
    }
}

// 3. TAMBAH PRODUK BARU (POST)
if ($method === 'POST') {
    $input = get_json_input();
    $nama_produk = trim($input['nama_produk'] ?? '');
    $barcode     = trim($input['barcode'] ?? '');
    $kategori_id = (int)($input['kategori_id'] ?? 1);
    $satuan_id   = (int)($input['satuan_id'] ?? 1);
    $harga       = (float)($input['harga'] ?? 0);
    $stok        = (int)($input['stok'] ?? 0);
    $deskripsi   = trim($input['deskripsi'] ?? '');
    $foto        = trim($input['foto'] ?? '');

    if ($nama_produk === '' || $harga <= 0) {
        api_response(false, 'Nama produk dan harga wajib diisi dengan benar!', null, 400);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO produk (kategori_id, satuan_id, nama_produk, barcode, harga, stok, deskripsi, foto, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$kategori_id, $satuan_id, $nama_produk, $barcode !== '' ? $barcode : null, $harga, $stok, $deskripsi, $foto]);
        $new_id = (int)$pdo->lastInsertId();

        api_response(true, "Produk '{$nama_produk}' berhasil ditambahkan!", ['id' => $new_id], 201);
    } catch (PDOException $e) {
        api_response(false, 'Gagal menambahkan produk: ' . $e->getMessage(), null, 500);
    }
}

api_response(false, 'Metode HTTP tidak didukung', null, 405);
