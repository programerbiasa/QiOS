class UserModel {
  final int id;
  final String username;
  final String namaLengkap;
  final String role;
  final String? token;

  const UserModel({
    required this.id,
    required this.username,
    required this.namaLengkap,
    required this.role,
    this.token,
  });

  factory UserModel.fromJson(Map<String, dynamic> json, {String? token}) {
    return UserModel(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      username: json['username'] ?? '',
      namaLengkap: json['nama_lengkap'] ?? '',
      role: json['role'] ?? 'kasir',
      token: token ?? json['token'],
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'username': username,
      'nama_lengkap': namaLengkap,
      'role': role,
      'token': token,
    };
  }
}
