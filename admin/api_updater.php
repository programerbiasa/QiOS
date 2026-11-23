<?php
/**
 * QiOS System Updater API
 * Mengelola pengecekan versi dan pembaruan otomatis via Git / GitHub API
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
 * Helper: Ambil info Git lokal
 */
function get_local_git_info($root_dir) {
    if (!is_dir($root_dir . '/.git')) {
        return [
            'is_git' => false,
            'commit' => 'N/A',
            'branch' => 'N/A',
            'date'   => 'N/A',
            'message'=> 'Folder proyek bukan repositori Git aktif.'
        ];
    }

    $c_hash = run_cmd('git rev-parse --short HEAD', $root_dir);
    $c_full = run_cmd('git rev-parse HEAD', $root_dir);
    $c_branch = run_cmd('git rev-parse --abbrev-ref HEAD', $root_dir);
    $c_date = run_cmd('git log -1 --format=%cd --date=format:"%d %b %Y %H:%M"', $root_dir);
    $c_msg = run_cmd('git log -1 --format=%s', $root_dir);

    return [
        'is_git'      => ($c_hash['code'] === 0),
        'commit'      => ($c_hash['code'] === 0) ? $c_hash['stdout'] : 'Unknown',
        'commit_full' => ($c_full['code'] === 0) ? $c_full['stdout'] : '',
        'branch'      => ($c_branch['code'] === 0) ? $c_branch['stdout'] : 'main',
        'date'        => ($c_date['code'] === 0) ? $c_date['stdout'] : '-',
        'message'     => ($c_msg['code'] === 0) ? $c_msg['stdout'] : '-'
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
        $remote_msg = '';
        $remote_date = '';
        $check_method = 'api';

        // 1. Cek via GitHub REST API (Paling cepat & tidak perlu ssh/git config di remote jika repo publik)
        $apiUrl = "https://api.github.com/repos/{$github_repo}/commits/{$branch}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'QiOS-Updater/1.0 (RaspberryPi; Linux)');
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($http_code === 200 && $response) {
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

        // 2. Fallback jika cURL ke GitHub dibatasi rate-limit atau gagal, gunakan git ls-remote jika ada git
        if (!$remote_commit && $local['is_git']) {
            $check_method = 'git_ls_remote';
            $ls = run_cmd("git ls-remote origin refs/heads/{$branch}", $root_dir);
            if ($ls['code'] === 0 && !empty($ls['stdout'])) {
                $parts = preg_split('/\s+/', $ls['stdout']);
                if (!empty($parts[0])) {
                    $remote_commit_full = $parts[0];
                    $remote_commit = substr($parts[0], 0, 7);
                    $remote_msg = 'Pembaruan terbaru dari branch ' . $branch;
                }
            }
        }

        if (!$remote_commit) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Gagal menghubungi server GitHub. Pastikan perangkat terhubung ke internet. Error: ' . ($curl_err ?: "HTTP $http_code"),
                'local'   => $local
            ]);
            exit;
        }

        // Bandingkan commit hash
        $has_update = false;
        if ($local['is_git'] && !empty($local['commit'])) {
            $has_update = (strpos($local['commit_full'], $remote_commit) !== 0 && $local['commit'] !== $remote_commit);
        } else {
            // Jika folder belum git, tandai bisa disinkronkan
            $has_update = true;
        }

        echo json_encode([
            'status'         => 'success',
            'has_update'     => $has_update,
            'check_method'   => $check_method,
            'local'          => $local,
            'remote'         => [
                'commit'      => $remote_commit,
                'commit_full' => $remote_commit_full ?? '',
                'branch'      => $branch,
                'message'     => $remote_msg,
                'date'        => $remote_date
            ]
        ]);
        break;

    case 'update':
        // Pastikan request metode POST untuk aksi mutasi
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'Metode request tidak valid. Gunakan POST.']);
            exit;
        }

        // Backup settingan database lokal sebelum git pull agar tidak tertimpa atau hilang
        $db_config_file = $root_dir . '/config/database.php';
        $db_backup_file = $root_dir . '/config/database.php.qios_backup';
        if (file_exists($db_config_file)) {
            copy($db_config_file, $db_backup_file);
        }

        $log = [];
        $success = false;

        // Cek apakah .git ada
        if (!is_dir($root_dir . '/.git')) {
            // Inisialisasi repo jika belum ada
            $init = run_cmd('git init', $root_dir);
            $log[] = "git init: " . ($init['stdout'] ?: $init['stderr']);
            $remote = run_cmd('git remote add origin https://github.com/programerbiasa/QiOS.git', $root_dir);
            $log[] = "git remote add origin: " . ($remote['stdout'] ?: $remote['stderr']);
        }

        // Ambil branch aktif
        $branch_cmd = run_cmd('git rev-parse --abbrev-ref HEAD', $root_dir);
        $branch = ($branch_cmd['code'] === 0 && !empty($branch_cmd['stdout'])) ? $branch_cmd['stdout'] : 'main';
        if ($branch === 'HEAD') $branch = 'main';

        // 1. Fetch dari origin
        $fetch = run_cmd("git fetch origin {$branch}", $root_dir);
        $log[] = "Fetch origin: " . ($fetch['stdout'] ?: ($fetch['stderr'] ?: 'OK'));

        // 2. Reset/Pull ke origin/<branch>
        // Menggunakan fetch + reset --hard origin/<branch> adalah best-practice untuk production server kios
        // agar tidak terjadi merge conflict saat update dari remote.
        $pull = run_cmd("git reset --hard origin/{$branch}", $root_dir);
        $log[] = "Reset & Apply Update: " . ($pull['stdout'] ?: $pull['stderr']);

        if ($pull['code'] === 0) {
            $success = true;
        } else {
            // Coba pull standar
            $pull_std = run_cmd("git pull origin {$branch}", $root_dir);
            $log[] = "Fallback git pull: " . ($pull_std['stdout'] ?: $pull_std['stderr']);
            if ($pull_std['code'] === 0) {
                $success = true;
            }
        }

        // Kembalikan file database lokal jika ada perbedaan kredensial server
        if (file_exists($db_backup_file)) {
            // Cek apakah database.php masih ada
            if (!file_exists($db_config_file) || filesize($db_config_file) === 0) {
                copy($db_backup_file, $db_config_file);
                $log[] = "Memulihkan database config dari backup.";
            }
            @unlink($db_backup_file);
        }

        $new_local = get_local_git_info($root_dir);

        if ($success) {
            echo json_encode([
                'status'  => 'success',
                'message' => 'Sistem berhasil diperbarui ke commit ' . $new_local['commit'] . '!',
                'log'     => implode("\n", $log),
                'local'   => $new_local
            ]);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Pembaruan otomatis gagal dieksekusi. Periksa izin akses (permission) folder atau koneksi Git.',
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
