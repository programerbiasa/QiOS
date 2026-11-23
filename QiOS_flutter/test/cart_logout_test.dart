import 'package:flutter_test/flutter_test.dart';
import 'package:kiosk_cendekia/data/models/product_model.dart';
import 'package:kiosk_cendekia/ui/features/pos/pos_view_model.dart';

void main() {
  group('POS Cart & Logout Validation Test', () {
    test('Cart detects unfinished transaction when items are added', () {
      final posVm = PosViewModel();
      expect(posVm.cart.isEmpty, isTrue);

      const testProduct = ProductModel(
        id: 1,
        kategoriId: 1,
        namaKategori: 'Minuman',
        satuanId: 1,
        namaSatuan: 'Botol',
        kodeSatuan: 'Btl',
        namaProduk: 'Air Mineral 600ml',
        harga: 4000,
        stok: 10,
        deskripsi: 'Air mineral segar',
        fotoUrl: '',
      );

      posVm.addToCart(testProduct);
      expect(posVm.cart.isNotEmpty, isTrue);
      expect(posVm.totalItemsCount, 1);
      expect(posVm.grandTotal, 4000.0);

      // Reset / Clear cart saat Yes Logout
      posVm.clearCart();
      expect(posVm.cart.isEmpty, isTrue);
      expect(posVm.totalItemsCount, 0);
    });
  });
}
