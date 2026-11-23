import sys
import os
import urllib.request
import urllib.parse
import webview

def get_app_dir():
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))

def get_server_url():
    # 1. Cek argumen CLI (misal: QiOS-Cendekia.exe http://172.16.5.11/qios)
    for arg in sys.argv[1:]:
        if arg.startswith("http://") or arg.startswith("https://"):
            val = arg.strip()
            parsed = urllib.parse.urlparse(val)
            if not parsed.path or parsed.path == "/":
                return val.rstrip("/") + "/qios/"
            return val.rstrip("/") + "/"

    # 2. Cek file config.ini di samping file .exe
    app_dir = get_app_dir()
    config_file = os.path.join(app_dir, "config.ini")
    if os.path.exists(config_file):
        try:
            with open(config_file, "r", encoding="utf-8") as f:
                for line in f:
                    line = line.strip()
                    if line.startswith("server_url="):
                        val = line.split("=", 1)[1].strip()
                        if val:
                            if not val.startswith("http://") and not val.startswith("https://"):
                                val = "http://" + val
                            parsed = urllib.parse.urlparse(val)
                            if not parsed.path or parsed.path == "/":
                                return val.rstrip("/") + "/qios/"
                            return val.rstrip("/") + "/"
        except Exception:
            pass

    return "http://172.16.5.11/qios/"

def get_clean_ip(url):
    try:
        parsed = urllib.parse.urlparse(url)
        host = parsed.netloc or parsed.path
        if ":" in host:
            host = host.split(":")[0]
        return host or "172.16.5.11"
    except Exception:
        return "172.16.5.11"

SERVER_URL = get_server_url()
CURRENT_IP = get_clean_ip(SERVER_URL)

def generate_offline_html(current_url, current_ip):
    return f"""<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Koneksi Server - QiOS Cendekia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {{
            background: #090d16;
            color: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 20px;
        }}
        .login-box {{
            background: #111827;
            border: 1px solid #334155;
            border-radius: 20px;
            padding: 35px 30px;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 20px 45px rgba(0,0,0,0.65);
            position: relative;
            text-align: center;
        }}
        .btn-gear-setting {{
            position: absolute;
            top: 18px;
            right: 18px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #1e293b;
            border: 1px solid #475569;
            color: #38bdf8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            cursor: pointer;
            transition: all 0.25s ease;
        }}
        .btn-gear-setting:hover {{
            background: #0284c7;
            color: #ffffff;
            border-color: #38bdf8;
            transform: rotate(60deg) scale(1.08);
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.4);
        }}
        .modal-content {{
            background: #111827;
            border: 1px solid #334155;
            color: #f1f5f9;
            border-radius: 18px;
            text-align: left;
        }}
    </style>
</head>
<body>
    <div class="login-box">
        <!-- ICON PENGATURAN DI POJOK KANAN ATAS -->
        <button type="button" class="btn-gear-setting" onclick="openConfigModal()" title="Pengaturan Alamat IP Server">
            <i class="bi bi-gear-fill"></i>
        </button>

        <div class="mb-3">
            <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-info rounded-circle p-3 mb-2" style="width: 75px; height: 75px;">
                <i class="bi bi-hdd-network display-4"></i>
            </div>
            <h4 class="fw-bold text-white mb-1">QiOS Cendekia</h4>
            <p class="text-secondary small mb-0">Aplikasi kasir belum dapat terhubung ke server Raspberry Pi.</p>
        </div>

        <div class="bg-dark bg-opacity-50 border border-secondary rounded-3 p-3 mb-4 text-start small">
            <div class="d-flex justify-content-between mb-1">
                <span class="text-secondary">Host Server Saat Ini:</span>
                <strong class="text-warning font-monospace" id="labelCurrentUrl">{current_url}</strong>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-secondary">Status Jaringan:</span>
                <span class="badge bg-danger">Tidak Terjangkau</span>
            </div>
        </div>

        <div class="d-grid gap-2 mb-3">
            <button type="button" class="btn btn-primary rounded-pill py-2 fw-bold shadow-sm" onclick="retryConnection()">
                <i class="bi bi-arrow-clockwise me-1"></i> Coba Hubungkan Ulang
            </button>
            <button type="button" class="btn btn-outline-info rounded-pill py-2 fw-semibold" onclick="openConfigModal()">
                <i class="bi bi-gear-fill me-1"></i> Atur IP Server
            </button>
        </div>

        <div class="text-secondary small" style="font-size: 0.73rem;">
            *Pastikan laptop terhubung ke Wi-Fi / LAN yang sama dengan server Raspberry Pi (172.16.5.11).
        </div>
    </div>

    <!-- POPUP MODAL PENGATURAN IP SERVER -->
    <div class="modal fade" id="modalConfigIp" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-secondary bg-dark bg-opacity-40">
                    <h5 class="modal-title fw-bold text-white">
                        <i class="bi bi-gear-fill text-info me-2"></i>Pengaturan Server QiOS
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-secondary small mb-3">
                        Ubah alamat IP server jika server Raspberry Pi berganti jaringan Wi-Fi atau menggunakan IP baru.
                    </p>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Protokol & Alamat IP Server:</label>
                        <div class="input-group">
                            <select id="selectProtocol" class="form-select bg-dark border-secondary text-white" style="max-width: 105px;">
                                <option value="http://">http://</option>
                                <option value="https://">https://</option>
                            </select>
                            <input type="text" id="inputIpServer" class="form-control bg-dark border-secondary text-white font-monospace" placeholder="172.16.5.11" value="{current_ip}">
                        </div>
                        <small class="text-muted">Masukkan IP server (default: <code>172.16.5.11</code>). Aplikasi otomatis mengakses folder <code>/qios/</code>.</small>
                    </div>

                    <div id="testStatusBox" class="alert py-2 small d-none mb-3"></div>

                    <div class="d-flex justify-content-between align-items-center pt-2 border-top border-secondary">
                        <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" onclick="testServerConnection()">
                            <span id="testSpinner" class="spinner-border spinner-border-sm me-1 d-none"></span>
                            <i class="bi bi-broadcast me-1" id="testIcon"></i> Tes Koneksi
                        </button>
                        <button type="button" class="btn btn-info btn-sm rounded-pill px-4 fw-bold text-dark" onclick="saveAndApplyIp()">
                            <i class="bi bi-check-lg me-1"></i> Simpan & Hubungkan
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let configModal = null;

    function openConfigModal() {{
        if (!configModal) {{
            configModal = new bootstrap.Modal(document.getElementById('modalConfigIp'));
        }}
        document.getElementById('testStatusBox').className = 'alert py-2 small d-none mb-3';
        configModal.show();
    }}

    function getTargetUrl() {{
        let proto = document.getElementById('selectProtocol').value;
        let ip = document.getElementById('inputIpServer').value.trim();
        ip = ip.replace(/^https?:\\/\\//, '').replace(/\\/+$/, '');
        if (!ip) ip = '172.16.5.11';
        if (!ip.includes('/')) {{
            return proto + ip + '/qios/';
        }}
        return proto + ip + '/';
    }}

    function retryConnection() {{
        window.location.href = '{current_url}';
    }}

    // Tes Koneksi ke IP Server
    async function testServerConnection() {{
        const targetUrl = getTargetUrl();
        const box = document.getElementById('testStatusBox');
        const spinner = document.getElementById('testSpinner');
        const icon = document.getElementById('testIcon');

        spinner.classList.remove('d-none');
        icon.classList.add('d-none');
        box.className = 'alert alert-info py-2 small mb-3';
        box.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Menguji koneksi ke ' + targetUrl + '...';

        try {{
            if (window.pywebview && window.pywebview.api && window.pywebview.api.test_connection) {{
                const res = await window.pywebview.api.test_connection(targetUrl);
                if (res.status === 'success') {{
                    box.className = 'alert alert-success py-2 small mb-3';
                    box.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> <strong>Berhasil!</strong> Server di <code>' + targetUrl + '</code> merespon aktif.';
                }} else {{
                    box.className = 'alert alert-danger py-2 small mb-3';
                    box.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> <strong>Gagal Terhubung:</strong> ' + (res.message || 'Tidak ada respon dari server.');
                }}
            }} else {{
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 3000);
                await fetch(targetUrl, {{ mode: 'no-cors', signal: controller.signal }});
                clearTimeout(timeoutId);
                box.className = 'alert alert-success py-2 small mb-3';
                box.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> <strong>Berhasil!</strong> Server merespon jaringan.';
            }}
        }} catch (err) {{
            box.className = 'alert alert-danger py-2 small mb-3';
            box.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> <strong>Gagal:</strong> Server tidak merespon di alamat tersebut.';
        }} finally {{
            spinner.classList.add('d-none');
            icon.classList.remove('d-none');
        }}
    }}

    // Simpan IP ke config.ini dan Muat Ulang
    async function saveAndApplyIp() {{
        const targetUrl = getTargetUrl();
        const box = document.getElementById('testStatusBox');

        box.className = 'alert alert-info py-2 small mb-3';
        box.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Menyimpan konfigurasi dan menghubungkan ke ' + targetUrl + '...';

        try {{
            if (window.pywebview && window.pywebview.api && window.pywebview.api.save_server_ip) {{
                const res = await window.pywebview.api.save_server_ip(targetUrl);
                if (res.status === 'success') {{
                    return;
                }}
            }}
        }} catch (e) {{
        }}

        // Browser fallback
        try {{
            localStorage.setItem('kios_server_url', targetUrl);
        }} catch (e) {{}}
        window.location.href = targetUrl;
    }}
    </script>
</body>
</html>"""

class JsApi:
    def __init__(self, window_holder):
        self.holder = window_holder

    def save_server_ip(self, ip_or_url):
        raw = ip_or_url.strip()
        if not raw:
            return {"status": "error", "message": "Alamat IP kosong"}
        if not raw.startswith("http://") and not raw.startswith("https://"):
            raw = "http://" + raw
        parsed = urllib.parse.urlparse(raw)
        host = parsed.netloc or parsed.path
        if ":" in host:
            host = host.split(":")[0]
        if not host:
            host = "127.0.0.1"

        if not parsed.path or parsed.path == "/":
            raw = raw.rstrip("/") + "/qios/"
        elif not raw.endswith("/"):
            raw += "/"

        db_host = "127.0.0.1" if host in ["localhost", "127.0.0.1", "::1"] else host

        ini_content = f"""[SERVER]
server_url={raw}

[DATABASE]
db_host={db_host}
db_port=3306
db_user=root
db_password=pastijuara1
db_name=kios_db
"""
        app_dir = get_app_dir()
        config_path = os.path.join(app_dir, "config.ini")
        try:
            with open(config_path, "w", encoding="utf-8") as f:
                f.write(ini_content)
        except Exception as e:
            return {"status": "error", "message": f"Gagal menulis config.ini: {e}"}

        # Sinkronkan juga ke folder desktop_go jika ada
        go_ini = os.path.join(app_dir, "desktop_go", "config.ini")
        if os.path.exists(os.path.dirname(go_ini)):
            try:
                with open(go_ini, "w", encoding="utf-8") as f:
                    f.write(ini_content)
            except Exception:
                pass

        # Muat halaman baru pada window desktop
        if self.holder.get("window"):
            self.holder["window"].load_url(raw)

        return {"status": "success", "url": raw, "db_host": db_host}

    def test_connection(self, ip_or_url):
        raw = ip_or_url.strip()
        if not raw:
            return {"status": "error", "message": "Alamat IP kosong"}
        if not raw.startswith("http://") and not raw.startswith("https://"):
            raw = "http://" + raw
        
        web_ok = False
        web_status = 0
        web_err = ""
        try:
            req = urllib.request.Request(raw, headers={'User-Agent': 'QiOS-Desktop/1.0'})
            res = urllib.request.urlopen(req, timeout=3.0)
            web_ok = True
            web_status = res.status
        except Exception as e:
            web_err = str(e)

        # Uji juga port database MySQL 3306
        import socket
        db_ok = False
        try:
            parsed = urllib.parse.urlparse(raw)
            h = parsed.netloc or parsed.path
            if ":" in h:
                h = h.split(":")[0]
            if not h or h in ["localhost", "127.0.0.1", "::1"]:
                h = "127.0.0.1"
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(2.0)
            res_code = sock.connect_ex((h, 3306))
            sock.close()
            db_ok = (res_code == 0)
        except Exception:
            db_ok = False

        if web_ok:
            return {
                "status": "success",
                "code": web_status,
                "db_ok": db_ok,
                "message": f"Web server aktif (HTTP {web_status}). Database MySQL 3306: {'Terhubung' if db_ok else 'Port belum terbuka'}"
            }
        else:
            return {
                "status": "error",
                "code": web_status,
                "db_ok": db_ok,
                "message": f"Gagal web server: {web_err}. Database MySQL 3306: {'Port terbuka' if db_ok else 'Tidak terjangkau'}"
            }

def is_server_online(url, timeout=3):
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'QiOS-Desktop/1.0'})
        res = urllib.request.urlopen(req, timeout=timeout)
        return True
    except Exception:
        return False

def main():
    is_kiosk = "--kiosk" in sys.argv
    app_dir = get_app_dir()
    icon_path = os.path.join(app_dir, "assets", "images", "qios_logo.ico")
    if not os.path.exists(icon_path):
        icon_path = os.path.join(app_dir, "assets", "images", "favicon.ico")

    target_url = SERVER_URL
    clean_ip = CURRENT_IP

    # Periksa koneksi awal ke server
    if not is_server_online(target_url, timeout=2.5):
        # Coba periksa root host terlebih dahulu
        root_test = f"http://{clean_ip}/"
        if is_server_online(root_test, timeout=2.0):
            target_url = f"http://{clean_ip}/qios/"
        else:
            offline_html_content = generate_offline_html(target_url, clean_ip)
            target_url = "data:text/html;charset=utf-8," + urllib.parse.quote(offline_html_content)

    window_holder = {"window": None}
    js_api = JsApi(window_holder)

    # Buat window desktop native Windows dengan JS API bridge
    window = webview.create_window(
        title="QiOS Cendekia - Sistem POS Kasir",
        url=target_url,
        width=1280,
        height=820,
        min_size=(1024, 700),
        resizable=True,
        fullscreen=is_kiosk,
        confirm_close=True,
        js_api=js_api
    )
    window_holder["window"] = window

    # Jalankan engine WebView2
    webview.start(gui="edgechromium")

if __name__ == "__main__":
    main()
