<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// Baca input JSON jika ada
$raw_input = file_get_contents('php://input');
$json_input = [];
if (!empty($raw_input)) {
    $decoded = json_decode($raw_input, true);
    if (is_array($decoded)) {
        $json_input = $decoded;
    }
}

$user = null;
if (is_logged_in()) {
    $user = current_user();
} else {
    // Dukungan untuk client REST API (seperti aplikasi Windows Kasir Flutter)
    $req_user_id = (int)($_POST['user_id'] ?? ($_GET['user_id'] ?? ($json_input['user_id'] ?? 0)));
    if ($req_user_id > 0) {
        $stmt_u = $pdo->prepare("SELECT id, username, nama_lengkap, role FROM users WHERE id = ?");
        $stmt_u->execute([$req_user_id]);
        $user = $stmt_u->fetch(PDO::FETCH_ASSOC);
    }
    
    // Jika masih null, cari user kasir aktif
    if (!$user) {
        $stmt_u = $pdo->query("SELECT id, username, nama_lengkap, role FROM users WHERE role = 'kasir' LIMIT 1");
        $user = $stmt_u->fetch(PDO::FETCH_ASSOC);
    }
}

if (!$user) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Identitas pengguna tidak ditemukan.']);
    exit;
}

$user_id = (int)$user['id'];

// Update status online / last_seen
try {
    $stmt_seen = $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
    $stmt_seen->execute([$user_id]);
} catch (Exception $e) {
    // Abaikan jika error minor
}

$action = $_GET['action'] ?? ($_POST['action'] ?? ($json_input['action'] ?? 'poll'));

// 1. KIRIM PESAN CHAT
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message'] ?? ($json_input['message'] ?? ''));
    if ($message === '') {
        echo json_encode(['status' => 'error', 'message' => 'Pesan tidak boleh kosong.']);
        exit;
    }

    if (mb_strlen($message) > 1000) {
        echo json_encode(['status' => 'error', 'message' => 'Pesan terlalu panjang (maksimal 1000 karakter).']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO chat_messages (user_id, sender_name, sender_role, message, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user_id,
            $user['nama_lengkap'],
            $user['role'],
            $message
        ]);
        $new_id = (int)$pdo->lastInsertId();

        echo json_encode([
            'status'     => 'success',
            'message_id' => $new_id,
            'sender'     => $user['nama_lengkap'],
            'role'       => $user['role']
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Gagal mengirim pesan: ' . $e->getMessage()]);
    }
    exit;
}

// 2. BERSIHKAN RIWAYAT CHAT (CLEAR CHAT)
if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->exec("DELETE FROM chat_messages");

        echo json_encode([
            'status'  => 'success',
            'message' => 'Riwayat chat berhasil dibersihkan.'
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Gagal membersihkan chat: ' . $e->getMessage()]);
    }
    exit;
}

// 3. RIWAYAT AWAL (INITIAL LOAD)
if ($action === 'history') {
    try {
        $stmt = $pdo->query("
            SELECT * FROM (
                SELECT id, user_id, sender_name, sender_role, message, created_at 
                FROM chat_messages 
                ORDER BY id DESC 
                LIMIT 50
            ) sub 
            ORDER BY id ASC
        ");
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Max ID saat ini
        $stmt_max = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM chat_messages");
        $max_id = (int)$stmt_max->fetchColumn();

        // Ambil daftar pengguna online
        $stmt_online = $pdo->query("
            SELECT id, username, nama_lengkap, role, 
                   IF(active_session_token IS NOT NULL AND last_seen IS NOT NULL AND last_seen >= NOW() - INTERVAL 90 SECOND, 1, 0) AS is_online,
                   last_seen
            FROM users 
            ORDER BY is_online DESC, role ASC, nama_lengkap ASC
        ");
        $online_users = $stmt_online->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status'          => 'success',
            'current_user_id' => $user_id,
            'messages'        => $messages,
            'online_users'    => $online_users,
            'max_id'          => $max_id
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// 4. POLLING PESAN BARU & STATUS ONLINE
if ($action === 'poll') {
    $last_id = (int)($_GET['last_id'] ?? ($json_input['last_id'] ?? 0));

    try {
        $stmt = $pdo->prepare("
            SELECT id, user_id, sender_name, sender_role, message, created_at 
            FROM chat_messages 
            WHERE id > ? 
            ORDER BY id ASC 
            LIMIT 50
        ");
        $stmt->execute([$last_id]);
        $new_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Max ID saat ini
        $stmt_max = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM chat_messages");
        $max_id = (int)$stmt_max->fetchColumn();

        // Daftar status online
        $stmt_online = $pdo->query("
            SELECT id, username, nama_lengkap, role, 
                   IF(active_session_token IS NOT NULL AND last_seen IS NOT NULL AND last_seen >= NOW() - INTERVAL 90 SECOND, 1, 0) AS is_online,
                   last_seen
            FROM users 
            ORDER BY is_online DESC, role ASC, nama_lengkap ASC
        ");
        $online_users = $stmt_online->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status'          => 'success',
            'current_user_id' => $user_id,
            'messages'        => $new_messages,
            'online_users'    => $online_users,
            'max_id'          => $max_id
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Action tidak dikenal.']);
