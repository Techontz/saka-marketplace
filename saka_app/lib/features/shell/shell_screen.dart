import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/network/connectivity_service.dart';
import '../../core/widgets/states.dart';
import '../account/account_screen.dart';
import '../auth/auth_controller.dart';
import '../explore/explore_screen.dart';
import '../favorites/favorites_controller.dart';
import '../favorites/saved_screen.dart';
import '../home/home_screen.dart';

/// The app's frame: four tabs and the offline banner.
///
/// An `IndexedStack`, not a PageView and not a rebuilt body. Every tab's
/// widget subtree — and therefore its scroll position, its loaded pages and its
/// controllers — stays alive when the user switches away. Scrolling halfway
/// down Home, checking Saved and coming back must not reset Home to the top or
/// re-request the rails.
///
/// The cost is that all four tabs are built at launch. That is deliberate and
/// bounded: each is a lazy sliver list, so building them constructs a scroll
/// view and nothing else until they are on screen.
class ShellScreen extends StatefulWidget {
  const ShellScreen({super.key});

  @override
  State<ShellScreen> createState() => _ShellScreenState();
}

class _ShellScreenState extends State<ShellScreen> {
  int _index = 0;

  /// One navigator key per tab, so each tab keeps its own back stack. Without
  /// this, opening a listing from Home and switching to Saved would leave the
  /// listing on the shared stack and Back would return to it from the wrong tab.
  final ConnectivityService _connectivity = Get.find<ConnectivityService>();

  void _select(int next) {
    if (next == _index) {
      // Tapping the active tab scrolls it to the top — the iOS convention, and
      // the fastest way out of a long list.
      _scrollActiveToTop();
      return;
    }
    HapticFeedback.selectionClick();
    setState(() => _index = next);
  }

  void _scrollActiveToTop() {
    final ScrollController? controller = _controllers[_index];
    if (controller == null || !controller.hasClients) return;
    controller.animateTo(
      0,
      duration: AppMotion.slow,
      curve: AppMotion.easeOut,
    );
  }

  final Map<int, ScrollController> _controllers = <int, ScrollController>{
    0: ScrollController(),
    1: ScrollController(),
    2: ScrollController(),
  };

  @override
  void dispose() {
    for (final ScrollController c in _controllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.page,
      body: Column(
        children: <Widget>[
          // The banner sits ABOVE the content rather than floating over it, so
          // it never covers a control the user is reaching for.
          //
          // A StreamBuilder, not an Obx: ConnectivityService is a plain service
          // rather than a GetxController — core/network holds no dependency on
          // the state layer — so it exposes a Stream and a plain getter. An Obx
          // over a plain bool subscribes to nothing, which GetX detects and
          // renders as a full-screen error in place of the whole shell.
          StreamBuilder<bool>(
            stream: _connectivity.onStatusChange,
            initialData: _connectivity.isOnlineNow,
            builder: (BuildContext context, AsyncSnapshot<bool> snapshot) {
              final bool online = snapshot.data ?? true;
              return AnimatedSize(
                duration: AppMotion.base,
                curve: AppMotion.easeOut,
                child: online
                    ? const SizedBox.shrink()
                    : OfflineBanner(onRetry: _connectivity.refresh),
              );
            },
          ),
          Expanded(
            child: IndexedStack(
              index: _index,
              children: <Widget>[
                HomeScreen(scrollController: _controllers[0]!),
                ExploreScreen(scrollController: _controllers[1]!),
                SavedScreen(scrollController: _controllers[2]!),
                const AccountScreen(),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: _BottomBar(index: _index, onSelect: _select),
    );
  }
}

/// A hand-built bar rather than NavigationBar.
///
/// Material 3's NavigationBar carries an animated pill indicator and a fixed
/// 80pt height that read unmistakably as Android. This is quieter: an icon, a
/// label, and the brand teal on the active item — closer to the web's own
/// navigation and to what an iOS user expects.
class _BottomBar extends StatelessWidget {
  const _BottomBar({required this.index, required this.onSelect});

  final int index;
  final ValueChanged<int> onSelect;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: AppColors.background,
        border: Border(top: AppBorders.hairline),
      ),
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: AppSizes.bottomNavHeight,
          child: Row(
            children: <Widget>[
              _BarItem(
                icon: Icons.home_outlined,
                activeIcon: Icons.home_rounded,
                label: 'Home',
                isActive: index == 0,
                onTap: () => onSelect(0),
              ),
              _BarItem(
                icon: Icons.explore_outlined,
                activeIcon: Icons.explore_rounded,
                label: 'Explore',
                isActive: index == 1,
                onTap: () => onSelect(1),
              ),
              _SavedBarItem(isActive: index == 2, onTap: () => onSelect(2)),
              _AccountBarItem(isActive: index == 3, onTap: () => onSelect(3)),
            ],
          ),
        ),
      ),
    );
  }
}

class _BarItem extends StatelessWidget {
  const _BarItem({
    required this.icon,
    required this.activeIcon,
    required this.label,
    required this.isActive,
    required this.onTap,
    this.badgeCount,
  });

  final IconData icon;
  final IconData activeIcon;
  final String label;
  final bool isActive;
  final VoidCallback onTap;
  final int? badgeCount;

  @override
  Widget build(BuildContext context) {
    final Color color =
        isActive ? AppColors.primary : AppColors.mutedForeground;

    return Expanded(
      child: Semantics(
        selected: isActive,
        button: true,
        label: label,
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTap: onTap,
          child: SizedBox(
            height: AppSizes.bottomNavHeight,
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: <Widget>[
                Stack(
                  clipBehavior: Clip.none,
                  children: <Widget>[
                    // 240ms icon swap. Long enough to register as a change,
                    // short enough not to lag the tab switch behind the tap.
                    AnimatedSwitcher(
                      duration: AppMotion.base,
                      transitionBuilder:
                          (Widget child, Animation<double> animation) =>
                              ScaleTransition(
                        scale: Tween<double>(begin: 0.85, end: 1)
                            .animate(animation),
                        child: FadeTransition(opacity: animation, child: child),
                      ),
                      child: Icon(
                        isActive ? activeIcon : icon,
                        key: ValueKey<bool>(isActive),
                        size: 24,
                        color: color,
                      ),
                    ),
                    if (badgeCount != null && badgeCount! > 0)
                      Positioned(
                        top: -3,
                        right: -7,
                        child: _CountBadge(count: badgeCount!),
                      ),
                  ],
                ),
                const SizedBox(height: 3),
                Text(
                  label,
                  style: AppTypography.caption.copyWith(
                    color: color,
                    fontSize: 10.5,
                    fontWeight: isActive ? FontWeight.w800 : FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Saved, with a live count.
///
/// Its own widget so the Obx wraps ONLY this tab. Putting the observable in the
/// bar would rebuild all four items every time a heart is tapped anywhere in
/// the app.
class _SavedBarItem extends StatelessWidget {
  const _SavedBarItem({required this.isActive, required this.onTap});

  final bool isActive;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final FavoritesController favorites = Get.find<FavoritesController>();
    return Obx(
      () => _BarItem(
        icon: Icons.favorite_border_rounded,
        activeIcon: Icons.favorite_rounded,
        label: 'Saved',
        isActive: isActive,
        onTap: onTap,
        badgeCount: favorites.savedCount,
      ),
    );
  }
}

/// Account, which shows the user's initials once they are signed in.
class _AccountBarItem extends StatelessWidget {
  const _AccountBarItem({required this.isActive, required this.onTap});

  final bool isActive;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final AuthController auth = Get.find<AuthController>();
    return Obx(
      () => _BarItem(
        icon: auth.isSignedIn
            ? Icons.account_circle_outlined
            : Icons.person_outline_rounded,
        activeIcon: Icons.account_circle_rounded,
        label: auth.isSignedIn ? 'Account' : 'Sign in',
        isActive: isActive,
        onTap: onTap,
      ),
    );
  }
}

class _CountBadge extends StatelessWidget {
  const _CountBadge({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: const BoxConstraints(minWidth: 16),
      height: 16,
      padding: const EdgeInsets.symmetric(horizontal: 4),
      decoration: BoxDecoration(
        color: AppColors.orange,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: AppColors.background, width: 1.5),
      ),
      alignment: Alignment.center,
      child: Text(
        // Caps at 99+. A four-digit badge is wider than the icon it sits on.
        count > 99 ? '99+' : '$count',
        style: AppTypography.overline.copyWith(
          color: Colors.white,
          fontSize: 9,
          letterSpacing: 0,
        ),
      ),
    );
  }
}
