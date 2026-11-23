<?php
// Pastikan pengguna sudah login sebelum widget chat dirender
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    return;
}

// Tentukan path relatif ke api/chat.php
$chat_api_url = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false || strpos($_SERVER['PHP_SELF'], '/kasir/') !== false || strpos($_SERVER['PHP_SELF'], '/qios/') !== false) 
    ? '../api/chat.php' 
    : 'api/chat.php';

$current_user_name = $_SESSION['nama_lengkap'] ?? 'Saya';
$current_user_role = $_SESSION['role'] ?? 'kasir';
?>

<!-- ======================================================== -->
<!-- WIDGET POPUP CHAT PENGGUNA AKTIF (ADMIN & KASIR) -->
<!-- ======================================================== -->
<style>
/* Floating Chat Button */
#kiosChatFloatingBtn {
    position: fixed;
    bottom: 22px;
    right: 22px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0284c7, #0369a1);
    color: #ffffff;
    border: 2px solid #38bdf8;
    box-shadow: 0 8px 25px rgba(2, 132, 199, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    cursor: pointer;
    z-index: 1070;
    transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

#kiosChatFloatingBtn:hover {
    transform: scale(1.08) translateY(-3px);
    box-shadow: 0 12px 30px rgba(2, 132, 199, 0.6);
}

#kiosChatBadgeUnread {
    position: absolute;
    top: -4px;
    right: -4px;
    background-color: #ef4444;
    color: #ffffff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
    border: 2px solid #090d16;
    display: none;
    animation: pulseBadge 1.5s infinite;
}

@keyframes pulseBadge {
    0% { transform: scale(1); }
    50% { transform: scale(1.15); }
    100% { transform: scale(1); }
}

#kiosOnlinePulse {
    position: absolute;
    bottom: 2px;
    right: 2px;
    width: 13px;
    height: 13px;
    background-color: #10b981;
    border: 2px solid #090d16;
    border-radius: 50%;
}

/* Chat Popup Window */
#kiosChatBox {
    position: fixed;
    bottom: 90px;
    right: 22px;
    width: 360px;
    max-width: calc(100vw - 30px);
    height: 500px;
    max-height: calc(100vh - 120px);
    background-color: #0f172a;
    border: 1px solid #334155;
    border-radius: 18px;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.65);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 1070;
    font-family: system-ui, -apple-system, sans-serif;
    transition: all 0.25s ease-out;
}

#kiosChatBox.chat-hidden {
    display: none !important;
}

/* Header */
.kios-chat-header {
    background: #1e293b;
    border-bottom: 1px solid #334155;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* Active Users Drawer */
#kiosActiveUsersDrawer {
    background-color: #111827;
    border-bottom: 1px solid #334155;
    max-height: 150px;
    overflow-y: auto;
    padding: 8px 12px;
    display: none;
}

/* Messages Area */
#kiosChatMessagesBody {
    flex-grow: 1;
    overflow-y: auto;
    padding: 12px;
    background-color: #090d16;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

/* Message Bubbles */
.chat-msg {
    display: flex;
    flex-direction: column;
    max-width: 82%;
    animation: fadeInMsg 0.2s ease-in;
}

@keyframes fadeInMsg {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.chat-msg.mine {
    align-self: flex-end;
}

.chat-msg.other {
    align-self: flex-start;
}

.chat-bubble {
    padding: 8px 12px;
    border-radius: 14px;
    font-size: 0.83rem;
    line-height: 1.35;
    word-break: break-word;
}

.chat-msg.mine .chat-bubble {
    background: #0284c7;
    color: #ffffff;
    border-bottom-right-radius: 3px;
}

.chat-msg.other .chat-bubble {
    background: #1e293b;
    color: #f1f5f9;
    border: 1px solid #334155;
    border-bottom-left-radius: 3px;
}

.chat-sender-info {
    font-size: 0.68rem;
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.chat-time {
    font-size: 0.62rem;
    opacity: 0.75;
    margin-top: 3px;
    text-align: right;
}

/* Input Bar */
.kios-chat-footer {
    background-color: #1e293b;
    border-top: 1px solid #334155;
    padding: 8px 10px;
    display: flex;
    gap: 6px;
    align-items: center;
}

.kios-chat-input {
    background-color: #0f172a !important;
    border: 1px solid #475569 !important;
    color: #f8fafc !important;
    border-radius: 20px;
    padding: 6px 12px;
    font-size: 0.82rem;
}

.kios-chat-input:focus {
    border-color: #38bdf8 !important;
    box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.25) !important;
}

.btn-send-chat {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #0284c7;
    border: none;
    color: white;
    font-size: 0.9rem;
    transition: background 0.15s;
    flex-shrink: 0;
}

.btn-send-chat:hover {
    background: #0369a1;
}

/* Print Hide */
@media print {
    #kiosChatFloatingBtn, #kiosChatBox {
        display: none !important;
    }
}
</style>

<!-- Floating Toggle Button -->
<div id="kiosChatFloatingBtn" onclick="toggleKiosChat()" title="Obrolan Petugas & Admin Aktif">
    <i class="bi bi-chat-dots-fill"></i>
    <span id="kiosChatBadgeUnread">0</span>
    <span id="kiosOnlinePulse" title="Terhubung ke Jaringan Kios"></span>
</div>

<!-- Popup Chat Box -->
<div id="kiosChatBox" class="chat-hidden">
    <!-- Header Chat -->
    <div class="kios-chat-header">
        <div class="d-flex align-items-center gap-2">
            <div class="rounded-circle bg-primary bg-opacity-25 text-info d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                <i class="bi bi-chat-quote-fill fs-6"></i>
            </div>
            <div>
                <div class="fw-bold text-white lh-1" style="font-size: 0.85rem;">Obrolan Tim Kios</div>
                <small class="text-success" style="font-size: 0.7rem;" id="kiosOnlineCountText">
                    <i class="bi bi-circle-fill" style="font-size: 0.45rem;"></i> Memuat aktif...
                </small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-1">
            <button type="button" class="btn btn-sm btn-dark text-danger border-0 p-1" onclick="confirmClearChat()" title="Bersihkan Seluruh Riwayat Chat">
                <i class="bi bi-trash3"></i>
            </button>
            <button type="button" class="btn btn-sm btn-dark text-secondary border-0 p-1" onclick="toggleUsersDrawer()" title="Lihat Siapa Saja yang Online">
                <i class="bi bi-people-fill"></i>
            </button>
            <button type="button" class="btn btn-sm btn-dark text-secondary border-0 p-1" onclick="toggleKiosChat()" title="Tutup Chat">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>

    <!-- Active Users Drawer (Slide Drawer) -->
    <div id="kiosActiveUsersDrawer">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-bold text-secondary text-uppercase" style="font-size: 0.65rem;">Petugas Terdaftar & Status</span>
            <span class="badge bg-dark border border-secondary text-secondary" style="font-size: 0.62rem;" id="kiosDrawerCount">0 User</span>
        </div>
        <div id="kiosUsersList" class="d-flex flex-column gap-1">
            <!-- Diisi lewat JavaScript -->
        </div>
    </div>

    <!-- Body Pesan Chat -->
    <div id="kiosChatMessagesBody">
        <div class="text-center py-4 text-secondary small" id="kiosChatLoading">
            <span class="spinner-border spinner-border-sm me-1"></span> Menghubungkan obrolan...
        </div>
    </div>

    <!-- Input Bar Footer -->
    <div class="kios-chat-footer">
        <input type="text" id="kiosChatInputText" class="form-control kios-chat-input" placeholder="Ketik pesan..." maxlength="1000" autocomplete="off" onkeydown="handleChatKeyDown(event)">
        <button type="button" class="btn btn-send-chat" onclick="sendKiosChatMessage()" title="Kirim Pesan">
            <i class="bi bi-send-fill"></i>
        </button>
    </div>
</div>

<!-- JavaScript Obrolan Real-Time (Polling 3s) -->
<script>
const CHAT_API_URL = '<?= $chat_api_url ?>';
const CURRENT_USER_ID = <?= (int)$_SESSION['user_id'] ?>;

let lastMessageId = 0;
let isChatOpen = false;
let unreadCount = 0;
let chatPollingTimer = null;
let audioContext = null;

// Bunyikan Notifikasi Beep Halus
function playChatBeep() {
    try {
        if (!audioContext) {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (audioContext.state === 'suspended') {
            audioContext.resume();
        }
        const osc = audioContext.createOscillator();
        const gain = audioContext.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, audioContext.currentTime); // D5
        osc.frequency.exponentialRampToValueAtTime(880, audioContext.currentTime + 0.12); // A5
        gain.gain.setValueAtTime(0.08, audioContext.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.18);
        osc.connect(gain);
        gain.connect(audioContext.destination);
        osc.start();
        osc.stop(audioContext.currentTime + 0.18);
    } catch (e) {
        // Abaikan jika browser memblokir audio
    }
}

// Buka / Tutup Popup Chat
function toggleKiosChat() {
    const box = document.getElementById('kiosChatBox');
    const badge = document.getElementById('kiosChatBadgeUnread');
    
    isChatOpen = !isChatOpen;
    if (isChatOpen) {
        box.classList.remove('chat-hidden');
        unreadCount = 0;
        badge.style.display = 'none';
        badge.innerText = '0';
        
        // Scroll ke bawah
        scrollChatToBottom();
        
        // Fokuskan input
        setTimeout(() => {
            const input = document.getElementById('kiosChatInputText');
            if (input) input.focus();
        }, 150);
        
        // Jika belum ada pesan, ambil history
        if (lastMessageId === 0) {
            loadChatHistory();
        }
    } else {
        box.classList.add('chat-hidden');
    }
}

// Buka / Tutup Daftar Pengguna Aktif
function toggleUsersDrawer() {
    const drawer = document.getElementById('kiosActiveUsersDrawer');
    if (drawer.style.display === 'block') {
        drawer.style.display = 'none';
    } else {
        drawer.style.display = 'block';
    }
}

// Scroll otomatis ke dasar obrolan
function scrollChatToBottom() {
    const body = document.getElementById('kiosChatMessagesBody');
    if (body) {
        body.scrollTop = body.scrollHeight;
    }
}

// Format waktu jam:menit
function formatTimeOnly(dateStr) {
    if (!dateStr) return '';
    try {
        const d = new Date(dateStr.replace(' ', 'T'));
        return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    } catch (e) {
        return dateStr.substring(11, 16);
    }
}

// Render Bubble Pesan
function appendChatMessage(msg) {
    const body = document.getElementById('kiosChatMessagesBody');
    const isMine = (parseInt(msg.user_id) === CURRENT_USER_ID);
    
    const wrapper = document.createElement('div');
    wrapper.className = 'chat-msg ' + (isMine ? 'mine' : 'other');
    
    let roleBadge = '';
    if (!isMine) {
        const isAdm = (msg.sender_role === 'admin');
        roleBadge = `
            <div class="chat-sender-info">
                <strong class="text-white">${escapeHtml(msg.sender_name)}</strong>
                <span class="badge ${isAdm ? 'bg-warning text-dark' : 'bg-primary text-light'}" style="font-size: 0.58rem; padding: 1px 4px;">
                    ${isAdm ? 'Admin' : 'Kasir'}
                </span>
            </div>
        `;
    }
    
    wrapper.innerHTML = `
        ${roleBadge}
        <div class="chat-bubble">
            ${escapeHtml(msg.message)}
            <div class="chat-time">${formatTimeOnly(msg.created_at)}</div>
        </div>
    `;
    
    body.appendChild(wrapper);
}

// Update Tampilan Daftar Pengguna Aktif & Count
function renderOnlineUsers(users) {
    if (!users || !Array.isArray(users)) return;
    
    const onlineList = users.filter(u => parseInt(u.is_online) === 1);
    const countText = document.getElementById('kiosOnlineCountText');
    const drawerCount = document.getElementById('kiosDrawerCount');
    const listContainer = document.getElementById('kiosUsersList');
    
    if (countText) {
        countText.innerHTML = `<i class="bi bi-circle-fill text-success" style="font-size: 0.45rem;"></i> ${onlineList.length} Petugas Online`;
    }
    if (drawerCount) {
        drawerCount.innerText = `${onlineList.length}/${users.length} Online`;
    }
    
    if (listContainer) {
        listContainer.innerHTML = '';
        users.forEach(u => {
            const isOnline = (parseInt(u.is_online) === 1);
            const isMe = (parseInt(u.id) === CURRENT_USER_ID);
            const isAdm = (u.role === 'admin');
            
            const item = document.createElement('div');
            item.className = 'd-flex justify-content-between align-items-center py-1 px-2 rounded bg-dark border border-secondary';
            item.style.fontSize = '0.74rem';
            
            item.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <span class="rounded-circle ${isOnline ? 'bg-success' : 'bg-secondary'}" style="width: 8px; height: 8px;"></span>
                    <span class="text-white fw-semibold">${escapeHtml(u.nama_lengkap)} ${isMe ? '<small class="text-info">(Anda)</small>' : ''}</span>
                    <span class="badge ${isAdm ? 'bg-warning text-dark' : 'bg-secondary'}" style="font-size: 0.55rem; padding: 1px 3px;">
                        ${isAdm ? 'Admin' : 'Kasir'}
                    </span>
                </div>
                <span class="${isOnline ? 'text-success' : 'text-muted'}" style="font-size: 0.65rem;">
                    ${isOnline ? 'Aktif' : 'Offline'}
                </span>
            `;
            listContainer.appendChild(item);
        });
    }
}

// Ambil Riwayat Pesan Awal
function loadChatHistory() {
    fetch(CHAT_API_URL + '?action=history')
        .then(res => res.json())
        .then(data => {
            const loading = document.getElementById('kiosChatLoading');
            if (loading) loading.style.display = 'none';
            
            if (data.status === 'success') {
                const body = document.getElementById('kiosChatMessagesBody');
                body.innerHTML = '';
                
                if (data.messages && data.messages.length > 0) {
                    data.messages.forEach(msg => {
                        appendChatMessage(msg);
                        if (parseInt(msg.id) > lastMessageId) {
                            lastMessageId = parseInt(msg.id);
                        }
                    });
                } else {
                    body.innerHTML = '<div class="text-center py-4 text-secondary small">Belum ada obrolan. Mulai sapa tim Anda!</div>';
                }
                
                renderOnlineUsers(data.online_users);
                scrollChatToBottom();
            }
        })
        .catch(err => {
            console.error('Chat error:', err);
        });
}

// Polling Pesan Baru & Heartbeat Aktif
function pollChatUpdates() {
    fetch(CHAT_API_URL + '?action=poll&last_id=' + lastMessageId)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                renderOnlineUsers(data.online_users);

                // Cek jika database chat telah dibersihkan oleh pihak lain
                if (typeof data.max_id !== 'undefined' && data.max_id < lastMessageId) {
                    lastMessageId = data.max_id;
                    const body = document.getElementById('kiosChatMessagesBody');
                    if (body) {
                        body.innerHTML = '<div class="text-center py-4 text-secondary small"><i class="bi bi-trash3 text-muted me-1"></i>Riwayat chat telah dibersihkan.</div>';
                    }
                }
                
                if (data.messages && data.messages.length > 0) {
                    let hasNewFromOthers = false;
                    
                    data.messages.forEach(msg => {
                        appendChatMessage(msg);
                        if (parseInt(msg.id) > lastMessageId) {
                            lastMessageId = parseInt(msg.id);
                        }
                        if (parseInt(msg.user_id) !== CURRENT_USER_ID) {
                            hasNewFromOthers = true;
                        }
                    });
                    
                    scrollChatToBottom();
                    
                    // Notifikasi jika obrolan sedang tertutup
                    if (!isChatOpen && hasNewFromOthers) {
                        unreadCount += data.messages.length;
                        const badge = document.getElementById('kiosChatBadgeUnread');
                        if (badge) {
                            badge.innerText = unreadCount > 9 ? '9+' : unreadCount;
                            badge.style.display = 'block';
                        }
                        playChatBeep();
                    }
                }
            }
        })
        .catch(err => {
            // Heartbeat retry
        });
}

// Kirim Pesan
function sendKiosChatMessage() {
    const input = document.getElementById('kiosChatInputText');
    const text = input.value.trim();
    if (!text) return;
    
    input.value = '';
    
    const formData = new FormData();
    formData.append('action', 'send');
    formData.append('message', text);
    
    fetch(CHAT_API_URL, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            pollChatUpdates();
        } else {
            alert('Gagal mengirim pesan: ' + (data.message || 'Error'));
        }
    })
    .catch(err => {
        alert('Gagal menghubungi server.');
    });
}

// Bersihkan Riwayat Chat
function confirmClearChat() {
    if (confirm('Apakah Anda yakin ingin membersihkan seluruh riwayat chat? Tindakan ini akan menghapus pesan bagi kasir dan admin.')) {
        const formData = new FormData();
        formData.append('action', 'clear');

        fetch(CHAT_API_URL, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                lastMessageId = 0;
                const body = document.getElementById('kiosChatMessagesBody');
                if (body) {
                    body.innerHTML = '<div class="text-center py-4 text-secondary small"><i class="bi bi-trash3 text-muted me-1"></i>Riwayat chat berhasil dibersihkan.</div>';
                }
            } else {
                alert('Gagal membersihkan chat: ' + (data.message || 'Error'));
            }
        })
        .catch(err => {
            alert('Gagal menghubungi server.');
        });
    }
}

function handleChatKeyDown(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        sendKiosChatMessage();
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Jalankan Polling Setiap 3 Detik
document.addEventListener('DOMContentLoaded', function() {
    loadChatHistory();
    chatPollingTimer = setInterval(pollChatUpdates, 3000);
});
</script>
