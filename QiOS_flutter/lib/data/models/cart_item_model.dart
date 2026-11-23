import 'product_model.dart';

class CartItemModel {
  final ProductModel product;
  int qty;

  CartItemModel({
    required this.product,
    this.qty = 1,
  });

  double get subtotal => product.harga * qty;

  Map<String, dynamic> toApiItem() {
    return {
      'id': product.id,
      'qty': qty,
    };
  }
}
