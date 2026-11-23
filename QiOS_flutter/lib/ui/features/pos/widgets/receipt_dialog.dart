import 'package:flutter/material.dart';
import 'package:kiosk_cendekia/data/services/thermal_printer_service.dart';
import 'package:kiosk_cendekia/ui/core/app_colors.dart';
import 'package:kiosk_cendekia/ui/core/currency_formatter.dart';

class ReceiptDialog extends StatelessWidget {
  final Map<String, dynamic> data;

  const ReceiptDialog({super.key, required this.data});

  @override
  Widget build(BuildContext context) {
    final kodePesanan = data['kode_pesanan'] ?? '-';
    final waktu = data['waktu'] ?? '-';
    final kasir = data['kasir'] ?? 'Kasir';
    final pelanggan = data['nama_pembeli'] ?? data['pelanggan'] ?? 'Umum';
    final totalHarga = data['total_harga'] ?? 0;
    final uangBayar = data['uang_bayar'] ?? 0;
    final kembalian = data['kembalian'] ?? 0;
    final List items = data['items'] ?? [];

    return AlertDialog(
      backgroundColor: Colors.white,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      contentPadding: const EdgeInsets.all(18),
      content: SizedBox(
        width: 320,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Badge Info Ukuran Kertas
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                margin: const EdgeInsets.only(bottom: 12),
                decoration: BoxDecoration(
                  color: const Color(0xFFF1F5F9),
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: const Color(0xFFCBD5E1)),
                ),
                child: const Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(Icons.receipt_long, size: 14, color: Color(0xFF0284C7)),
                    SizedBox(width: 4),
                    Text(
                      'Format Kertas Thermal 58mm',
                      style: TextStyle(
                        fontSize: 10,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF0369A1),
                      ),
                    ),
                  ],
                ),
              ),

              // Header Toko
              const Center(
                child: Text(
                  'QiOS Cendekia',
                  style: TextStyle(
                    fontFamily: 'monospace',
                    fontWeight: FontWeight.bold,
                    fontSize: 17,
                    color: Colors.black,
                    letterSpacing: 0.5,
                  ),
                ),
              ),
              const Center(
                child: Text(
                  'Smart & Digital POS System',
                  style: TextStyle(fontFamily: 'monospace', fontSize: 11, color: Colors.black54),
                ),
              ),
              Center(
                child: Text(
                  waktu,
                  style: const TextStyle(fontFamily: 'monospace', fontSize: 10, color: Colors.black45),
                ),
              ),
              const SizedBox(height: 4),
              _dashedLine(),
              const SizedBox(height: 4),

              // Info Transaksi
              _rowText('No.TRX:', kodePesanan, isBold: true),
              _rowText('Kasir :', kasir),
              _rowText('Plggn :', pelanggan),
              const SizedBox(height: 4),
              _dashedLine(),
              const SizedBox(height: 6),

              // Daftar Barang (2 Baris per item khusus struk 58mm)
              ...items.map((it) {
                final nama = it['nama_produk'] ?? 'Barang';
                final qty = it['jumlah'] ?? 1;
                final harga = it['harga'] ?? 0;
                final subtotal = it['subtotal'] ?? 0;
                return Padding(
                  padding: const EdgeInsets.only(bottom: 6.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        nama,
                        style: const TextStyle(
                          fontFamily: 'monospace',
                          fontWeight: FontWeight.bold,
                          fontSize: 11,
                          color: Colors.black,
                        ),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            '$qty x ${CurrencyFormatter.format(harga)}',
                            style: const TextStyle(fontFamily: 'monospace', fontSize: 10.5, color: Colors.black54),
                          ),
                          Text(
                            CurrencyFormatter.format(subtotal),
                            style: const TextStyle(
                              fontFamily: 'monospace',
                              fontWeight: FontWeight.bold,
                              fontSize: 11,
                              color: Colors.black,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                );
              }),
              const SizedBox(height: 4),
              _dashedLine(),
              const SizedBox(height: 6),

              // Rincian Pembayaran
              _rowText('TOTAL:', CurrencyFormatter.format(totalHarga), isBold: true, fontSize: 13),
              _rowText('TUNAI:', CurrencyFormatter.format(uangBayar), fontSize: 11),
              _rowText('KEMBALI:', CurrencyFormatter.format(kembalian), isBold: true, fontSize: 12),
              const SizedBox(height: 6),
              _dashedLine(),
              const SizedBox(height: 6),

              // Catatan Kaki
              const Center(
                child: Text(
                  '*** TERIMA KASIH ***\nBarang yang sudah dibeli\ntidak dapat ditukar/dikembalikan',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontFamily: 'monospace', fontSize: 9.5, color: Colors.black54),
                ),
              ),
            ],
          ),
        ),
      ),
      actionsAlignment: MainAxisAlignment.center,
      actionsPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
      actions: [
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Tombol Cetak Struk 58mm
            ElevatedButton.icon(
              onPressed: () async {
                try {
                  await ThermalPrinterService.printReceipt58mm(data);
                } catch (e) {
                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text('Gagal mencetak: $e'),
                        backgroundColor: AppColors.accentRed,
                      ),
                    );
                  }
                }
              },
              icon: const Icon(Icons.print, size: 18),
              label: const Text(
                'Cetak Struk (58mm)',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
              ),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF0F172A),
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 12),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
                elevation: 2,
              ),
            ),
            const SizedBox(height: 8),

            // Tombol Selesai / Transaksi Baru
            OutlinedButton.icon(
              onPressed: () => Navigator.pop(context),
              icon: const Icon(Icons.check, size: 18),
              label: const Text(
                'Selesai / Transaksi Baru',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
              ),
              style: OutlinedButton.styleFrom(
                foregroundColor: AppColors.primary,
                side: const BorderSide(color: AppColors.primary),
                padding: const EdgeInsets.symmetric(vertical: 10),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
              ),
            ),
            const SizedBox(height: 6),

            // Keterangan printer
            const Center(
              child: Text(
                'Ukuran telah disesuaikan untuk printer portable 58mm (PT-210 / POS-58)',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 9, color: Color(0xFF64748B)),
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _rowText(String left, String right, {bool isBold = false, double fontSize = 11}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 1.5),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            left,
            style: TextStyle(fontFamily: 'monospace', fontSize: fontSize, color: Colors.black87),
          ),
          Text(
            right,
            style: TextStyle(
              fontFamily: 'monospace',
              fontWeight: isBold ? FontWeight.bold : FontWeight.normal,
              fontSize: fontSize,
              color: Colors.black,
            ),
          ),
        ],
      ),
    );
  }

  Widget _dashedLine() {
    return const Text(
      '- - - - - - - - - - - - - - - - - - - - - - - - -',
      textAlign: TextAlign.center,
      style: TextStyle(color: Colors.black38, fontSize: 9, fontFamily: 'monospace'),
      maxLines: 1,
    );
  }
}
