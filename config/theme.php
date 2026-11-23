<?php
/**
 * Konfigurasi & Engine Tema Tampilan QiOS Cendekia
 * Mendukung Tema Profesional:
 * 1. light        - Terang Bersih (Clean White & Ocean Blue)
 * 2. kalem        - Kalem Santai (Slate Serene & Indigo)
 * 3. lembut       - Lembut Hangat (Warm Latte & Caramel Cream)
 * 4. pastel_mint  - Lembut Segar (Sage & Mint Soft)
 * 5. midnight     - Modern Gelap (Midnight Slate & Cyan) - Default
 * 6. emerald      - Emerald Eco (Forest Dark & Green)
 * 7. sapphire     - Royal Sapphire (Indigo & Electric Blue)
 * 8. amber        - Amber Warmth (Warm Espresso & Gold)
 */

// Handle Switch Theme Request dari halaman mana saja
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'switch_theme') {
    $sel_theme = trim($_POST['theme'] ?? '');
    $available_themes = get_all_themes();
    if (array_key_exists($sel_theme, $available_themes)) {
        if (function_exists('set_setting')) {
            set_setting('tema_aplikasi', $sel_theme, 'Tema visual sistem QiOS Cendekia');
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash_success'] = 'Tema berhasil diubah ke "' . $available_themes[$sel_theme]['name'] . '"!';
    }
    $redir = $_SERVER['REQUEST_URI'] ?? 'index.php';
    header('Location: ' . $redir);
    exit;
}

function get_all_themes() {
    return [
        'light' => [
            'key'         => 'light',
            'name'        => 'Terang Bersih (Clean White)',
            'badge'       => 'Terang',
            'category'    => 'Modern Light',
            'description' => 'Tema putih bersih yang cerah, tajam, dan jernih dengan aksen ocean blue. Sangat mudah dibaca di ruangan yang terang.',
            'mode'        => 'light',
            'preview'     => [
                'bg'     => '#f8fafc',
                'card'   => '#ffffff',
                'header' => '#f1f5f9',
                'accent' => '#0284c7',
                'text'   => '#0f172a'
            ],
            'vars' => [
                '--bg-darker'       => '#f8fafc',
                '--bg-card'         => '#ffffff',
                '--bg-header'       => '#f1f5f9',
                '--bg-secondary'    => '#e2e8f0',
                '--border-color'    => '#94a3b8',
                '--accent-cyan'     => '#0284c7',
                '--accent-primary'  => '#0284c7',
                '--accent-hover'    => '#0369a1',
                '--text-main'       => '#0f172a',
                '--text-muted'      => '#334155'
            ]
        ],
        'kalem' => [
            'key'         => 'kalem',
            'name'        => 'Kalem Santai (Slate Serene)',
            'badge'       => 'Kalem & Sejuk',
            'category'    => 'Soft Cool',
            'description' => 'Nuansa abu-abu kebiruan (slate) yang sejuk dan teduh dengan aksen indigo lembut. Tidak silau, sangat nyaman di mata kasir.',
            'mode'        => 'light',
            'preview'     => [
                'bg'     => '#eef2f6',
                'card'   => '#ffffff',
                'header' => '#e2e8f0',
                'accent' => '#4f46e5',
                'text'   => '#0f172a'
            ],
            'vars' => [
                '--bg-darker'       => '#eef2f6',
                '--bg-card'         => '#ffffff',
                '--bg-header'       => '#e2e8f0',
                '--bg-secondary'    => '#e2e8f0',
                '--border-color'    => '#94a3b8',
                '--accent-cyan'     => '#0d9488',
                '--accent-primary'  => '#4f46e5',
                '--accent-hover'    => '#4338ca',
                '--text-main'       => '#0f172a',
                '--text-muted'      => '#334155'
            ]
        ],
        'lembut' => [
            'key'         => 'lembut',
            'name'        => 'Lembut Hangat (Warm Latte)',
            'badge'       => 'Lembut & Hangat',
            'category'    => 'Warm Pastel',
            'description' => 'Nuansa krim vanilla latte dan pastel hangat yang manis, ramah, dan menenangkan dengan aksen karamel bronze.',
            'mode'        => 'light',
            'preview'     => [
                'bg'     => '#faf6f0',
                'card'   => '#ffffff',
                'header' => '#ede4d7',
                'accent' => '#d97706',
                'text'   => '#1c1917'
            ],
            'vars' => [
                '--bg-darker'       => '#faf6f0',
                '--bg-card'         => '#ffffff',
                '--bg-header'       => '#ede4d7',
                '--bg-secondary'    => '#f5ebe0',
                '--border-color'    => '#a8a29e',
                '--accent-cyan'     => '#ea580c',
                '--accent-primary'  => '#d97706',
                '--accent-hover'    => '#b45309',
                '--text-main'       => '#1c1917',
                '--text-muted'      => '#44403c'
            ]
        ],
        'pastel_mint' => [
            'key'         => 'pastel_mint',
            'name'        => 'Lembut Segar (Sage & Mint)',
            'badge'       => 'Segar & Rileks',
            'category'    => 'Fresh Pastel',
            'description' => 'Nuansa hijau sage dan mint lembut yang asri dan sejuk. Memberikan kenyamanan visual dan meredakan lelah mata.',
            'mode'        => 'light',
            'preview'     => [
                'bg'     => '#f2f8f4',
                'card'   => '#ffffff',
                'header' => '#dceae1',
                'accent' => '#059669',
                'text'   => '#052e16'
            ],
            'vars' => [
                '--bg-darker'       => '#f2f8f4',
                '--bg-card'         => '#ffffff',
                '--bg-header'       => '#dceae1',
                '--bg-secondary'    => '#dcfce7',
                '--border-color'    => '#86efac',
                '--accent-cyan'     => '#0d9488',
                '--accent-primary'  => '#059669',
                '--accent-hover'    => '#047857',
                '--text-main'       => '#052e16',
                '--text-muted'      => '#166534'
            ]
        ],
        'midnight' => [
            'key'         => 'midnight',
            'name'        => 'Modern Gelap (Midnight Slate)',
            'badge'       => 'Gelap Default',
            'category'    => 'Modern Dark',
            'description' => 'Tema gelap navy slate modern dengan aksen cyan neon. Ergonomis dan nyaman untuk penggunaan kasir intensif.',
            'mode'        => 'dark',
            'preview'     => [
                'bg'     => '#090d16',
                'card'   => '#111827',
                'header' => '#0f172a',
                'accent' => '#38bdf8',
                'text'   => '#f1f5f9'
            ],
            'vars' => [
                '--bg-darker'       => '#090d16',
                '--bg-card'         => '#111827',
                '--bg-header'       => '#0f172a',
                '--bg-secondary'    => '#1e293b',
                '--border-color'    => '#334155',
                '--accent-cyan'     => '#38bdf8',
                '--accent-primary'  => '#38bdf8',
                '--accent-hover'    => '#0284c7',
                '--text-main'       => '#f1f5f9',
                '--text-muted'      => '#94a3b8'
            ]
        ],
        'emerald' => [
            'key'         => 'emerald',
            'name'        => 'Emerald Eco Dark',
            'badge'       => 'Segar Alami',
            'category'    => 'Fresh Dark',
            'description' => 'Nuansa deep forest pine gelap dengan aksen hijau emerald. Memberikan kesan ramah lingkungan, tenang, dan higienis.',
            'mode'        => 'dark',
            'preview'     => [
                'bg'     => '#08120d',
                'card'   => '#0e1e17',
                'header' => '#142820',
                'accent' => '#10b981',
                'text'   => '#f0fdf4'
            ],
            'vars' => [
                '--bg-darker'       => '#08120d',
                '--bg-card'         => '#0e1e17',
                '--bg-header'       => '#142820',
                '--bg-secondary'    => '#1a362b',
                '--border-color'    => '#244b3c',
                '--accent-cyan'     => '#10b981',
                '--accent-primary'  => '#10b981',
                '--accent-hover'    => '#059669',
                '--text-main'       => '#f0fdf4',
                '--text-muted'      => '#86efac'
            ]
        ],
        'sapphire' => [
            'key'         => 'sapphire',
            'name'        => 'Royal Sapphire Dark',
            'badge'       => 'Mewah Elegan',
            'category'    => 'Luxury Dark',
            'description' => 'Nuansa deep indigo dan navy gelap mewah dengan aksen electric blue yang berkelas.',
            'mode'        => 'dark',
            'preview'     => [
                'bg'     => '#0a0d1e',
                'card'   => '#121733',
                'header' => '#182046',
                'accent' => '#6366f1',
                'text'   => '#f8fafc'
            ],
            'vars' => [
                '--bg-darker'       => '#0a0d1e',
                '--bg-card'         => '#121733',
                '--bg-header'       => '#182046',
                '--bg-secondary'    => '#222d60',
                '--border-color'    => '#2f3d82',
                '--accent-cyan'     => '#818cf8',
                '--accent-primary'  => '#6366f1',
                '--accent-hover'    => '#4f46e5',
                '--text-main'       => '#f8fafc',
                '--text-muted'      => '#a5b4fc'
            ]
        ],
        'amber' => [
            'key'         => 'amber',
            'name'        => 'Amber Warmth Dark',
            'badge'       => 'Hangat Elegan',
            'category'    => 'Warm Dark',
            'description' => 'Nuansa warm espresso charcoal dengan aksen amber gold keemasan yang membangkitkan selera.',
            'mode'        => 'dark',
            'preview'     => [
                'bg'     => '#130f0c',
                'card'   => '#1d1612',
                'header' => '#291e18',
                'accent' => '#f59e0b',
                'text'   => '#fffbeb'
            ],
            'vars' => [
                '--bg-darker'       => '#130f0c',
                '--bg-card'         => '#1d1612',
                '--bg-header'       => '#291e18',
                '--bg-secondary'    => '#382920',
                '--border-color'    => '#4f3b2f',
                '--accent-cyan'     => '#fbbf24',
                '--accent-primary'  => '#f59e0b',
                '--accent-hover'    => '#d97706',
                '--text-main'       => '#fffbeb',
                '--text-muted'      => '#fcd34d'
            ]
        ]
    ];
}

/**
 * Mendapatkan Kunci Tema Aktif
 */
function get_app_theme() {
    $theme = function_exists('get_setting') ? get_setting('tema_aplikasi', 'midnight') : 'midnight';
    $themes = get_all_themes();
    if (!array_key_exists($theme, $themes)) {
        return 'midnight';
    }
    return $theme;
}

/**
 * Mendapatkan Data Tema Aktif
 */
function get_current_theme_data() {
    $themes = get_all_themes();
    $active_key = get_app_theme();
    return $themes[$active_key] ?? $themes['midnight'];
}

/**
 * Merender Blok CSS & Variabel Tema ke HTML Header
 */
function render_theme_styles() {
    $t = get_current_theme_data();
    $is_light = ($t['mode'] === 'light');

    $css = "<!-- QiOS Dynamic Theme: " . htmlspecialchars($t['name']) . " -->\n";
    $css .= "<style id='qios-theme-styles'>\n";
    $css .= ":root {\n";
    foreach ($t['vars'] as $var => $val) {
        $css .= "    {$var}: {$val};\n";
    }
    $css .= "}\n";

    // 1. Global typography & visual contrast (WCAG AAA Compliance)
    $css .= "body { background: radial-gradient(circle at top right, var(--bg-header), var(--bg-darker)) !important; background-color: var(--bg-darker) !important; color: var(--text-main) !important; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif !important; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }\n";
    $css .= "h1, h2, h3, h4, h5, h6, .h1, .h2, .h3, .h4, .h5, .h6 { color: var(--text-main) !important; font-weight: 700 !important; }\n";
    $css .= "label, .form-label { color: var(--text-main) !important; font-weight: 600 !important; }\n";
    $css .= ".form-text, .text-muted, .text-secondary { color: var(--text-muted) !important; }\n";

    // 2. Card, Modals & Surfaces
    $css .= ".card, .pos-product-card, .pos-cart-panel, .stat-card, .login-card { background-color: var(--bg-card) !important; border-color: var(--border-color) !important; color: var(--text-main) !important; }\n";
    $css .= ".admin-header, .kasir-topbar, .mutasi-topbar, .login-header { background-color: var(--bg-header) !important; border-color: var(--border-color) !important; }\n";
    $css .= ".admin-menubar { background: var(--bg-secondary) !important; }\n";
    $css .= ".admin-nav .nav-link { color: var(--text-muted) !important; }\n";
    $css .= ".admin-nav .nav-link:hover, .admin-nav .nav-link.active { color: #ffffff !important; background: var(--accent-primary) !important; border-color: var(--accent-primary) !important; }\n";
    $css .= ".border-secondary { border-color: var(--border-color) !important; }\n";

    // 3. Modal System (Ergonomis & Kontras Tinggi)
    $css .= ".modal-content { background-color: var(--bg-card) !important; border: 1.5px solid var(--border-color) !important; color: var(--text-main) !important; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important; }\n";
    $css .= ".modal-header { border-bottom: 1px solid var(--border-color) !important; color: var(--text-main) !important; }\n";
    $css .= ".modal-footer { border-top: 1px solid var(--border-color) !important; }\n";
    $css .= ".modal-title { color: var(--text-main) !important; font-weight: 700 !important; }\n";

    // 4. Form Controls & Inputs (Jelas & Tajam)
    $css .= ".form-control, .form-select { border: 1.5px solid var(--border-color) !important; color: var(--text-main) !important; font-size: 0.95rem !important; font-weight: 500 !important; border-radius: 8px !important; }\n";
    $css .= ".input-group-text { border: 1.5px solid var(--border-color) !important; color: var(--text-main) !important; font-weight: 600 !important; }\n";

    // 5. Status Box & Connection Boxes (Khusus untuk tampilan jelas ramah mata)
    $css .= ".status-info-box, .theme-info-box { background-color: var(--bg-secondary) !important; border: 1.5px solid var(--border-color) !important; border-radius: 10px !important; color: var(--text-main) !important; }\n";
    $css .= ".status-info-box span, .status-info-box strong, .theme-info-box span, .theme-info-box strong { color: var(--text-main) !important; }\n";

    // 6. Buttons
    $css .= ".btn-primary { background-color: var(--accent-primary) !important; border-color: var(--accent-primary) !important; color: #ffffff !important; font-weight: 600 !important; }\n";
    $css .= ".btn-primary:hover { background-color: var(--accent-hover) !important; border-color: var(--accent-hover) !important; color: #ffffff !important; }\n";
    $css .= ".text-info { color: var(--accent-cyan) !important; }\n";

    if ($is_light) {
        $css .= "/* === PENGATURAN MODE TERANG / KALEM / LEMBUT (WCAG AAA) === */\n";
        // Warna teks pada background putih / terang
        $css .= ".text-white:not(.btn):not(.badge):not(.badge-solid) { color: var(--text-main) !important; }\n";
        $css .= ".text-light { color: var(--text-main) !important; }\n";
        $css .= ".navbar-brand { color: var(--text-main) !important; }\n";
        
        // Input Controls
        $css .= ".form-control, .form-select { background-color: #ffffff !important; color: var(--text-main) !important; }\n";
        $css .= ".form-control::placeholder { color: var(--text-muted) !important; opacity: 0.8 !important; }\n";
        $css .= ".form-control:focus, .form-select:focus { background-color: #ffffff !important; border-color: var(--accent-primary) !important; color: var(--text-main) !important; box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.25) !important; }\n";
        $css .= ".input-group-text { background-color: var(--bg-header) !important; }\n";

        // Tombol Close Modal
        $css .= ".btn-close { filter: none !important; opacity: 0.8 !important; }\n";
        $css .= ".btn-close:hover { opacity: 1 !important; }\n";

        // Badges dengan kontras tajam (Bukan opacity pudar)
        $css .= ".badge.bg-success, .badge-status-active { background-color: #dcfce7 !important; color: #14532d !important; border: 1.5px solid #86efac !important; font-weight: 700 !important; }\n";
        $css .= ".badge.bg-info, .badge-status-dynamic { background-color: #e0f2fe !important; color: #075985 !important; border: 1.5px solid #7dd3fc !important; font-weight: 700 !important; }\n";
        $css .= ".badge.bg-warning { background-color: #fef9c3 !important; color: #713f12 !important; border: 1.5px solid #fde047 !important; font-weight: 700 !important; }\n";
        $css .= ".badge.bg-danger { background-color: #fee2e2 !important; color: #7f1d1d !important; border: 1.5px solid #fca5a5 !important; font-weight: 700 !important; }\n";
        $css .= ".badge.bg-secondary { background-color: var(--bg-header) !important; color: var(--text-main) !important; border: 1.5px solid var(--border-color) !important; font-weight: 600 !important; }\n";
        $css .= ".badge.bg-dark { background-color: var(--bg-header) !important; color: var(--text-main) !important; border: 1.5px solid var(--border-color) !important; }\n";

        // Badges Barcode Ergonomis & Kontras Tinggi (Light Mode)
        $css .= ".badge-barcode { background-color: #e0f2fe !important; color: #0369a1 !important; border: 1.5px solid #0284c7 !important; font-weight: 700 !important; font-size: 0.74rem !important; padding: 4px 8px !important; border-radius: 6px !important; box-shadow: 0 1px 2px rgba(2,132,199,0.1) !important; }\n";
        $css .= ".badge-no-barcode { background-color: #f1f5f9 !important; color: #475569 !important; border: 1.5px dashed #94a3b8 !important; font-weight: 600 !important; font-size: 0.72rem !important; padding: 4px 8px !important; border-radius: 6px !important; }\n";
        $css .= ".prod-name-title { color: var(--text-main) !important; font-weight: 700 !important; font-size: 0.95rem !important; }\n";

        // Kontainer gelap yang ada di file legacy dialihkan ke warna terang nyaman
        $css .= ".bg-dark:not(.badge):not(.btn) { background-color: var(--bg-secondary) !important; color: var(--text-main) !important; }\n";
        $css .= ".bg-black:not(.badge):not(.btn) { background-color: var(--bg-header) !important; color: var(--text-main) !important; }\n";

        // Tabel untuk mode Terang / Kalem / Lembut
        $css .= ".table { color: var(--text-main) !important; border-color: var(--border-color) !important; }\n";
        $css .= ".table th, .table td { border-color: var(--border-color) !important; color: var(--text-main) !important; }\n";
        $css .= ".table-dark { --bs-table-bg: var(--bg-card) !important; --bs-table-color: var(--text-main) !important; --bs-table-border-color: var(--border-color) !important; background-color: var(--bg-card) !important; color: var(--text-main) !important; }\n";
        $css .= ".table-dark thead th, .table-secondary { background-color: var(--bg-header) !important; color: var(--text-main) !important; border-color: var(--border-color) !important; font-weight: 700 !important; }\n";
        $css .= ".table-dark tbody tr:hover td, .table-mutasi tbody tr:hover td { background-color: var(--bg-secondary) !important; color: var(--text-main) !important; }\n";

        // Kasir POS Cards
        $css .= ".pos-product-card { background-color: var(--bg-card) !important; border: 1.5px solid var(--border-color) !important; box-shadow: 0 2px 8px rgba(0,0,0,0.04) !important; }\n";
        $css .= ".pos-product-card:hover:not(.out-of-stock) { border-color: var(--accent-primary) !important; box-shadow: 0 4px 14px rgba(0,0,0,0.08) !important; }\n";
        $css .= ".pos-cart-panel { background-color: var(--bg-card) !important; border: 1.5px solid var(--border-color) !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05) !important; }\n";
        $css .= ".total-display-box { background: linear-gradient(135deg, var(--bg-header) 0%, var(--bg-secondary) 100%) !important; border: 1.5px solid var(--border-color) !important; }\n";
        $css .= ".grand-total-amount { color: var(--accent-primary) !important; }\n";
        $css .= ".btn-quick-cash { background-color: var(--bg-header) !important; border-color: var(--border-color) !important; color: var(--text-main) !important; font-weight: 600 !important; }\n";
        $css .= ".btn-quick-cash:hover { background-color: var(--bg-secondary) !important; border-color: var(--accent-primary) !important; color: var(--text-main) !important; }\n";
        $css .= ".cart-qty-input { background-color: #ffffff !important; border: 1.5px solid var(--border-color) !important; color: var(--text-main) !important; font-weight: 700 !important; }\n";
        $css .= ".btn-outline-secondary { border-color: var(--border-color) !important; color: var(--text-muted) !important; }\n";
        $css .= ".btn-outline-secondary:hover { background-color: var(--bg-header) !important; color: var(--text-main) !important; }\n";
        $css .= ".login-header h4 { color: var(--text-main) !important; }\n";
        $css .= ".login-header p { color: var(--text-muted) !important; }\n";
        $css .= ".login-card { box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.1) !important; border: 1.5px solid var(--border-color) !important; }\n";
    } else {
        $css .= "/* === PENGATURAN MODE GELAP (MIDNIGHT / EMERALD / SAPPHIRE / AMBER) === */\n";
        $css .= ".form-control, .form-select { background-color: var(--bg-secondary) !important; color: #f8fafc !important; }\n";
        $css .= ".form-control:focus, .form-select:focus { background-color: var(--bg-secondary) !important; border-color: var(--accent-primary) !important; color: #f8fafc !important; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.25) !important; }\n";
        $css .= ".input-group-text { background-color: var(--bg-header) !important; color: #f8fafc !important; }\n";
        $css .= ".btn-close { filter: invert(1) grayscale(100%) brightness(200%) !important; opacity: 0.9 !important; }\n";

        // Badges Mode Gelap (Kontras Cerah di atas Dark Surface)
        $css .= ".badge.bg-success, .badge-status-active { background-color: rgba(34, 197, 94, 0.25) !important; color: #4ade80 !important; border: 1.5px solid #22c55e !important; font-weight: 700 !important; }\n";
        $css .= ".badge.bg-info, .badge-status-dynamic { background-color: rgba(56, 189, 248, 0.25) !important; color: #38bdf8 !important; border: 1.5px solid #0ea5e9 !important; font-weight: 700 !important; }\n";
        $css .= ".badge.bg-warning { background-color: rgba(234, 179, 8, 0.25) !important; color: #facc15 !important; border: 1.5px solid #eab308 !important; font-weight: 700 !important; }\n";
        $css .= ".badge.bg-danger { background-color: rgba(239, 68, 68, 0.25) !important; color: #f87171 !important; border: 1.5px solid #ef4444 !important; font-weight: 700 !important; }\n";
        $css .= ".badge.bg-secondary { background-color: #1e293b !important; color: #cbd5e1 !important; border: 1.5px solid #475569 !important; font-weight: 600 !important; }\n";

        // Badges Barcode Ergonomis & Kontras Tinggi (Dark Mode)
        $css .= ".badge-barcode { background-color: rgba(14, 165, 233, 0.2) !important; color: #38bdf8 !important; border: 1.5px solid #0284c7 !important; font-weight: 700 !important; font-size: 0.74rem !important; padding: 4px 8px !important; border-radius: 6px !important; box-shadow: 0 1px 4px rgba(0,0,0,0.3) !important; }\n";
        $css .= ".badge-no-barcode { background-color: rgba(148, 163, 184, 0.15) !important; color: #94a3b8 !important; border: 1.5px dashed #475569 !important; font-weight: 600 !important; font-size: 0.72rem !important; padding: 4px 8px !important; border-radius: 6px !important; }\n";
        $css .= ".prod-name-title { color: #f8fafc !important; font-weight: 700 !important; font-size: 0.95rem !important; }\n";
    }

    $css .= "</style>\n";
    return $css;
}

/**
 * Merender Modal Pemilihan Tema Interaktif (Dapat ditaruh di Kasir / Mutasi / Admin)
 */
function render_theme_modal() {
    $all_themes = get_all_themes();
    $current_theme_key = get_app_theme();

    ob_start();
    ?>
    <div class="modal fade" id="modalPilihTema" tabindex="-1" aria-labelledby="modalPilihTemaLabel" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content shadow-lg border-0 rounded-4 overflow-hidden">
                <div class="modal-header border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-palette2 text-warning fs-5"></i>
                        <h5 class="modal-title fw-bold mb-0" id="modalPilihTemaLabel">Pilih Tema Tampilan</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-secondary small mb-3">Pilih nuansa warna yang paling nyaman untuk Anda (Terang, Kalem, Lembut, atau Gelap). Perubahan langsung aktif di seluruh sistem.</p>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="switch_theme">
                        <div class="row g-3">
                            <?php foreach ($all_themes as $th_key => $th): ?>
                                <?php $is_active = ($th_key === $current_theme_key); ?>
                                <div class="col-md-6 col-lg-3 col-6">
                                    <label class="theme-choice-card d-block h-100 p-3 rounded-4 border position-relative <?= $is_active ? 'border-primary shadow-sm' : 'border-secondary border-opacity-50' ?>" 
                                           style="background: <?= $th['preview']['card'] ?>; cursor: pointer; transition: all 0.18s; box-shadow: <?= $is_active ? '0 0 0 2px ' . $th['preview']['accent'] : 'none' ?>;">
                                        <input type="radio" name="theme" value="<?= e($th_key) ?>" class="d-none" <?= $is_active ? 'checked' : '' ?> onchange="this.form.submit()">
                                        
                                        <!-- Header Theme Card -->
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <span class="badge" style="background: <?= $th['preview']['accent'] ?>; color: <?= $th['mode'] === 'light' ? '#ffffff' : '#000000' ?>; font-size: 0.68rem; font-weight: 700;">
                                                <?= e($th['badge']) ?>
                                            </span>
                                            <?php if ($is_active): ?>
                                                <i class="bi bi-check-circle-fill text-success fs-6"></i>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Nama Tema -->
                                        <div class="fw-bold mb-1" style="color: <?= $th['preview']['text'] ?>; font-size: 0.85rem;">
                                            <?= e($th['name']) ?>
                                        </div>

                                        <!-- Color Swatches Preview -->
                                        <div class="d-flex gap-1 my-2">
                                            <span style="display:inline-block; width:18px; height:18px; border-radius:50%; background:<?= $th['preview']['bg'] ?>; border:1px solid rgba(0,0,0,0.15);" title="Background"></span>
                                            <span style="display:inline-block; width:18px; height:18px; border-radius:50%; background:<?= $th['preview']['card'] ?>; border:1px solid rgba(0,0,0,0.15);" title="Card"></span>
                                            <span style="display:inline-block; width:18px; height:18px; border-radius:50%; background:<?= $th['preview']['accent'] ?>; border:1px solid rgba(0,0,0,0.15);" title="Accent"></span>
                                        </div>

                                        <!-- Keterangan Singkat -->
                                        <p class="small mb-0 text-truncate" style="color: <?= $th['mode'] === 'light' ? '#64748b' : '#94a3b8' ?>; font-size: 0.72rem;" title="<?= e($th['description']) ?>">
                                            <?= e($th['description']) ?>
                                        </p>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </form>
                </div>
                <div class="modal-footer py-2 px-4 justify-content-between bg-light bg-opacity-25 border-top">
                    <small class="text-secondary"><i class="bi bi-info-circle me-1"></i>Tema tersimpan di database dan berlaku otomatis.</small>
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
