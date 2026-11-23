import 'package:flutter/material.dart';
import '../../../data/models/cart_item_model.dart';
import '../../../data/models/product_model.dart';
import '../../../data/services/api_service.dart';

class PosViewModel extends ChangeNotifier {
  final ApiService _apiService = ApiService();

  List<ProductModel> _allProducts = [];
  List<ProductModel> _filteredProducts = [];
  List<Map<String, dynamic>> _categories = [];
  final List<CartItemModel> _cart = [];

  bool _isLoading = false;
  String? _errorMessage;

  String _searchQuery = '';
  int _selectedCategoryId = 0; // 0 = Semua Kategori
  String _selectedCustomer = 'Umum'; // Umum, Siswa, Guru
  double _cashGiven = 0;

  List<ProductModel> get products => _filteredProducts;
  List<Map<String, dynamic>> get categories => _categories;
  List<CartItemModel> get cart => _cart;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;
  int get selectedCategoryId => _selectedCategoryId;
  String get selectedCustomer => _selectedCustomer;
  double get cashGiven => _cashGiven;

  double get grandTotal => _cart.fold(0.0, (sum, item) => sum + item.subtotal);
  int get totalItemsCount => _cart.fold(0, (sum, item) => sum + item.qty);
  double get changeAmount => cashGiven >= grandTotal ? (cashGiven - grandTotal) : 0;
  double get deficitAmount => grandTotal > cashGiven ? (grandTotal - cashGiven) : 0;
  bool get isPaymentValid => _cart.isNotEmpty && cashGiven >= grandTotal;

  // 1. Muat Produk & Kategori Awal
  Future<void> fetchInitialData() async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      final results = await Future.wait([
        _apiService.getProducts(),
        _apiService.getCategories(),
      ]);

      _allProducts = results[0] as List<ProductModel>;
      _categories = results[1] as List<Map<String, dynamic>>;
      _applyFilter();
      _isLoading = false;
      notifyListeners();
    } catch (e) {
      _errorMessage = e.toString().replaceAll('Exception: ', '');
      _isLoading = false;
      notifyListeners();
    }
  }

  // 2. Filter Kategori & Pencarian
  void setCategory(int katId) {
    _selectedCategoryId = katId;
    _applyFilter();
    notifyListeners();
  }

  void setSearchQuery(String query) {
    _searchQuery = query.toLowerCase().trim();
    _applyFilter();
    notifyListeners();
  }

  void _applyFilter() {
    _filteredProducts = _allProducts.where((prod) {
      final matchesSearch = _searchQuery.isEmpty ||
          prod.namaProduk.toLowerCase().contains(_searchQuery) ||
          (prod.barcode != null && prod.barcode!.toLowerCase().contains(_searchQuery)) ||
          prod.deskripsi.toLowerCase().contains(_searchQuery);

      final matchesCategory = _selectedCategoryId == 0 || prod.kategoriId == _selectedCategoryId;

      return matchesSearch && matchesCategory;
    }).toList();
  }

  // 3. Manajemen Keranjang Belanja
  void addToCart(ProductModel product) {
    if (product.isOutOfStock) return;

    final existingIndex = _cart.indexWhere((item) => item.product.id == product.id);
    if (existingIndex >= 0) {
      if (_cart[existingIndex].qty < product.stok) {
        _cart[existingIndex].qty++;
      }
    } else {
      _cart.add(CartItemModel(product: product, qty: 1));
    }

    notifyListeners();
  }

  void updateQty(int productId, int delta) {
    final index = _cart.indexWhere((item) => item.product.id == productId);
    if (index < 0) return;

    final item = _cart[index];
    final newQty = item.qty + delta;

    if (newQty <= 0) {
      _cart.removeAt(index);
    } else if (newQty <= item.product.stok) {
      item.qty = newQty;
    }

    notifyListeners();
  }

  void removeFromCart(int productId) {
    _cart.removeWhere((item) => item.product.id == productId);
    notifyListeners();
  }

  void clearCart() {
    _cart.clear();
    _cashGiven = 0;
    _selectedCustomer = 'Umum';
    notifyListeners();
  }

  // 4. Pembayaran & Pelanggan
  void setCustomer(String customer) {
    _selectedCustomer = customer;
    notifyListeners();
  }

  void setCash(double amount) {
    _cashGiven = amount;
    notifyListeners();
  }

  void addCash(double amount) {
    _cashGiven += amount;
    notifyListeners();
  }

  // 5. Checkout ke Server
  Future<Map<String, dynamic>> submitCheckout(int userId) async {
    if (!isPaymentValid) {
      throw Exception('Uang pembayaran tidak mencukupi.');
    }

    final apiItems = _cart.map((item) => item.toApiItem()).toList();
    final result = await _apiService.checkout(
      userId: userId,
      namaPembeli: _selectedCustomer,
      uangBayar: _cashGiven,
      items: apiItems,
    );

    // Refresh stok produk setelah checkout
    await fetchInitialData();
    clearCart();

    return result;
  }
}
