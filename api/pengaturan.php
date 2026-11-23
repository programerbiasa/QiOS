<?php
require_once __DIR__ . '/init.php';

$app_name = get_app_name();
$logo_url = get_base_url() . (function_exists('get_app_logo') ? get_app_logo('') : 'assets/images/logo.png');

api_response(true, 'Data pengaturan sistem berhasil diambil', [
    'nama_aplikasi' => $app_name,
    'logo_url'      => $logo_url,
    'printer_width' => '58mm',
]);
