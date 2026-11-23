<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Baca input JSON dari frontend
$raw_input = file_get_contents('php://input');
$input_data = [];
if (!empty($raw_input)) {
    $decoded = json_decode($raw_input, true);
    if (is_array($decoded)) {
        $input_data = $decoded;
    }
}

$message = trim($input_data['message'] ?? ($_POST['message'] ?? ''));
$session_id = trim($input_data['session_id'] ?? ($_POST['session_id'] ?? 'default'));

// Validasi session ID agar aman (hanya alphanumeric & hyphen)
$session_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $session_id);
if (empty($session_id)) {
    $session_id = 'default';
}

if ($message === '') {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Pesan tidak boleh kosong.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Path ke skrip Python brain.py
$brain_script = __DIR__ . '/brain.py';
if (!file_exists($brain_script)) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Engine AI brain.py tidak ditemukan di server.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Siapkan payload JSON untuk dikirim ke Python via STDIN
$payload = json_encode([
    'message'    => $message,
    'session_id' => $session_id
], JSON_UNESCAPED_UNICODE);

// Eksekusi Python dengan proc_open untuk performa dan keamanan optimal
$descriptors = [
    0 => ['pipe', 'r'], // STDIN
    1 => ['pipe', 'w'], // STDOUT
    2 => ['pipe', 'w']  // STDERR
];

$cmd = "python3 " . escapeshellarg($brain_script);
$process = proc_open($cmd, $descriptors, $pipes, __DIR__, [
    'PYTHONIOENCODING' => 'utf-8',
    'LANG'             => 'en_US.UTF-8'
]);

if (is_resource($process)) {
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    $stdout_output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr_output = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $return_value = proc_close($process);

    if ($return_value === 0 && !empty($stdout_output)) {
        $ai_json = json_decode(trim($stdout_output), true);
        if (is_array($ai_json) && isset($ai_json['reply'])) {
            echo json_encode($ai_json, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Jika terjadi error dari Python
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Gagal memproses respon AI.',
        'detail'  => $stderr_output ?: 'Output Python tidak valid.'
    ], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Tidak dapat menjalankan proses Python di server.'
    ], JSON_UNESCAPED_UNICODE);
}
