import 'package:get/get.dart';

import '../../core/network/api_client.dart';
import '../../core/network/connectivity_service.dart';
import '../../core/storage/cache_store.dart';
import '../../core/storage/secure_store.dart';
import '../../data/repositories/account_repository.dart';
import '../../data/repositories/ads_repository.dart';
import '../../data/repositories/auth_repository.dart';
import '../../data/repositories/booking_repository.dart';
import '../../data/repositories/catalog_repository.dart';
import '../../data/repositories/directory_repository.dart';
import '../../data/repositories/listing_repository.dart';
import '../../data/repositories/vendor_repository.dart';
import '../../features/auth/auth_controller.dart';
import '../../features/favorites/favorites_controller.dart';
import '../../features/location/location_controller.dart';

/// What the whole app needs, for the whole app's life.
///
/// Everything here is `permanent: true` and everything NOT here is created by a
/// per-route binding and disposed on pop. The distinction matters: a controller
/// that outlives its screen is a memory leak with a nice API, and a repository
/// recreated per screen loses the in-flight deduplication that stops the home
/// screen making the same call three times.
///
/// Only three controllers qualify: the session, the saved set, and where the
/// user is browsing. Each is genuinely global state that every screen reads.
class InitialBinding extends Bindings {
  InitialBinding({
    required this.secureStore,
    required this.cacheStore,
  });

  final SecureStore secureStore;
  final CacheStore cacheStore;

  @override
  void dependencies() {
    // --- infrastructure ----------------------------------------------------
    Get.put<SecureStore>(secureStore, permanent: true);
    Get.put<CacheStore>(cacheStore, permanent: true);

    final ConnectivityService connectivity = Get.put<ConnectivityService>(
      ConnectivityService(),
      permanent: true,
    );

    final ApiClient api = Get.put<ApiClient>(
      ApiClient(
        secureStore: secureStore,
        connectivity: connectivity,
      ),
      permanent: true,
    );

    // --- repositories ------------------------------------------------------
    //
    // Stateless apart from the client they wrap, so they are cheap to keep and
    // expensive to recreate (the ApiClient's in-flight map lives on the client,
    // but a repository churn would still mean re-resolving dependencies on
    // every navigation).
    Get.put<AuthRepository>(AuthRepository(api: api), permanent: true);
    Get.put<CatalogRepository>(
      CatalogRepository(api: api, cache: cacheStore),
      permanent: true,
    );
    Get.put<ListingRepository>(ListingRepository(api: api), permanent: true);
    Get.put<DirectoryRepository>(DirectoryRepository(api: api), permanent: true);
    Get.put<AccountRepository>(AccountRepository(api: api), permanent: true);
    Get.put<BookingRepository>(BookingRepository(api: api), permanent: true);
    Get.put<VendorRepository>(VendorRepository(api: api), permanent: true);
    Get.put<AdsRepository>(AdsRepository(api: api), permanent: true);

    // --- global controllers ------------------------------------------------
    final AuthController auth = Get.put<AuthController>(
      AuthController(
        repository: Get.find<AuthRepository>(),
        secureStore: secureStore,
        cache: cacheStore,
        api: api,
      ),
      permanent: true,
    );

    Get.put<FavoritesController>(
      FavoritesController(
        repository: Get.find<AccountRepository>(),
        auth: auth,
      ),
      permanent: true,
    );

    Get.put<LocationController>(
      LocationController(
        catalog: Get.find<CatalogRepository>(),
        cache: cacheStore,
      ),
      permanent: true,
    );
  }
}
