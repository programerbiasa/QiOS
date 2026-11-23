import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:kiosk_cendekia/ui/features/auth/auth_view_model.dart';
import 'package:kiosk_cendekia/ui/core/app_colors.dart';

class ServerSettingsDialog extends StatefulWidget {
  const ServerSettingsDialog({super.key});

  @override
  State<ServerSettingsDialog> createState() => _ServerSettingsDialogState();
}

class _ServerSettingsDialogState extends State<ServerSettingsDialog> {
  late TextEditingController _controller;
  bool _isTesting = false;
  String? _testResult;
  bool _isSuccess = false;

  @override
  void initState() {
    super.initState();
    final authVm = context.read<AuthViewModel>();
    _controller = TextEditingController(text: authVm.currentServerUrl);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _testConnection() async {
    setState(() {
      _isTesting = true;
      _testResult = 'Menguji koneksi ke ${_controller.text.trim()}...';
      _isSuccess = false;
    });

    final authVm = context.read<AuthViewModel>();
    final ok = await authVm.testServer(_controller.text.trim());

    if (mounted) {
      setState(() {
        _isTesting = false;
        _isSuccess = ok;
        _testResult = ok
            ? '✓ Berhasil! Server merespon aktif.'
            : '✗ Gagal terhubung ke server. Periksa IP dan WiFi.';
      });
    }
  }

  Future<void> _saveAndClose() async {
    final authVm = context.read<AuthViewModel>();
    await authVm.updateServerUrl(_controller.text.trim());
    if (mounted) {
      Navigator.pop(context);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Alamat server berhasil disimpan!'),
          backgroundColor: AppColors.accentGreen,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: AppColors.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: const BorderSide(color: AppColors.border),
      ),
      title: const Row(
        children: [
          Icon(Icons.settings, color: AppColors.primaryLight),
          SizedBox(width: 8),
          Text('Pengaturan IP Server', style: TextStyle(color: AppColors.textWhite, fontSize: 18, fontWeight: FontWeight.bold)),
        ],
      ),
      content: SizedBox(
        width: 420,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Masukkan alamat IP host server Raspberry Pi Kios:',
              style: TextStyle(color: AppColors.textMuted, fontSize: 13),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _controller,
              style: const TextStyle(color: AppColors.textWhite, fontSize: 14),
              decoration: InputDecoration(
                filled: true,
                fillColor: AppColors.background,
                prefixIcon: const Icon(Icons.dns, color: AppColors.primaryLight, size: 20),
                hintText: 'http://172.16.5.11/',
                hintStyle: const TextStyle(color: AppColors.textMuted),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: const BorderSide(color: AppColors.border),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: const BorderSide(color: AppColors.border),
                ),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: const BorderSide(color: AppColors.primaryLight),
                ),
              ),
            ),
            const SizedBox(height: 12),
            if (_testResult != null)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: _isSuccess ? AppColors.accentGreen.withValues(alpha: 0.15) : AppColors.accentRed.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: _isSuccess ? AppColors.accentGreen : AppColors.accentRed, width: 0.5),
                ),
                child: Row(
                  children: [
                    if (_isTesting)
                      const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.primaryLight),
                      )
                    else
                      Icon(_isSuccess ? Icons.check_circle : Icons.error, color: _isSuccess ? AppColors.accentGreen : AppColors.accentRed, size: 18),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _testResult!,
                        style: TextStyle(color: _isSuccess ? AppColors.accentGreen : AppColors.accentRed, fontSize: 12),
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: _isTesting ? null : _testConnection,
          child: const Text('Tes Koneksi', style: TextStyle(color: AppColors.primaryLight)),
        ),
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Batal', style: TextStyle(color: AppColors.textMuted)),
        ),
        ElevatedButton(
          onPressed: _saveAndClose,
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.primary,
            foregroundColor: AppColors.textWhite,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          ),
          child: const Text('Simpan & Terapkan'),
        ),
      ],
    );
  }
}
