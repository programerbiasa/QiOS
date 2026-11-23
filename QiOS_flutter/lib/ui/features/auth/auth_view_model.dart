import 'package:flutter/material.dart';
import '../../../data/models/user_model.dart';
import '../../../data/services/api_service.dart';

class AuthViewModel extends ChangeNotifier {
  final ApiService _apiService = ApiService();

  UserModel? _user;
  bool _isLoading = false;
  String? _errorMessage;
  String _currentServerUrl = ApiService.defaultServerUrl;

  UserModel? get user => _user;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;
  String get currentServerUrl => _currentServerUrl;
  bool get isAuthenticated => _user != null;

  AuthViewModel() {
    loadServerUrl();
  }

  Future<void> loadServerUrl() async {
    _currentServerUrl = await _apiService.getBaseUrl();
    notifyListeners();
  }

  Future<void> updateServerUrl(String newUrl) async {
    await _apiService.saveBaseUrl(newUrl);
    _currentServerUrl = await _apiService.getBaseUrl();
    notifyListeners();
  }

  Future<bool> testServer(String testUrl) async {
    return await _apiService.testConnection(testUrl);
  }

  Future<bool> login(String username, String password, {bool forceLogin = false}) async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      _user = await _apiService.login(username, password, forceLogin: forceLogin);
      _isLoading = false;
      notifyListeners();
      return true;
    } catch (e) {
      _isLoading = false;
      if (e is AlreadyActiveException) {
        _errorMessage = e.message;
      } else {
        _errorMessage = e.toString().replaceAll('Exception: ', '');
      }
      notifyListeners();
      rethrow;
    }
  }

  void logout() {
    if (_user != null) {
      _apiService.logoutServer(_user!.id, _user!.token);
    }
    _user = null;
    notifyListeners();
  }

  Future<bool> checkHeartbeat() async {
    if (_user == null) return false;
    final valid = await _apiService.checkHeartbeat(_user!.id, _user!.token);
    if (!valid) {
      _user = null;
      notifyListeners();
      return false;
    }
    return true;
  }
}
