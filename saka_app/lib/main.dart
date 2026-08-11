import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

import 'app/bindings/initial_binding.dart';
import 'app/config/app_config.dart';
import 'app/routes/app_pages.dart';
import 'app/routes/app_routes.dart';
import 'app/theme/app_colors.dart';
import 'app/theme/app_theme.dart';
import 'core/storage/cache_store.dart';
import 'core/storage/secure_store.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Fails immediately if a release build was compiled against a development API
  // origin or with network logging on. Better a hard failure on the developer's
  // machine than a store build quietly pointing at a laptop.
  AppConfig.assertSafe();

  // Portrait only. Every layout in this app is a single-column mobile design;
  // offering landscape would ship rotations nobody has reviewed.
  await SystemChrome.setPreferredOrientations(<DeviceOrientation>[
    DeviceOrientation.portraitUp,
  ]);

  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.dark,
      statusBarBrightness: Brightness.light,
      systemNavigationBarColor: AppColors.background,
      systemNavigationBarIconBrightness: Brightness.dark,
    ),
  );

  // Opened BEFORE the first frame, on purpose.
  //
  // This is what lets the home screen paint cached categories and listings
  // synchronously in its first build instead of showing a spinner and filling
  // in later. Both are fast local operations; the cost is a few milliseconds of
  // startup in exchange for removing the app's most visible loading state.
  final CacheStore cache = await CacheStore.open();
  final SecureStore secure = SecureStore();
  await secure.load();

  runApp(SakaApp(cache: cache, secure: secure));
}

class SakaApp extends StatelessWidget {
  const SakaApp({required this.cache, required this.secure, super.key});

  final CacheStore cache;
  final SecureStore secure;

  @override
  Widget build(BuildContext context) {
    return GetMaterialApp(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light,
      // Light only — see AppTheme for why the web's unreviewed dark tokens are
      // not shipped as a mobile dark mode.
      themeMode: ThemeMode.light,
      initialBinding: InitialBinding(secureStore: secure, cacheStore: cache),
      initialRoute: Routes.shell,
      getPages: AppPages.pages,
      defaultTransition: Transition.cupertino,
      // Matches the theme's page transitions; GetX would otherwise use its own
      // default and the two would disagree on push versus dialog routes.
      transitionDuration: const Duration(milliseconds: 300),
      builder: (BuildContext context, Widget? child) {
        // Text scaling is honoured but bounded. Beyond ~1.3 the two-column
        // listing grid stops fitting a price and a title on a 360px phone, and
        // an unreadable clipped card serves an accessibility user worse than a
        // slightly smaller one.
        final MediaQueryData media = MediaQuery.of(context);
        return MediaQuery(
          data: media.copyWith(
            textScaler: media.textScaler.clamp(
              minScaleFactor: 0.9,
              maxScaleFactor: 1.3,
            ),
          ),
          child: child ?? const SizedBox.shrink(),
        );
      },
    );
  }
}
