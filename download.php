<?php
/**
 * QiOS Desktop Client Download Handler
 * Melayani pengunduhan installer aplikasi desktop Windows (QiOS Client).
 * Jika berkas tersedia lokal di server (Raspberry Pi), langsung streaming.
 * Jika tidak tersedia lokal, otomatis mengalihkan (redirect) ke GitHub repository.
 */

$githubDownloadUrl = 'https://github.com/programerbiasa/QiOS/raw/main/xojo/Builds%20-%20QiOS/Installer/QiOS_Setup_v1.0.0_x64.exe';

$candidatePaths = [
    __DIR__ . '/xojo/Builds - QiOS/Installer/QiOS_Setup_v1.0.0_x64.exe',
    __DIR__ . '/downloads/QiOS_Setup_v1.0.0_x64.exe',
];

$targetFile = null;
foreach ($candidatePaths as $path) {
    if (file_exists($path) && is_readable($path) && filesize($path) > 1000000) {
        $targetFile = $path;
        break;
    }
}

// Jika request meminta cek status / info file via JSON
if (isset($_GET['info'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'app_name' => 'QiOS Desktop Client',
        'version' => 'v1.0.0',
        'filename' => 'QiOS_Setup_v1.0.0_x64.exe',
        'local_available' => ($targetFile !== null),
        'file_size' => $targetFile ? filesize($targetFile) : null,
        'file_size_formatted' => $targetFile ? round(filesize($targetFile) / (1024 * 1024), 2) . ' MB' : '~16 MB',
        'github_url' => $githubDownloadUrl
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Jika ada file lokal, stream ke klien
if ($targetFile !== null) {
    @ini_set('max_execution_time', '300');
    @ini_set('memory_limit', '128M');

    // Bersihkan semua output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }

    $fileName = basename($targetFile);
    $fileSize = filesize($targetFile);

    header('Content-Description: File Transfer');
    header('Content-Type: application/vnd.microsoft.portable-executable');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . $fileSize);

    $handle = fopen($targetFile, 'rb');
    if ($handle !== false) {
        while (!feof($handle)) {
            echo fread($handle, 65536);
            flush();
        }
        fclose($handle);
    } else {
        readfile($targetFile);
    }
    exit;
}

// Fallback: alihkan ke GitHub repository
header('Location: ' . $githubDownloadUrl, true, 302);
exit;
