<?php
require_once __DIR__ . '/init.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    api_response(false, 'Metode HTTP tidak diizinkan. Gunakan POST untuk autentikasi.', null, 405);
}

$input = get_json_input();
$action = $input['action'] ?? 'login';

// 1. LOGOUT SESSION
if ($action === 'logout') {
    $user_id = (int)($input['user_id'] ?? 0);
    $token   = trim($input['token'] ?? '');

    if ($user_id > 0) {
        try {
            $stmt_logout = $pdo->prepare("
                UPDATE users 
                SET active_session_token = NULL, 
                    active_device_name = NULL, 
                    last_active_at = NULL
                WHERE id = ?
            ");
            $stmt_logout->execute([$user_id]);
        } catch (PDOException $e) {
            // Abaikan error DB ringan
        }
    }
    api_response(true, 'Sesi berhasil logout.', null);
}

// 2. HEARTBEAT / SESSION CHECK (Memperbarui status aktif tanpa memblokir/mengeluarkan perangkat lain)
if ($action === 'heartbeat') {
    $user_id = (int)($input['user_id'] ?? 0);

    if ($user_id > 0) {
        try {
            $stmt_up = $pdo->prepare("UPDATE users SET last_active_at = NOW(), last_seen = NOW() WHERE id = ?");
            $stmt_up->execute([$user_id]);
        } catch (PDOException $e) {
            // Abaikan error DB ringan
        }
    }
    // Selalu valid agar akun dapat diakses dari beberapa perangkat secara bebas tanpa saling mengganggu
    api_response(true, 'Sesi aktif', ['is_valid' => true]);
}

// 3. LOGIN PETUGAS KASIR / ADMIN
$username    = trim($input['username'] ?? '');
$password    = $input['password'] ?? '';
$device_name = trim($input['device_name'] ?? 'Komputer Windows');

if (empty($username) || empty($password)) {
    api_response(false, 'Username dan password wajib diisi!', null, 400);
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            id, username, password, nama_lengkap, role, 
            active_session_token, active_device_name, last_active_at, created_at 
        FROM users 
        WHERE username = ? 
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $is_valid = false;
    if ($user) {
        if (password_verify($password, $user['password'])) {
            $is_valid = true;
        } elseif ($password === $user['password']) {
            // Re-hash password otomatis jika masih plaintext
            $is_valid = true;
            $new_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt_rehash = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt_rehash->execute([$new_hash, $user['id']]);
        }
    }

    if (!$is_valid) {
        api_response(false, 'Username atau password tidak sesuai!', null, 401);
    }

    // Token sesi baru
    $token = bin2hex(random_bytes(24));
    try {
        $stmt_up = $pdo->prepare("
            UPDATE users 
            SET active_session_token = ?, active_device_name = ?, last_active_at = NOW(), last_seen = NOW() 
            WHERE id = ?
        ");
        $stmt_up->execute([$token, $device_name, $user['id']]);
    } catch (PDOException $e) {
        // Abaikan jika kolom opsional
    }

    api_response(true, 'Login berhasil!', [
        'user'  => [
            'id'           => (int)$user['id'],
            'username'     => $user['username'],
            'nama_lengkap' => $user['nama_lengkap'],
            'role'         => $user['role']
        ],
        'token' => $token
    ]);
} catch (PDOException $e) {
    api_response(false, 'Database Error: ' . $e->getMessage(), null, 500);
}
