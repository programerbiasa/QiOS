import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'ui/core/app_colors.dart';
import 'ui/features/auth/auth_view_model.dart';
import 'ui/features/auth/login_screen.dart';
import 'ui/features/pos/pos_screen.dart';
import 'ui/features/pos/pos_view_model.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const KiosCendekiaApp());
}

class KiosCendekiaApp extends StatelessWidget {
  const KiosCendekiaApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => AuthViewModel()),
        ChangeNotifierProvider(create: (_) => PosViewModel()),
      ],
      child: MaterialApp(
        title: 'QiOS Cendekia',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          brightness: Brightness.dark,
          scaffoldBackgroundColor: AppColors.background,
          primaryColor: AppColors.primary,
          colorScheme: const ColorScheme.dark(
            primary: AppColors.primary,
            secondary: AppColors.primaryLight,
            surface: AppColors.surface,
          ),
          fontFamily: 'Segoe UI',
          useMaterial3: true,
        ),
        initialRoute: '/login',
        routes: {
          '/login': (_) => const LoginScreen(),
          '/pos': (_) => const PosScreen(),
        },
      ),
    );
  }
}
