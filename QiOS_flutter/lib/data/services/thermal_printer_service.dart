import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

class ThermalPrinterService {
  /// Mencetak struk ke printer kasir thermal ukuran 58mm (roll)
  static Future<void> printReceipt58mm(Map<String, dynamic> data) async {
    final kodePesanan = data['kode_pesanan'] ?? '-';
    final waktu = data['waktu'] ?? '-';
    final kasir = data['kasir'] ?? 'Kasir';
    final pelanggan = data['nama_pembeli'] ?? data['pelanggan'] ?? 'Umum';
    final totalHarga = data['total_harga'] ?? 0;
    final uangBayar = data['uang_bayar'] ?? 0;
    final kembalian = data['kembalian'] ?? 0;
    final List items = data['items'] ?? [];

    final doc = pw.Document();

    // Ukuran kertas roll 58mm standar kasir thermal (margin minimal)
    const pageFormat = PdfPageFormat(
      58 * PdfPageFormat.mm,
      double.infinity,
      marginLeft: 2 * PdfPageFormat.mm,
      marginRight: 2 * PdfPageFormat.mm,
      marginTop: 2 * PdfPageFormat.mm,
      marginBottom: 6 * PdfPageFormat.mm,
    );

    doc.addPage(
      pw.Page(
        pageFormat: pageFormat,
        build: (pw.Context context) {
          return pw.Column(
            crossAxisAlignment: pw.CrossAxisAlignment.stretch,
            children: [
              // Header Toko
              pw.Center(
                child: pw.Text(
                  'QiOS Cendekia',
                  style: pw.TextStyle(
                    fontWeight: pw.FontWeight.bold,
                    fontSize: 12,
                  ),
                ),
              ),
              pw.Center(
                child: pw.Text(
                  'Smart & Digital POS System',
                  style: const pw.TextStyle(fontSize: 7.5),
                ),
              ),
              pw.Center(
                child: pw.Text(
                  waktu,
                  style: const pw.TextStyle(fontSize: 7),
                ),
              ),
              pw.SizedBox(height: 2),
              _pwDivider(),
              pw.SizedBox(height: 2),

              // Info Pesanan
              _pwRow('No.TRX:', kodePesanan, isBold: true),
              _pwRow('Kasir :', kasir),
              _pwRow('Plggn :', pelanggan),
              pw.SizedBox(height: 2),
              _pwDivider(),
              pw.SizedBox(height: 2),

              // Daftar Barang (Format 2 baris rapi khusus kertas 58mm)
              ...items.map((it) {
                final nama = it['nama_produk'] ?? 'Barang';
                final qty = it['jumlah'] ?? 1;
                final harga = it['harga'] ?? 0;
                final subtotal = it['subtotal'] ?? 0;
                return pw.Container(
                  margin: const pw.EdgeInsets.only(bottom: 3),
                  child: pw.Column(
                    crossAxisAlignment: pw.CrossAxisAlignment.start,
                    children: [
                      pw.Text(
                        nama,
                        style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 8),
                        maxLines: 2,
                      ),
                      pw.Row(
                        mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                        children: [
                          pw.Text('$qty x ${_formatRupiah(harga)}', style: const pw.TextStyle(fontSize: 7.5)),
                          pw.Text(_formatRupiah(subtotal), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 8)),
                        ],
                      ),
                    ],
                  ),
                );
              }),

              pw.SizedBox(height: 2),
              _pwDivider(),
              pw.SizedBox(height: 2),

              // Rincian Pembayaran
              _pwRow('TOTAL:', _formatRupiah(totalHarga), isBold: true, fontSize: 9.5),
              _pwRow('TUNAI:', _formatRupiah(uangBayar), fontSize: 8),
              _pwRow('KEMBALI:', _formatRupiah(kembalian), isBold: true, fontSize: 8.5),

              pw.SizedBox(height: 3),
              _pwDivider(),
              pw.SizedBox(height: 4),

              // Footer Struk
              pw.Center(
                child: pw.Text(
                  '*** TERIMA KASIH ***',
                  style: pw.TextStyle(fontSize: 7.5, fontWeight: pw.FontWeight.bold),
                ),
              ),
              pw.Center(
                child: pw.Text(
                  'Barang yang sudah dibeli\ntidak dapat ditukar/dikembalikan',
                  textAlign: pw.TextAlign.center,
                  style: const pw.TextStyle(fontSize: 6.5),
                ),
              ),
              // Feed spacer agar tulisan terbawah tidak terpotong pisau gerigi printer thermal
              pw.SizedBox(height: 18),
            ],
          );
        },
      ),
    );

    await Printing.layoutPdf(
      name: 'Struk_$kodePesanan',
      format: pageFormat,
      onLayout: (PdfPageFormat format) async => doc.save(),
    );
  }

  static pw.Widget _pwRow(String left, String right, {bool isBold = false, double fontSize = 7.5}) {
    return pw.Padding(
      padding: const pw.EdgeInsets.symmetric(vertical: 0.8),
      child: pw.Row(
        mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
        children: [
          pw.Text(left, style: pw.TextStyle(fontSize: fontSize)),
          pw.Text(
            right,
            style: pw.TextStyle(
              fontSize: fontSize,
              fontWeight: isBold ? pw.FontWeight.bold : pw.FontWeight.normal,
            ),
          ),
        ],
      ),
    );
  }

  static pw.Widget _pwDivider() {
    return pw.Text(
      '------------------------------------------------',
      textAlign: pw.TextAlign.center,
      style: const pw.TextStyle(fontSize: 6),
      maxLines: 1,
    );
  }

  static String _formatRupiah(dynamic amount) {
    final num val = (amount is num) ? amount : (num.tryParse(amount.toString()) ?? 0);
    final str = val.toInt().toString().replaceAllMapped(
      RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'),
      (Match m) => '${m[1]}.',
    );
    return 'Rp $str';
  }
}
