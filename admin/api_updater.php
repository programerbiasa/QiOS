<?php
/**
 * QiOS System Updater API
 * Mengelola pengecekan versi dan pembaruan otomatis via GitHub (Git / Zip / Wget / System cURL)
 * Kompatibel dengan semua instalasi PHP tanpa modul khusus (Zero-Dependency)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// Autentikasi sesi admin / superadmin (Sesi login web aplikasi)
$user_role = strtolower($_SESSION['role'] ?? '');
$is_authorized = false;

if (isset($_SESSION['user_id']) && in_array($user_role, ['admin', 'superadmin', 'owner'])) {
    $is_authorized = true;
} elseif (!empty($_SESSION['admin_logged_in'])) {
    $is_authorized = true;
}

if (!$is_authorized) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Akses ditolak. Silakan login sebagai administrator terlebih dahulu (Role saat ini: ' . ($user_role ?: 'Belum Login') . ').'
    ]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$root_dir = realpath(__DIR__ . '/..');

/**
 * Helper: Eksekusi command di server dengan aman
 */
function run_cmd($cmd, $cwd) {
    $descriptors = [
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"]  // stderr
    ];

    $process = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        return ['code' => -1, 'stdout' => '', 'stderr' => 'Gagal memulai proses sistem.'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exit_code = proc_close($process);
    return [
        'code'   => $exit_code,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr)
    ];
}

/**
 * Helper: HTTP GET request tanpa ketergantungan pada ekstensi php-curl
 */
function http_fetch($url) {
    // 1. Coba via file_get_contents dengan stream_context
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: QiOS-Updater/1.0 (RaspberryPi; Linux)

Accept: application/vnd.github.v3+json

",
            'timeout' => 12
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];
    $context = stream_context_create($opts);
    $res = @file_get_contents($url, false, $context);
    if ($res !== false) {
        return $res;
    }

    // 2. Coba via php-curl jika tersedia
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'QiOS-Updater/1.0');
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) return $res;
    }

    // 3. Fallback via binary curl bawaan OS Linux / Windows
    $sys_curl = run_cmd("curl -s -L -A 'QiOS-Updater/1.0' '{$url}'", sys_get_temp_dir());
    if ($sys_curl['code'] === 0 && !empty($sys_curl['stdout'])) {
        return $sys_curl['stdout'];
    }

    return null;
}

/**
 * Helper: Ambil info Git / Versi lokal
 */
function get_local_git_info($root_dir) {
    // 1. Jika ada folder .git dan git terpasang
    if (is_dir($root_dir . '/.git')) {
        $c_hash = run_cmd('git rev-parse --short HEAD', $root_dir);
        $c_full = run_cmd('git rev-parse HEAD', $root_dir);
        $c_branch = run_cmd('git rev-parse --abbrev-ref HEAD', $root_dir);
        $c_date = run_cmd('git log -1 --format=%cd --date=format:"%d %b %Y %H:%M"', $root_dir);
        $c_msg = run_cmd('git log -1 --format=%s', $root_dir);

        if ($c_hash['code'] === 0) {
            return [
                'is_git'      => true,
                'commit'      => $c_hash['stdout'],
                'commit_full' => $c_full['stdout'] ?? '',
                'branch'      => $c_branch['stdout'] ?: 'main',
                'date'        => $c_date['stdout'] ?: '-',
                'message'     => $c_msg['stdout'] ?: '-'
            ];
        }
    }

    // 2. Cek file .qios_version (Versi hasil update ZIP)
    $version_file = $root_dir . '/.qios_version';
    if (file_exists($version_file)) {
        $v_data = json_decode(@file_get_contents($version_file), true);
        if ($v_data) {
            return [
                'is_git'      => false,
                'commit'      => $v_data['commit'] ?? 'Installed',
                'commit_full' => $v_data['commit_full'] ?? ($v_data['commit'] ?? ''),
                'branch'      => 'main',
                'date'        => $v_data['updated'] ?? '-',
                'message'     => 'Instalasi Versi Server'
            ];
        }
    }

    return [
        'is_git'      => false,
        'commit'      => 'Awal',
        'commit_full' => '',
        'branch'      => 'main',
        'date'        => 'Versi Server SD Cendekia',
        'message'     => 'Aplikasi siap disinkronkan ke GitHub'
    ];
}

switch ($action) {
    case 'info':
        $info = get_local_git_info($root_dir);
        echo json_encode([
            'status' => 'success',
            'data'   => $info
        ]);
        break;

    case 'check':
        $local = get_local_git_info($root_dir);
        $github_repo = 'programerbiasa/QiOS';
        $branch = !empty($local['branch']) && $local['branch'] !== 'N/A' ? $local['branch'] : 'main';

        $remote_commit = null;
        $remote_commit_full = '';
        $remote_msg = '';
        $remote_date = '';

        // Ambil info commit terbaru dari GitHub
        $apiUrl = "https://api.github.com/repos/{$github_repo}/commits/{$branch}";
        $response = http_fetch($apiUrl);

        if ($response) {
            $data = json_decode($response, true);
            if ($data && isset($data['sha'])) {
                $remote_commit = substr($data['sha'], 0, 7);
                $remote_commit_full = $data['sha'];
                $remote_msg = $data['commit']['message'] ?? '';
                $remote_date = isset($data['commit']['committer']['date']) 
                    ? date('d M Y H:i', strtotime($data['commit']['committer']['date']))
                    : '-';
            }
        }

        if (!$remote_commit) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Gagal menghubungi server GitHub. Pastikan server terhubung ke internet.',
                'local'   => $local
            ]);
            exit;
        }

        // Bandingkan commit hash
        $has_update = false;
        if (!empty($local['commit_full'])) {
            $has_update = (strpos($local['commit_full'], $remote_commit) !== 0 && $local['commit'] !== $remote_commit);
        } else {
            // Jika versi awal / belum identik dengan commit remote
            $has_update = ($local['commit'] !== $remote_commit);
        }

        echo json_encode([
            'status'         => 'success',
            'has_update'     => $has_update,
            'local'          => $local,
            'remote'         => [
                'commit'      => $remote_commit,
                'commit_full' => $remote_commit_full,
                'branch'      => $branch,
                'message'     => $remote_msg,
                'date'        => $remote_date
            ]
        ]);
        break;

    case 'update':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'Metode request tidak valid. Gunakan POST.']);
            exit;
        }

        $db_config_file = $root_dir . '/config/database.php';
        $db_backup_file = $root_dir . '/config/database.php.qios_backup';
        if (file_exists($db_config_file)) {
            copy($db_config_file, $db_backup_file);
        }

        $log = [];
        $success = false;

        $git_check = run_cmd('git --version', $root_dir);
        $has_git_cli = ($git_check['code'] === 0);

        if ($has_git_cli) {
            if (!is_dir($root_dir . '/.git')) {
                $init = run_cmd('git init', $root_dir);
                $log[] = "git init: " . ($init['stdout'] ?: $init['stderr']);
                $remote = run_cmd('git remote add origin https://github.com/programerbiasa/QiOS.git', $root_dir);
                $log[] = "git remote add origin: " . ($remote['stdout'] ?: $remote['stderr']);
            }

            $branch_cmd = run_cmd('git rev-parse --abbrev-ref HEAD', $root_dir);
            $branch = ($branch_cmd['code'] === 0 && !empty($branch_cmd['stdout'])) ? $branch_cmd['stdout'] : 'main';
            if ($branch === 'HEAD') $branch = 'main';

            $fetch = run_cmd("git fetch origin {$branch}", $root_dir);
            $log[] = "Fetch origin: " . ($fetch['stdout'] ?: ($fetch['stderr'] ?: 'OK'));

            $pull = run_cmd("git reset --hard origin/{$branch}", $root_dir);
            $log[] = "Reset & Apply Update: " . ($pull['stdout'] ?: $pull['stderr']);

            if ($pull['code'] === 0) {
                $success = true;
            }
        } else {
            $log[] = "Mengunduh paket rilis terbaru dari GitHub...";
            $zip_url = "https://github.com/programerbiasa/QiOS/archive/refs/heads/main.zip";
            $zip_tmp = sys_get_temp_dir() . '/qios_update.zip';
            
            // Unduh file via binary curl atau wget
            $dl = run_cmd("curl -s -L '{$zip_url}' -o '{$zip_tmp}' || wget -q -O '{$zip_tmp}' '{$zip_url}'", $root_dir);
            
            if (file_exists($zip_tmp) && filesize($zip_tmp) > 5000) {
                $log[] = "Paket berhasil diunduh (" . round(filesize($zip_tmp)/1024, 1) . " KB).";
                $extract_dir = sys_get_temp_dir() . '/qios_extracted_' . time();
                
                // Ekstrak via unzip CLI atau python/tar
                $unzip_res = run_cmd("unzip -q -o '{$zip_tmp}' -d '{$extract_dir}'", $root_dir);
                if ($unzip_res['code'] !== 0) {
                    // Fallback python unzip jika unzip CLI belum ada
                    $py_unzip = run_cmd("python3 -c \"import zipfile; zipfile.ZipFile('{$zip_tmp}').extractall('{$extract_dir}')\"", $root_dir);
                }

                $inner_folders = glob($extract_dir . '/*', GLOB_ONLYDIR);
                $source_folder = !empty($inner_folders) ? $inner_folders[0] : $extract_dir;

                if (is_dir($source_folder)) {
                    // Salin file menggunakan rsync / cp -r
                    $cp_res = run_cmd("cp -rf '{$source_folder}'/* '{$root_dir}/' 2>&1", $root_dir);
                    $log[] = "File aplikasi berhasil disinkronkan ke web server.";
                    $success = true;

                    // Buat file penanda versi
                    $apiUrl = "https://api.github.com/repos/programerbiasa/QiOS/commits/main";
                    $latest_info = json_decode(http_fetch($apiUrl), true);
                    $new_commit = $latest_info['sha'] ?? 'Latest';

                    @file_put_contents($root_dir . '/.qios_version', json_encode([
                        'commit'      => substr($new_commit, 0, 7),
                        'commit_full' => $new_commit,
                        'updated'     => date('d M Y H:i')
                    ]));
                } else {
                    $log[] = "Gagal mengekstrak folder update.";
                }
                
                // Cleanup temp
                @unlink($zip_tmp);
                run_cmd("rm -rf '{$extract_dir}'", sys_get_temp_dir());
            } else {
                $log[] = "Gagal mengunduh file ZIP dari GitHub.";
            }
        }

        // Pastikan konfigurasi database tidak hilang
        if (file_exists($db_backup_file)) {
            if (!file_exists($db_config_file) || filesize($db_config_file) === 0) {
                copy($db_backup_file, $db_config_file);
                $log[] = "Konfigurasi database server berhasil dipulihkan.";
            }
            @unlink($db_backup_file);
        }

        $new_local = get_local_git_info($root_dir);

        if ($success) {
            echo json_encode([
                'status'  => 'success',
                'message' => 'Sistem berhasil diperbarui ke versi terbaru!',
                'log'     => implode("\n", $log),
                'local'   => $new_local
            ]);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Pembaruan otomatis gagal dieksekusi.',
                'log'     => implode("\n", $log),
                'local'   => $new_local
            ]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Aksi updater tidak dikenali.']);
        break;
}
