class ProductModel {
  final int id;
  final int kategoriId;
  final String namaKategori;
  final int satuanId;
  final String namaSatuan;
  final String kodeSatuan;
  final String namaProduk;
  final String? barcode;
  final double harga;
  final int stok;
  final String deskripsi;
  final String fotoUrl;

  const ProductModel({
    required this.id,
    required this.kategoriId,
    required this.namaKategori,
    required this.satuanId,
    required this.namaSatuan,
    required this.kodeSatuan,
    required this.namaProduk,
    this.barcode,
    required this.harga,
    required this.stok,
    required this.deskripsi,
    required this.fotoUrl,
  });

  factory ProductModel.fromJson(Map<String, dynamic> json) {
    return ProductModel(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      kategoriId: json['kategori_id'] is int ? json['kategori_id'] : int.tryParse(json['kategori_id'].toString()) ?? 0,
      namaKategori: json['nama_kategori'] ?? 'Umum',
      satuanId: json['satuan_id'] is int ? json['satuan_id'] : int.tryParse(json['satuan_id'].toString()) ?? 0,
      namaSatuan: json['nama_satuan'] ?? 'Pcs',
      kodeSatuan: json['kode_satuan'] ?? 'Pcs',
      namaProduk: json['nama_produk'] ?? '',
      barcode: json['barcode']?.toString(),
      harga: json['harga'] is num ? (json['harga'] as num).toDouble() : double.tryParse(json['harga'].toString()) ?? 0.0,
      stok: json['stok'] is int ? json['stok'] : int.tryParse(json['stok'].toString()) ?? 0,
      deskripsi: json['deskripsi'] ?? '',
      fotoUrl: json['foto_url'] ?? '',
    );
  }

  bool get isOutOfStock => stok <= 0;
}
