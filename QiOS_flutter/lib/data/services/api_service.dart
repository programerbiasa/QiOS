import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../models/chat_message_model.dart';
import '../models/product_model.dart';
import '../models/user_model.dart';

class AlreadyActiveException implements Exception {
  final String message;
  final String activeDevice;

  const AlreadyActiveException(this.message, {this.activeDevice = 'Komputer Kasir Lain'});

  @override
  String toString() => message;
}

class ApiService {
  static const String defaultServerUrl = 'http://172.16.5.11/';
  static const String keyServerUrl = 'kios_server_url';

  Future<String> getBaseUrl() async {
    final prefs = await SharedPreferences.getInstance();
    String url = prefs.getString(keyServerUrl) ?? defaultServerUrl;
    if (!url.endsWith('/')) {
      url += '/';
    }
    return url;
  }

  Future<void> saveBaseUrl(String url) async {
    final prefs = await SharedPreferences.getInstance();
    String cleanUrl = url.trim();
    if (!cleanUrl.startsWith('http://') && !cleanUrl.startsWith('https://')) {
      cleanUrl = 'http://$cleanUrl';
    }
    if (!cleanUrl.endsWith('/')) {
      cleanUrl += '/';
    }
    await prefs.setString(keyServerUrl, cleanUrl);
  }

  // 1. Tes Koneksi ke Server IP
  Future<bool> testConnection(String targetUrl) async {
    try {
      String testEndpoint = targetUrl.trim();
      if (!testEndpoint.startsWith('http://') && !testEndpoint.startsWith('https://')) {
        testEndpoint = 'http://$testEndpoint';
      }
      if (!testEndpoint.endsWith('/')) {
        testEndpoint += '/';
      }
      testEndpoint += 'api/index.php';

      final response = await http.get(Uri.parse(testEndpoint)).timeout(
        const Duration(seconds: 4),
      );
      return response.statusCode == 200;
    } catch (_) {
      return false;
    }
  }

  // 2. Login Petugas
  Future<UserModel> login(
    String username,
    String password, {
    bool forceLogin = false,
    String deviceName = 'Komputer Kasir Windows',
  }) async {
    final baseUrl = await getBaseUrl();
    final uri = Uri.parse('${baseUrl}api/auth.php');

    try {
      final response = await http
          .post(
            uri,
            headers: {'Content-Type': 'application/json'},
            body: jsonEncode({
              'username': username,
              'password': password,
              'device_name': deviceName,
              'force_login': forceLogin,
            }),
          )
          .timeout(const Duration(seconds: 6));

      final json = jsonDecode(response.body);
      if (response.statusCode == 200 && json['success'] == true) {
        final userData = json['data']['user'];
        final token = json['data']['token'];
        return UserModel.fromJson(userData, token: token);
      } else if (response.statusCode == 409 && json['data']?['is_already_active'] == true) {
        throw AlreadyActiveException(
          json['message'] ?? 'Akun sedang aktif di komputer lain.',
          activeDevice: json['data']?['active_device_name'] ?? 'Komputer Kasir Lain',
        );
      } else {
        throw Exception(json['message'] ?? 'Login gagal.');
      }
    } catch (e) {
      if (e is http.ClientException) {
        throw Exception('Tidak dapat terhubung ke server ($baseUrl). Periksa jaringan WiFi.');
      }
      rethrow;
    }
  }

  // Logout sesi aktif di server
  Future<void> logoutServer(int userId, String? token) async {
    if (userId <= 0) return;
    try {
      final baseUrl = await getBaseUrl();
      final uri = Uri.parse('${baseUrl}api/auth.php');
      await http.post(
        uri,
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'action': 'logout',
          'user_id': userId,
          'token': token ?? '',
        }),
      ).timeout(const Duration(seconds: 4));
    } catch (_) {}
  }

  // Heartbeat cek apakah sesi masih valid atau telah diambil alih komputer lain
  Future<bool> checkHeartbeat(int userId, String? token) async {
    if (userId <= 0 || token == null || token.isEmpty) return true;
    try {
      final baseUrl = await getBaseUrl();
      final uri = Uri.parse('${baseUrl}api/auth.php');
      final response = await http.post(
        uri,
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'action': 'heartbeat',
          'user_id': userId,
          'token': token,
        }),
      ).timeout(const Duration(seconds: 4));

      final json = jsonDecode(response.body);
      return json['data']?['is_valid'] ?? true;
    } catch (_) {
      return true;
    }
  }

  // 3. Ambil Daftar Produk (61 Barang)
  Future<List<ProductModel>> getProducts({String? search, int? katId}) async {
    final baseUrl = await getBaseUrl();
    final queryParams = <String, String>{};
    if (search != null && search.isNotEmpty) {
      queryParams['search'] = search;
    }
    if (katId != null && katId > 0) {
      queryParams['kat_id'] = katId.toString();
    }

    final uri = Uri.parse('${baseUrl}api/produk.php').replace(queryParameters: queryParams);

    try {
      final response = await http.get(uri).timeout(const Duration(seconds: 8));
      final json = jsonDecode(response.body);

      if (response.statusCode == 200 && json['success'] == true) {
        final List items = json['data']['items'] ?? [];
        return items.map((item) => ProductModel.fromJson(item)).toList();
      } else {
        throw Exception(json['message'] ?? 'Gagal memuat produk.');
      }
    } catch (e) {
      throw Exception('Gagal memuat produk: $e');
    }
  }

  // 4. Ambil Daftar Kategori
  Future<List<Map<String, dynamic>>> getCategories() async {
    final baseUrl = await getBaseUrl();
    final uri = Uri.parse('${baseUrl}api/kategori.php');

    try {
      final response = await http.get(uri).timeout(const Duration(seconds: 5));
      final json = jsonDecode(response.body);

      if (response.statusCode == 200 && json['success'] == true) {
        final List list = json['data'] ?? [];
        return list.map((e) => Map<String, dynamic>.from(e)).toList();
      }
      return [];
    } catch (_) {
      return [];
    }
  }

  // 5. Proses Transaksi Pembayaran (Checkout)
  Future<Map<String, dynamic>> checkout({
    required int userId,
    required String namaPembeli,
    required double uangBayar,
    required List<Map<String, dynamic>> items,
  }) async {
    final baseUrl = await getBaseUrl();
    final uri = Uri.parse('${baseUrl}api/transaksi.php');

    try {
      final response = await http
          .post(
            uri,
            headers: {'Content-Type': 'application/json'},
            body: jsonEncode({
              'user_id': userId,
              'nama_pembeli': namaPembeli,
              'uang_bayar': uangBayar,
              'items': items,
            }),
          )
          .timeout(const Duration(seconds: 10));

      final json = jsonDecode(response.body);
      if (response.statusCode == 200 || response.statusCode == 201) {
        if (json['success'] == true) {
          return json['data'];
        }
      }
      throw Exception(json['message'] ?? 'Transaksi ditolak oleh server.');
    } catch (e) {
      rethrow;
    }
  }

  // 6. Riwayat Pesan Chat
  Future<Map<String, dynamic>> getChatHistory({required int userId}) async {
    final baseUrl = await getBaseUrl();
    final uri = Uri.parse('${baseUrl}api/chat.php?action=history&user_id=$userId');

    try {
      final response = await http.get(uri).timeout(const Duration(seconds: 6));
      final json = jsonDecode(response.body);

      if (response.statusCode == 200 && json['status'] == 'success') {
        final List msgList = json['messages'] ?? [];
        final messages = msgList.map((m) => ChatMessageModel.fromJson(m)).toList();
        return {
          'messages': messages,
          'online_users': json['online_users'] ?? [],
        };
      }
      return {'messages': <ChatMessageModel>[], 'online_users': []};
    } catch (_) {
      return {'messages': <ChatMessageModel>[], 'online_users': []};
    }
  }

  // 7. Polling Pesan Chat Baru (dengan informasi max_id)
  Future<Map<String, dynamic>> pollChatMessagesResult({
    required int lastId,
    required int userId,
  }) async {
    final baseUrl = await getBaseUrl();
    final uri = Uri.parse('${baseUrl}api/chat.php?action=poll&last_id=$lastId&user_id=$userId');

    try {
      final response = await http.get(uri).timeout(const Duration(seconds: 5));
      final json = jsonDecode(response.body);

      if (response.statusCode == 200 && json['status'] == 'success') {
        final List msgList = json['messages'] ?? [];
        final messages = msgList.map((m) => ChatMessageModel.fromJson(m)).toList();
        final int maxId = json['max_id'] is int ? json['max_id'] : (int.tryParse('${json['max_id']}') ?? 0);
        return {
          'messages': messages,
          'max_id': maxId,
          'online_users': json['online_users'] ?? [],
        };
      }
      return {'messages': <ChatMessageModel>[], 'max_id': lastId};
    } catch (_) {
      return {'messages': <ChatMessageModel>[], 'max_id': lastId};
    }
  }

  Future<List<ChatMessageModel>> pollChatMessages({
    required int lastId,
    required int userId,
  }) async {
    final res = await pollChatMessagesResult(lastId: lastId, userId: userId);
    return res['messages'] as List<ChatMessageModel>;
  }

  // 8. Kirim Pesan Chat
  Future<bool> sendChatMessage({
    required int userId,
    required String message,
  }) async {
    final baseUrl = await getBaseUrl();
    final uri = Uri.parse('${baseUrl}api/chat.php');

    try {
      final response = await http
          .post(
            uri,
            headers: {'Content-Type': 'application/json'},
            body: jsonEncode({
              'action': 'send',
              'user_id': userId,
              'message': message,
            }),
          )
          .timeout(const Duration(seconds: 6));

      final json = jsonDecode(response.body);
      return response.statusCode == 200 && json['status'] == 'success';
    } catch (_) {
      return false;
    }
  }
// 9. Bersihkan Riwayat Chat
  Future<bool> clearChat({required int userId}) async {
    final baseUrl = await getBaseUrl();
    final uri = Uri.parse('${baseUrl}api/chat.php');

    try {
      final response = await http
          .post(
            uri,
            headers: {'Content-Type': 'application/json'},
            body: jsonEncode({
              'action': 'clear',
              'user_id': userId,
            }),
          )
          .timeout(const Duration(seconds: 6));

      final json = jsonDecode(response.body);
      return response.statusCode == 200 && json['status'] == 'success';
    } catch (_) {
      return false;
    }
  }
}
