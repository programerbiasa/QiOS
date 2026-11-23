    </div><!-- /.container -->
</main>

<footer class="bg-dark text-secondary py-3 border-top border-secondary mt-auto small">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center">
        <div>
            <strong class="text-white"><?= e(function_exists('get_app_name') ? get_app_name() : 'QiOS Cendekia') ?></strong> &copy; <?= date('Y') ?>. Hak Cipta Dilindungi.
        </div>
        <div>
            <span class="d-inline-flex align-items-center gap-2">
            <span class="text-secondary">Server:</span>
            <span class="badge bg-secondary font-monospace px-2 py-1" id="footerServerIp" title="Alamat IP Web Server"><?= e($_SERVER['HTTP_HOST'] ?? '127.0.0.1') ?></span>
            <span class="text-secondary ms-1">Database:</span>
            <?php 
                $active_db = $GLOBALS['active_db_host'] ?? ($connected_host ?? ($db_host ?? '127.0.0.1'));
                $is_db_ok = isset($pdo) && ($pdo instanceof PDO);
            ?>
            <span class="badge <?= $is_db_ok ? 'bg-success text-white' : 'bg-danger text-white' ?> px-2 py-1 shadow-sm d-inline-flex align-items-center gap-1 font-monospace" id="footerDbStatus">
                <span class="spinner-grow spinner-grow-sm me-1 d-none" id="footerDbPulse" style="width: 7px; height: 7px;"></span>
                <i class="bi <?= $is_db_ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>" id="footerDbIcon"></i>
                <span id="footerDbText"><?= $is_db_ok ? 'Online (' . e($active_db) . ')' : 'Offline' ?></span>
            </span>
        </span>
        </div>
    </div>
</footer>

<!-- Wadah Notifikasi Transaksi Kasir Mengambang Real-Time (Bottom-Right) -->
<div id="adminNotificationContainer" style="position: fixed; bottom: 20px; right: 20px; z-index: 10050; max-width: 360px; width: calc(100% - 40px); pointer-events: none;">
    <!-- Notifikasi disuntikkan secara dinamis oleh JavaScript -->
</div>

<style>
#adminNotificationContainer .toast {
    pointer-events: auto;
}
@keyframes slideInNotification {
    from {
        transform: translateY(30px) scale(0.95);
        opacity: 0;
    }
    to {
        transform: translateY(0) scale(1);
        opacity: 1;
    }
}
</style>

<!-- Popup Chat Pengguna Aktif -->
<?php require_once __DIR__ . '/../../includes/chat_widget.php'; ?>

<!-- Bootstrap 5.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Script Real-Time Notifikasi Transaksi Kasir -->
<script>
(function() {
    let lastSeenOrderId = 0;

    // Suara Bel Notifikasi Kasir menggunakan Web Audio API
    function playOrderChime() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();
            const now = ctx.currentTime;
            
            // Nada 1: Bell Ding
            const osc1 = ctx.createOscillator();
            const gain1 = ctx.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(587.33, now); // D5
            osc1.frequency.exponentialRampToValueAtTime(880, now + 0.15); // A5
            gain1.gain.setValueAtTime(0.3, now);
            gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
            osc1.connect(gain1);
            gain1.connect(ctx.destination);
            osc1.start(now);
            osc1.stop(now + 0.5);

            // Nada 2: Harmonic Ring
            const osc2 = ctx.createOscillator();
            const gain2 = ctx.createGain();
            osc2.type = 'triangle';
            osc2.frequency.setValueAtTime(1174.66, now + 0.12); // D6
            gain2.gain.setValueAtTime(0.25, now + 0.12);
            gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.65);
            osc2.connect(gain2);
            gain2.connect(ctx.destination);
            osc2.start(now + 0.12);
            osc2.stop(now + 0.65);
        } catch (e) {
            // Browser mungkin memblokir audio sebelum interaksi pengguna
        }
    }

    // Format Rupiah
    function formatRupiah(number) {
        return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.max(0, number));
    }

    // Tampilkan Kartu Notifikasi Transaksi Kasir
    function showOrderNotification(order) {
        const container = document.getElementById('adminNotificationContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'toast show border-0 shadow-lg mb-2 text-white';
        toast.style.background = 'linear-gradient(135deg, #090d16 0%, #1e293b 100%)';
        toast.style.borderLeft = '5px solid #10b981 !important';
        toast.style.borderRadius = '12px';
        toast.style.boxShadow = '0 10px 25px rgba(0,0,0,0.5), 0 0 15px rgba(16, 185, 129, 0.3)';
        toast.style.animation = 'slideInNotification 0.35s ease-out';

        toast.innerHTML = `
            <div class="toast-header bg-dark bg-opacity-75 text-white border-bottom border-secondary border-opacity-25 py-2 px-3 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-success bg-opacity-25 text-success rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                        <i class="bi bi-cart-check-fill me-1"></i> Transaksi Kasir Baru
                    </span>
                    <strong class="font-monospace text-info small" style="font-size: 0.78rem;">${order.kode_pesanan}</strong>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <small class="text-secondary" style="font-size: 0.7rem;">${order.waktu}</small>
                    <button type="button" class="btn-close btn-close-white" style="font-size: 0.65rem;" onclick="this.closest('.toast').remove()"></button>
                </div>
            </div>
            <div class="toast-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small">Kasir: <strong class="text-white">${order.nama_kasir}</strong></span>
                    <span class="badge bg-secondary bg-opacity-25 text-light" style="font-size: 0.7rem;">Plggn: ${order.nama_pembeli}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top border-secondary border-opacity-25">
                    <span class="text-secondary small">${order.total_items} Barang</span>
                    <span class="fw-bold font-monospace text-success fs-6">${formatRupiah(order.total_harga)}</span>
                </div>
            </div>
        `;

        container.appendChild(toast);

        // Otomatis hilang setelah 9 detik
        setTimeout(() => {
            if (toast && toast.parentNode) {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.4s ease-out';
                setTimeout(() => toast.remove(), 400);
            }
        }, 9000);
    }

    // Polling ke check_new_orders.php
    function checkNewOrders() {
        if (lastSeenOrderId === 0) return;

        const checkUrl = '<?= dirname($_SERVER['SCRIPT_NAME']) === '/' ? '' : dirname($_SERVER['SCRIPT_NAME']) ?>/check_new_orders.php';
        fetch(checkUrl + '?last_id=' + lastSeenOrderId)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.has_new && Array.isArray(data.orders)) {
                    lastSeenOrderId = data.latest_id;
                    data.orders.forEach(order => {
                        showOrderNotification(order);
                    });
                    playOrderChime();
                }
            })
            .catch(() => {});
    }

    // Inisialisasi awal ID baseline saat halaman admin dimuat
    function initOrderNotifier() {
        const checkUrl = '<?= dirname($_SERVER['SCRIPT_NAME']) === '/' ? '' : dirname($_SERVER['SCRIPT_NAME']) ?>/check_new_orders.php';
        fetch(checkUrl + '?last_id=0')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    lastSeenOrderId = data.latest_id;
                    setInterval(checkNewOrders, 2500);
                }
            })
            .catch(() => {
                setTimeout(initOrderNotifier, 4000);
            });
    }

    // Mulai saat DOM siap
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initOrderNotifier);
    } else {
        initOrderNotifier();
    }
})();

    // ==========================================
    // Real-Time Server & Database Status Poller
    // ==========================================
    function updateRealtimeServerStatus() {
        const statusUrl = '<?= dirname($_SERVER['SCRIPT_NAME']) === '/' ? '' : dirname($_SERVER['SCRIPT_NAME']) ?>/../api/server_status.php';
        fetch(statusUrl, { credentials: 'same-origin' })
            .then(res => res.json())
            .then(data => {
                if (data && data.status === 'success') {
                    const srvIp = document.getElementById('footerServerIp');
                    const dbStatus = document.getElementById('footerDbStatus');
                    const dbIcon = document.getElementById('footerDbIcon');
                    const dbText = document.getElementById('footerDbText');

                    if (srvIp && data.web_host) {
                        srvIp.textContent = data.web_host;
                    }

                    if (dbStatus && dbIcon && dbText && data.db) {
                        if (data.db.online) {
                            dbStatus.className = 'badge bg-success text-white px-2 py-1 shadow-sm d-inline-flex align-items-center gap-1 font-monospace';
                            dbIcon.className = 'bi bi-check-circle-fill text-white';
                            dbText.textContent = 'Online (' + data.db.host + ')';
                        } else {
                            dbStatus.className = 'badge bg-danger text-white px-2 py-1 shadow-sm d-inline-flex align-items-center gap-1 font-monospace';
                            dbIcon.className = 'bi bi-x-circle-fill text-white';
                            dbText.textContent = 'Offline (' + data.db.host + ')';
                        }
                    }
                }
            })
            .catch(() => {});
    }

    // Jalankan segera dan refresh status setiap 15 detik secara realtime
    updateRealtimeServerStatus();
    setInterval(updateRealtimeServerStatus, 15000);

</script>
</body>
</html>
