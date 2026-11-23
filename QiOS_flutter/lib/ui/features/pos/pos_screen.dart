import 'dart:io';
import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:kiosk_cendekia/ui/core/app_colors.dart';
import 'package:kiosk_cendekia/ui/core/currency_formatter.dart';
import 'package:kiosk_cendekia/data/models/product_model.dart';
import 'package:kiosk_cendekia/data/models/user_model.dart';
import 'package:kiosk_cendekia/data/services/api_service.dart';
import 'package:kiosk_cendekia/ui/features/auth/auth_view_model.dart';
import 'pos_view_model.dart';
import 'widgets/chat_dialog.dart';
import 'widgets/receipt_dialog.dart';
import 'widgets/server_settings_dialog.dart';

class PosScreen extends StatefulWidget {
  const PosScreen({super.key});

  @override
  State<PosScreen> createState() => _PosScreenState();
}

class _PosScreenState extends State<PosScreen> {
  final _searchController = TextEditingController();
  final _cashController = TextEditingController();
  final _apiService = ApiService();

  Timer? _bgTimer;
  int _lastKnownChatId = 0;
  int _unreadChatCount = 0;
  bool _isChatOpen = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<PosViewModel>().fetchInitialData();
      _initInitialChatStatus();
      _startBackgroundService();
    });
  }

  @override
  void dispose() {
    _bgTimer?.cancel();
    _searchController.dispose();
    _cashController.dispose();
    super.dispose();
  }

  Future<void> _initInitialChatStatus() async {
    final authVm = context.read<AuthViewModel>();
    final userId = authVm.user?.id ?? 3;
    try {
      final res = await _apiService.getChatHistory(userId: userId);
      final List msgs = res['messages'] ?? [];
      if (msgs.isNotEmpty) {
        _lastKnownChatId = msgs.last.id;
      }
    } catch (_) {}
  }

  void _startBackgroundService() {
    _bgTimer?.cancel();
    _bgTimer = Timer.periodic(const Duration(seconds: 3), (_) async {
      if (!mounted) return;
      final authVm = context.read<AuthViewModel>();
      final currentUser = authVm.user;
      if (currentUser == null) return;

      // 1. Cek Heartbeat Sesi (Periksa apakah akun diambil alih di komputer lain)
      final isSessionValid = await authVm.checkHeartbeat();
      if (!mounted) return;
      if (!isSessionValid) {
        _bgTimer?.cancel();
        _showSessionExpiredDialog();
        return;
      }

      // 2. Polling Pesan Chat Baru dari Admin
      try {
        final newMsgs = await _apiService.pollChatMessages(
          lastId: _lastKnownChatId,
          userId: currentUser.id,
        );

        if (!mounted || newMsgs.isEmpty) return;

        bool hasNewAdminMsg = false;
        String latestAdminMsg = '';
        String latestAdminSender = 'Admin';

        for (var msg in newMsgs) {
          if (msg.id > _lastKnownChatId) {
            _lastKnownChatId = msg.id;
          }
          if (msg.isAdmin || !msg.isMe(currentUser.id)) {
            hasNewAdminMsg = true;
            latestAdminMsg = msg.message;
            latestAdminSender = msg.senderName.isNotEmpty ? msg.senderName : 'Admin';
          }
        }

        if (hasNewAdminMsg && !_isChatOpen) {
          setState(() {
            _unreadChatCount += newMsgs.where((m) => m.isAdmin || !m.isMe(currentUser.id)).length;
          });

          // Bunyikan Audio Notifikasi Windows
          SystemSound.play(SystemSoundType.alert);

          // Munculkan Floating Toast Notifikasi
          _showChatNotificationBanner(latestAdminSender, latestAdminMsg, currentUser);
        }
      } catch (_) {}
    });
  }

  void _showChatNotificationBanner(String sender, String text, UserModel currentUser) {
    ScaffoldMessenger.of(context).clearSnackBars();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        behavior: SnackBarBehavior.floating,
        backgroundColor: const Color(0xFF0F172A),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: const BorderSide(color: Color(0xFF38BDF8), width: 1.5),
        ),
        margin: const EdgeInsets.only(bottom: 24, left: 24, right: 24),
        duration: const Duration(seconds: 6),
        content: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(8),
              decoration: const BoxDecoration(
                color: Color(0xFF0284C7),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.chat_bubble_outline, color: Colors.white, size: 20),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Text(
                        'Pesan Baru dari $sender',
                        style: const TextStyle(color: Color(0xFF38BDF8), fontWeight: FontWeight.bold, fontSize: 13),
                      ),
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                        decoration: BoxDecoration(
                          color: const Color(0xFFD97706),
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: const Text('ADMIN', style: TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold)),
                      ),
                    ],
                  ),
                  const SizedBox(height: 3),
                  Text(
                    text,
                    style: const TextStyle(color: Colors.white, fontSize: 12),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            ElevatedButton(
              onPressed: () {
                ScaffoldMessenger.of(context).hideCurrentSnackBar();
                _openChatDialog(currentUser);
              },
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF0284C7),
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
              ),
              child: const Text('Buka Chat', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
            ),
          ],
        ),
      ),
    );
  }

  void _openChatDialog(UserModel currentUser) async {
    setState(() {
      _unreadChatCount = 0;
      _isChatOpen = true;
    });

    await showDialog(
      context: context,
      builder: (_) => ChatDialog(currentUser: currentUser),
    );

    if (mounted) {
      setState(() {
        _isChatOpen = false;
      });
    }
  }

  void _showSessionExpiredDialog() {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1E293B),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Row(
          children: [
            Icon(Icons.gpp_bad_rounded, color: Color(0xFFEF4444), size: 28),
            SizedBox(width: 10),
            Text('Sesi Berakhir', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
          ],
        ),
        content: const Text(
          'Akun kasir Anda telah login di komputer kasir lain.\n\nSesuai standar kasir profesional, 1 akun kasir hanya boleh aktif di 1 komputer pada saat bersamaan. Sesi di komputer ini telah dinonaktifkan.',
          style: TextStyle(color: Color(0xFFCBD5E1), fontSize: 13, height: 1.4),
        ),
        actions: [
          ElevatedButton(
            onPressed: () {
              Navigator.pop(ctx);
              Navigator.pushReplacementNamed(context, '/login');
            },
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF0284C7)),
            child: const Text('Ke Halaman Login', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );
  }

  void _onCheckout() async {
    final posVm = context.read<PosViewModel>();
    final authVm = context.read<AuthViewModel>();
    final userId = authVm.user?.id ?? 1;

    try {
      final receiptData = await posVm.submitCheckout(userId);
      _cashController.clear();
      if (mounted) {
        showDialog(
          context: context,
          barrierDismissible: false,
          builder: (_) => ReceiptDialog(data: receiptData),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(e.toString().replaceAll('Exception: ', '')),
            backgroundColor: AppColors.accentRed,
          ),
        );
      }
    }
  }

  void _setCashAmount(PosViewModel posVm, double amount) {
    posVm.setCash(amount);
    _cashController.text = amount > 0 ? amount.toInt().toString() : '';
  }

  void _addCashAmount(PosViewModel posVm, double amount) {
    final newAmount = posVm.cashGiven + amount;
    posVm.setCash(newAmount);
    _cashController.text = newAmount > 0 ? newAmount.toInt().toString() : '';
  }

  void _handleLogoutAttempt(BuildContext context, PosViewModel posVm, AuthViewModel authVm) {
    if (posVm.cart.isNotEmpty) {
      showDialog(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => AlertDialog(
          backgroundColor: const Color(0xFF1E293B),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: const Row(
            children: [
              Icon(Icons.warning_amber_rounded, color: Color(0xFFF59E0B), size: 28),
              SizedBox(width: 10),
              Text(
                'Transaksi Belum Selesai!',
                style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16),
              ),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Masih ada ${posVm.totalItemsCount} item barang di keranjang belanja dengan total ${CurrencyFormatter.format(posVm.grandTotal)} yang belum diselesaikan pembayarannya.',
                style: const TextStyle(color: Color(0xFFCBD5E1), fontSize: 13, height: 1.4),
              ),
              const SizedBox(height: 12),
              const Text(
                'Apakah Anda yakin ingin membatalkan transaksi dan logout ke halaman login?',
                style: TextStyle(color: Color(0xFFF87171), fontSize: 13, fontWeight: FontWeight.bold),
              ),
            ],
          ),
          actions: [
            // NO -> Lanjut transaksi
            TextButton.icon(
              onPressed: () => Navigator.pop(ctx),
              icon: const Icon(Icons.arrow_back, size: 16, color: Color(0xFF38BDF8)),
              label: const Text(
                'No, Lanjut Transaksi',
                style: TextStyle(color: Color(0xFF38BDF8), fontWeight: FontWeight.bold),
              ),
            ),
            // YES -> Keluar ke login
            ElevatedButton.icon(
              onPressed: () {
                Navigator.pop(ctx);
                posVm.clearCart();
                _cashController.clear();
                authVm.logout();
                Navigator.pushReplacementNamed(context, '/login');
              },
              icon: const Icon(Icons.logout, size: 16, color: Colors.white),
              label: const Text(
                'Yes, Logout',
                style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
              ),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFFDC2626),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
          ],
        ),
      );
    } else {
      // Keranjang kosong -> konfirmasi logout biasa
      showDialog(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: const Color(0xFF1E293B),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: const Row(
            children: [
              Icon(Icons.logout_rounded, color: Color(0xFF38BDF8), size: 24),
              SizedBox(width: 10),
              Text(
                'Konfirmasi Logout',
                style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16),
              ),
            ],
          ),
          content: const Text(
            'Apakah Anda yakin ingin mengakhiri sesi kasir dan keluar ke halaman login?',
            style: TextStyle(color: Color(0xFFCBD5E1), fontSize: 13),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Batal', style: TextStyle(color: Color(0xFF94A3B8))),
            ),
            ElevatedButton(
              onPressed: () {
                Navigator.pop(ctx);
                authVm.logout();
                Navigator.pushReplacementNamed(context, '/login');
              },
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF0284C7),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
              child: const Text(
                'Ya, Logout',
                style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
              ),
            ),
          ],
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final authVm = context.watch<AuthViewModel>();
    final posVm = context.watch<PosViewModel>();

    return CallbackShortcuts(
      bindings: {
        const SingleActivator(LogicalKeyboardKey.f9): () {
          if (posVm.isPaymentValid) {
            _onCheckout();
          }
        },
      },
      child: Focus(
        autofocus: true,
        child: PopScope(
          canPop: false,
          onPopInvokedWithResult: (didPop, _) {
            if (!didPop) {
              _handleLogoutAttempt(context, posVm, authVm);
            }
          },
          child: Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: AppColors.surfaceVariant,
        elevation: 0,
        titleSpacing: 16,
        title: Row(
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: Image.asset(
                'assets/images/qios_logo.png',
                width: 38,
                height: 38,
                fit: BoxFit.contain,
              ),
            ),
            const SizedBox(width: 12),
            const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(
                  children: [
                    Text(
                      'Qi',
                      style: TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w900,
                        fontSize: 18,
                        letterSpacing: 0.5,
                      ),
                    ),
                    Text(
                      'OS',
                      style: TextStyle(
                        color: Color(0xFF38BDF8),
                        fontWeight: FontWeight.w900,
                        fontSize: 18,
                        letterSpacing: 0.5,
                      ),
                    ),
                    SizedBox(width: 6),
                    Text(
                      'Cendekia',
                      style: TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                        fontSize: 16,
                      ),
                    ),
                  ],
                ),
                Text(
                  'Smart & Digital POS',
                  style: TextStyle(
                    color: Color(0xFF94A3B8),
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    letterSpacing: 0.3,
                  ),
                ),
              ],
            ),
          ],
        ),
        actions: [
          // Tombol Buka Dashboard Admin (khusus role admin)
          if (authVm.user?.role == 'admin')
            Padding(
              padding: const EdgeInsets.only(right: 6),
              child: ElevatedButton.icon(
                onPressed: () {
                  String adminUrl = authVm.currentServerUrl;
                  if (!adminUrl.endsWith('/')) adminUrl += '/';
                  adminUrl += 'admin/index.php';
                  Process.run('cmd', ['/c', 'start', '', adminUrl]);
                },
                icon: const Icon(Icons.dashboard_customize, size: 16, color: Colors.amber),
                label: const Text('Dashboard Admin', style: TextStyle(color: Colors.amber, fontSize: 12, fontWeight: FontWeight.bold)),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF1E293B),
                  side: const BorderSide(color: Colors.amber),
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
              ),
            ),
          // Tombol Refresh Katalog Produk
          IconButton(
            icon: const Icon(Icons.refresh, size: 20, color: AppColors.primaryLight),
            tooltip: 'Segarkan Produk & Stok',
            onPressed: () => posVm.fetchInitialData(),
          ),
          const SizedBox(width: 4),

          // Info Server IP & Tombol Ganti
          TextButton.icon(
            onPressed: () {
              showDialog(
                context: context,
                builder: (_) => const ServerSettingsDialog(),
              );
            },
            icon: const Icon(Icons.dns_outlined, size: 16, color: AppColors.textMuted),
            label: Text(
              authVm.currentServerUrl.replaceAll('http://', '').replaceAll('/', ''),
              style: const TextStyle(color: AppColors.textMuted, fontSize: 12),
            ),
          ),
          const SizedBox(width: 8),

          // Tombol Hubungkan & Chat Admin dengan Notifikasi Badge
          Stack(
            clipBehavior: Clip.none,
            children: [
              ElevatedButton.icon(
                onPressed: () {
                  final user = authVm.user ??
                      const UserModel(
                        id: 3,
                        username: 'kasir',
                        namaLengkap: 'Petugas Kasir 1',
                        role: 'kasir',
                      );
                  _openChatDialog(user);
                },
                icon: const Icon(Icons.chat_bubble_outline, size: 15),
                label: const Text(
                  'Chat Admin',
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                ),
                style: ElevatedButton.styleFrom(
                  backgroundColor: _unreadChatCount > 0 ? const Color(0xFF0369A1) : const Color(0xFF0284C7),
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                  elevation: _unreadChatCount > 0 ? 3 : 0,
                ),
              ),
              if (_unreadChatCount > 0)
                Positioned(
                  top: -6,
                  right: -6,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                    decoration: BoxDecoration(
                      color: const Color(0xFFEF4444),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: const Color(0xFF0F172A), width: 1.5),
                    ),
                    child: Text(
                      '$_unreadChatCount',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 10,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(width: 8),

          // Status Kasir Aktif
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
            decoration: BoxDecoration(
              color: const Color(0xFF064E3B).withValues(alpha: 0.6),
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: const Color(0xFF059669)),
            ),
            child: const Row(
              children: [
                Icon(Icons.circle, size: 9, color: Color(0xFF10B981)),
                SizedBox(width: 6),
                Text(
                  'KASIR AKTIF',
                  style: TextStyle(color: Color(0xFF34D399), fontSize: 11, fontWeight: FontWeight.bold, letterSpacing: 0.5),
                ),
              ],
            ),
          ),
          const SizedBox(width: 6),

          // Tombol Keluar (Logout Kasir)
          IconButton(
            icon: const Icon(Icons.power_settings_new, color: Color(0xFFF87171), size: 20),
            tooltip: 'Logout Kasir',
            onPressed: () => _handleLogoutAttempt(context, posVm, authVm),
          ),
          const SizedBox(width: 10),
        ],
      ),
      body: posVm.isLoading
          ? const Center(child: CircularProgressIndicator(color: AppColors.primaryLight))
          : Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // 1. SEBELAH KIRI: KATALOG PRODUK (65% LEBAR)
                Expanded(
                  flex: 65,
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        // Baris Pencarian & Refresh
                        Row(
                          children: [
                            Expanded(
                              child: TextField(
                                controller: _searchController,
                                style: const TextStyle(color: Colors.white, fontSize: 13),
                                onChanged: posVm.setSearchQuery,
                                decoration: InputDecoration(
                                  filled: true,
                                  fillColor: AppColors.surface,
                                  isDense: true,
                                  contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                                  prefixIcon: const Icon(Icons.search, color: AppColors.textMuted, size: 18),
                                  suffixIcon: _searchController.text.isNotEmpty
                                      ? IconButton(
                                          icon: const Icon(Icons.clear, size: 16, color: AppColors.textMuted),
                                          onPressed: () {
                                            _searchController.clear();
                                            posVm.setSearchQuery('');
                                          },
                                        )
                                      : null,
                                  hintText: 'Cari nama barang atau barcode...',
                                  hintStyle: const TextStyle(color: AppColors.textMuted, fontSize: 13),
                                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: AppColors.border)),
                                  enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: AppColors.border)),
                                ),
                              ),
                            ),
                            const SizedBox(width: 8),
                            IconButton(
                              icon: const Icon(Icons.refresh, color: AppColors.primaryLight),
                              tooltip: 'Refresh Stok Barang',
                              onPressed: posVm.fetchInitialData,
                            ),
                          ],
                        ),
                        const SizedBox(height: 10),

                        // Filter Kategori (Horizontal Pills)
                        SingleChildScrollView(
                          scrollDirection: Axis.horizontal,
                          child: Row(
                            children: [
                              _buildCategoryPill(
                                label: 'Semua',
                                isSelected: posVm.selectedCategoryId == 0,
                                onTap: () => posVm.setCategory(0),
                              ),
                              ...posVm.categories.map((kat) {
                                final id = kat['id'] as int;
                                final name = kat['nama_kategori'] as String;
                                return _buildCategoryPill(
                                  label: name,
                                  isSelected: posVm.selectedCategoryId == id,
                                  onTap: () => posVm.setCategory(id),
                                );
                              }),
                            ],
                          ),
                        ),
                        const SizedBox(height: 12),

                        // Grid Produk
                        Expanded(
                          child: posVm.products.isEmpty
                              ? const Center(
                                  child: Text('Tidak ada barang ditemukan.', style: TextStyle(color: AppColors.textMuted)),
                                )
                              : GridView.builder(
                                  itemCount: posVm.products.length,
                                  gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                                    maxCrossAxisExtent: 180,
                                    mainAxisExtent: 195,
                                    crossAxisSpacing: 10,
                                    mainAxisSpacing: 10,
                                  ),
                                  itemBuilder: (context, index) {
                                    final prod = posVm.products[index];
                                    return _buildProductCard(prod, posVm);
                                  },
                                ),
                        ),
                      ],
                    ),
                  ),
                ),

                // 2. SEBELAH KANAN: PANEL KERANJANG (35% LEBAR)
                Expanded(
                  flex: 35,
                  child: Container(
                    margin: const EdgeInsets.only(top: 12, right: 12, bottom: 12),
                    decoration: BoxDecoration(
                      color: AppColors.surface,
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: AppColors.border),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        // Header Pesanan & Reset
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                          decoration: const BoxDecoration(
                            border: Border(bottom: BorderSide(color: AppColors.border)),
                          ),
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Expanded(
                                child: SingleChildScrollView(
                                  scrollDirection: Axis.horizontal,
                                  child: Row(
                                    children: [
                                      const Icon(Icons.shopping_cart, color: AppColors.primaryLight, size: 18),
                                      const SizedBox(width: 8),
                                      const Text('Pesanan', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14)),
                                      const SizedBox(width: 6),
                                      Text(
                                        'INV-${DateTime.now().year.toString().substring(2)}${DateTime.now().month.toString().padLeft(2, '0')}${DateTime.now().day.toString().padLeft(2, '0')}',
                                        style: const TextStyle(color: AppColors.textMuted, fontSize: 10, fontFamily: 'monospace'),
                                      ),
                                      const SizedBox(width: 6),
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                        decoration: BoxDecoration(color: AppColors.surfaceVariant, borderRadius: BorderRadius.circular(4)),
                                        child: Text('${posVm.totalItemsCount} item', style: const TextStyle(color: AppColors.primaryLight, fontSize: 11, fontWeight: FontWeight.bold)),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                              TextButton.icon(
                                onPressed: posVm.cart.isEmpty
                                    ? null
                                    : () {
                                        posVm.clearCart();
                                        _cashController.clear();
                                      },
                                icon: const Icon(Icons.delete_outline, size: 16, color: AppColors.accentRed),
                                label: const Text('Reset', style: TextStyle(color: AppColors.accentRed, fontSize: 12)),
                                style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: Size.zero),
                              ),
                            ],
                          ),
                        ),

                        // Dropdown Tipe Pelanggan (Umum, Siswa, Guru)
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 10),
                            decoration: BoxDecoration(
                              color: AppColors.background,
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(color: AppColors.border),
                            ),
                            child: DropdownButtonHideUnderline(
                              child: DropdownButton<String>(
                                value: posVm.selectedCustomer,
                                dropdownColor: AppColors.surface,
                                style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold),
                                isExpanded: true,
                                icon: const Icon(Icons.arrow_drop_down, color: AppColors.primaryLight),
                                items: const [
                                  DropdownMenuItem(value: 'Umum', child: Text('Pelanggan: Umum')),
                                  DropdownMenuItem(value: 'Siswa', child: Text('Pelanggan: Siswa')),
                                  DropdownMenuItem(value: 'Guru', child: Text('Pelanggan: Guru')),
                                ],
                                onChanged: (val) {
                                  if (val != null) posVm.setCustomer(val);
                                },
                              ),
                            ),
                          ),
                        ),

                        // Daftar Barang Keranjang
                        Expanded(
                          child: posVm.cart.isEmpty
                              ? const Center(
                                  child: SingleChildScrollView(
                                    child: Column(
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        Icon(Icons.remove_shopping_cart, size: 36, color: AppColors.textMuted),
                                        SizedBox(height: 6),
                                        Text('Keranjang masih kosong', style: TextStyle(color: AppColors.textMuted, fontSize: 12)),
                                      ],
                                    ),
                                  ),
                                )
                              : ListView.separated(
                                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                                  itemCount: posVm.cart.length,
                                  separatorBuilder: (context, index) => const Divider(color: AppColors.border, height: 8),
                                  itemBuilder: (context, index) {
                                    final item = posVm.cart[index];
                                    return Row(
                                      children: [
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment: CrossAxisAlignment.start,
                                            children: [
                                              Text(
                                                item.product.namaProduk,
                                                style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold),
                                                maxLines: 1,
                                                overflow: TextOverflow.ellipsis,
                                              ),
                                              Text(
                                                '${CurrencyFormatter.format(item.product.harga)} / ${item.product.kodeSatuan}',
                                                style: const TextStyle(color: AppColors.textMuted, fontSize: 11),
                                              ),
                                            ],
                                          ),
                                        ),
                                        // Kontrol Qty (- / +)
                                        Container(
                                          decoration: BoxDecoration(
                                            color: AppColors.surfaceVariant,
                                            borderRadius: BorderRadius.circular(6),
                                            border: Border.all(color: AppColors.border),
                                          ),
                                          child: Row(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              InkWell(
                                                onTap: () => posVm.updateQty(item.product.id, -1),
                                                child: const Padding(
                                                  padding: EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                                  child: Text('-', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                                                ),
                                              ),
                                              Padding(
                                                padding: const EdgeInsets.symmetric(horizontal: 6),
                                                child: Text('${item.qty}', style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold)),
                                              ),
                                              InkWell(
                                                onTap: () => posVm.updateQty(item.product.id, 1),
                                                child: const Padding(
                                                  padding: EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                                  child: Text('+', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                                                ),
                                              ),
                                            ],
                                          ),
                                        ),
                                        const SizedBox(width: 8),
                                        Text(
                                          CurrencyFormatter.format(item.subtotal),
                                          style: const TextStyle(color: AppColors.accentGreen, fontSize: 12, fontWeight: FontWeight.bold),
                                        ),
                                        IconButton(
                                          icon: const Icon(Icons.close, size: 16, color: AppColors.accentRed),
                                          padding: EdgeInsets.zero,
                                          constraints: const BoxConstraints(),
                                          onPressed: () => posVm.removeFromCart(item.product.id),
                                        ),
                                      ],
                                    );
                                  },
                                ),
                        ),

                        // Panel Total & Pembayaran (Identik dengan Server 172.16.5.11)
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: const BoxDecoration(
                            color: AppColors.surfaceVariant,
                            borderRadius: BorderRadius.vertical(bottom: Radius.circular(14)),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              // 1. Total Display Box
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                                decoration: BoxDecoration(
                                  gradient: const LinearGradient(
                                    colors: [Color(0xFF1E293B), Color(0xFF0F172A)],
                                    begin: Alignment.topLeft,
                                    end: Alignment.bottomRight,
                                  ),
                                  borderRadius: BorderRadius.circular(8),
                                  border: Border.all(color: AppColors.border),
                                ),
                                child: Column(
                                  children: [
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        const Text('Total Item:', style: TextStyle(color: AppColors.textMuted, fontSize: 11)),
                                        Text('${posVm.totalItemsCount} item', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11)),
                                      ],
                                    ),
                                    const Divider(color: AppColors.border, height: 10),
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        const Text('TOTAL :', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13)),
                                        Text(
                                          CurrencyFormatter.format(posVm.grandTotal),
                                          style: const TextStyle(
                                            color: AppColors.primaryLight,
                                            fontWeight: FontWeight.w800,
                                            fontSize: 20,
                                            fontFamily: 'monospace',
                                          ),
                                        ),
                                      ],
                                    ),
                                  ],
                                ),
                              ),
                              const SizedBox(height: 8),

                              // 2. Quick Cash Buttons (2 baris x 3 tombol persis di web kasir)
                              Row(
                                children: [
                                  Expanded(child: _buildQuickCashBtn('Uang Pas', () => _setCashAmount(posVm, posVm.grandTotal))),
                                  const SizedBox(width: 4),
                                  Expanded(child: _buildQuickCashBtn('+10.000', () => _addCashAmount(posVm, 10000))),
                                  const SizedBox(width: 4),
                                  Expanded(child: _buildQuickCashBtn('+20.000', () => _addCashAmount(posVm, 20000))),
                                ],
                              ),
                              const SizedBox(height: 4),
                              Row(
                                children: [
                                  Expanded(child: _buildQuickCashBtn('Rp 50rb', () => _setCashAmount(posVm, 50000))),
                                  const SizedBox(width: 4),
                                  Expanded(child: _buildQuickCashBtn('Rp 100rb', () => _setCashAmount(posVm, 100000))),
                                  const SizedBox(width: 4),
                                  Expanded(child: _buildQuickCashBtn('Rp 200rb', () => _setCashAmount(posVm, 200000))),
                                ],
                              ),
                              const SizedBox(height: 8),

                              // 3. Input Nominal Bayar
                              Container(
                                height: 38,
                                decoration: BoxDecoration(
                                  color: const Color(0xFF0F172A),
                                  borderRadius: BorderRadius.circular(6),
                                  border: Border.all(color: AppColors.border),
                                ),
                                child: Row(
                                  children: [
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 12),
                                      height: double.infinity,
                                      alignment: Alignment.center,
                                      decoration: const BoxDecoration(
                                        color: Color(0xFF1E293B),
                                        borderRadius: BorderRadius.horizontal(left: Radius.circular(5)),
                                        border: Border(right: BorderSide(color: AppColors.border)),
                                      ),
                                      child: const Text(
                                        'Bayar',
                                        style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12),
                                      ),
                                    ),
                                    Expanded(
                                      child: TextField(
                                        controller: _cashController,
                                        keyboardType: TextInputType.number,
                                        textAlign: TextAlign.end,
                                        style: const TextStyle(
                                          color: Colors.white,
                                          fontWeight: FontWeight.bold,
                                          fontSize: 15,
                                          fontFamily: 'monospace',
                                        ),
                                        decoration: const InputDecoration(
                                          hintText: '0',
                                          hintStyle: TextStyle(color: AppColors.textMuted, fontWeight: FontWeight.normal),
                                          border: InputBorder.none,
                                          contentPadding: EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                                          isDense: true,
                                        ),
                                        onChanged: (val) {
                                          final cleaned = val.replaceAll(RegExp(r'[^0-9]'), '');
                                          final numVal = double.tryParse(cleaned) ?? 0;
                                          posVm.setCash(numVal);
                                        },
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              const SizedBox(height: 6),

                              // 4. Output Kembalian / Uang Kurang Otomatis
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                                decoration: BoxDecoration(
                                  color: const Color(0xFF0F172A),
                                  borderRadius: BorderRadius.circular(6),
                                  border: Border.all(
                                    color: posVm.cart.isEmpty
                                        ? AppColors.border
                                        : (posVm.isPaymentValid
                                            ? AppColors.accentGreen
                                            : AppColors.accentRed),
                                    width: 1.2,
                                  ),
                                ),
                                child: Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    Text(
                                      posVm.cart.isEmpty
                                          ? 'Kembalian:'
                                          : (posVm.isPaymentValid ? 'Kembalian:' : 'Uang Kurang:'),
                                      style: TextStyle(
                                        color: posVm.cart.isEmpty
                                            ? AppColors.textMuted
                                            : (posVm.isPaymentValid
                                                ? AppColors.accentGreen
                                                : AppColors.accentRed),
                                        fontSize: 12,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                    Text(
                                      posVm.cart.isEmpty
                                          ? 'Rp 0'
                                          : (posVm.isPaymentValid
                                              ? CurrencyFormatter.format(posVm.changeAmount)
                                              : '- ${CurrencyFormatter.format(posVm.deficitAmount)}'),
                                      style: TextStyle(
                                        color: posVm.cart.isEmpty
                                            ? AppColors.textMuted
                                            : (posVm.isPaymentValid
                                                ? AppColors.accentGreen
                                                : AppColors.accentRed),
                                        fontWeight: FontWeight.bold,
                                        fontSize: 13,
                                        fontFamily: 'monospace',
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              const SizedBox(height: 8),

                              // 5. Tombol Selesaikan Transaksi (BAYAR & CETAK F9)
                              ElevatedButton.icon(
                                onPressed: posVm.isPaymentValid ? _onCheckout : null,
                                icon: const Icon(Icons.print, size: 18),
                                label: const Text('BAYAR & CETAK (F9)', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: AppColors.accentGreen,
                                  foregroundColor: Colors.white,
                                  disabledBackgroundColor: AppColors.border,
                                  disabledForegroundColor: AppColors.textMuted,
                                  padding: const EdgeInsets.symmetric(vertical: 11),
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
                                  elevation: posVm.isPaymentValid ? 3 : 0,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildCategoryPill({required String label, required bool isSelected, required VoidCallback onTap}) {
    return Padding(
      padding: const EdgeInsets.only(right: 6),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
          decoration: BoxDecoration(
            color: isSelected ? AppColors.primary : AppColors.surface,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: isSelected ? AppColors.primaryLight : AppColors.border),
          ),
          child: Text(
            label,
            style: TextStyle(
              color: isSelected ? Colors.white : AppColors.textMuted,
              fontSize: 12,
              fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildProductCard(ProductModel prod, PosViewModel posVm) {
    return InkWell(
      onTap: prod.isOutOfStock ? null : () => posVm.addToCart(prod),
      borderRadius: BorderRadius.circular(12),
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppColors.border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Foto Produk
            Expanded(
              child: Stack(
                fit: StackFit.expand,
                children: [
                  ClipRRect(
                    borderRadius: const BorderRadius.vertical(top: Radius.circular(11)),
                    child: prod.fotoUrl.isNotEmpty
                        ? Image.network(
                            prod.fotoUrl,
                            fit: BoxFit.cover,
                            errorBuilder: (context, error, stackTrace) => Container(
                              color: AppColors.surfaceVariant,
                              child: const Icon(Icons.image_not_supported, color: AppColors.textMuted),
                            ),
                          )
                        : Container(color: AppColors.surfaceVariant, child: const Icon(Icons.inventory_2, color: AppColors.textMuted)),
                  ),
                  Positioned(
                    top: 6,
                    right: 6,
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(
                        color: prod.isOutOfStock ? AppColors.accentRed : AppColors.surface.withValues(alpha: 0.85),
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Text(
                        prod.isOutOfStock ? 'HABIS' : '${prod.stok} ${prod.kodeSatuan}',
                        style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            // Teks Info Barang
            Padding(
              padding: const EdgeInsets.all(8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    prod.namaProduk,
                    style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 4),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        CurrencyFormatter.format(prod.harga),
                        style: const TextStyle(color: AppColors.primaryLight, fontSize: 12, fontWeight: FontWeight.bold),
                      ),
                      Container(
                        width: 22,
                        height: 22,
                        decoration: BoxDecoration(color: AppColors.primary, borderRadius: BorderRadius.circular(11)),
                        child: const Icon(Icons.add, size: 14, color: Colors.white),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildQuickCashBtn(String label, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(6),
      child: Container(
        height: 28,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: const Color(0xFF1E293B),
          borderRadius: BorderRadius.circular(6),
          border: Border.all(color: AppColors.border),
        ),
        child: Text(
          label,
          style: const TextStyle(
            color: Color(0xFFCBD5E1),
            fontSize: 11,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
    );
  }
}
