import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../theme/app_text.dart';

enum AppTab {
  beranda('Beranda', Icons.home_rounded),
  pekerjaan('Pekerjaan', Icons.format_list_bulleted_rounded),
  sinkron('Sinkron', Icons.sync_rounded),
  akun('Akun', Icons.person_outline_rounded);

  const AppTab(this.label, this.icon);

  final String label;
  final IconData icon;
}

/// Navigasi bawah setinggi 68 sesuai desain, dipakai layar-layar utama.
class AppBottomNav extends StatelessWidget {
  const AppBottomNav({
    super.key,
    required this.current,
    required this.onSelected,
    this.pendingSyncCount = 0,
  });

  final AppTab current;
  final ValueChanged<AppTab> onSelected;
  final int pendingSyncCount;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.surface,
      child: Container(
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: AppColors.border)),
        ),
        child: SafeArea(
          top: false,
          child: SizedBox(
            height: 68,
            child: Row(
              children: [
                for (final tab in AppTab.values)
                  Expanded(
                    child: _NavItem(
                      tab: tab,
                      selected: tab == current,
                      badgeCount: tab == AppTab.sinkron ? pendingSyncCount : 0,
                      onTap: () => onSelected(tab),
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

class _NavItem extends StatelessWidget {
  const _NavItem({
    required this.tab,
    required this.selected,
    required this.badgeCount,
    required this.onTap,
  });

  final AppTab tab;
  final bool selected;
  final int badgeCount;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = selected ? AppColors.primary : AppColors.textSecondary;

    return Semantics(
      button: true,
      selected: selected,
      child: InkWell(
        onTap: onTap,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Badge(
              isLabelVisible: badgeCount > 0,
              label: Text('$badgeCount'),
              backgroundColor: AppColors.syncBadge,
              child: Icon(tab.icon, size: 24, color: color),
            ),
            const SizedBox(height: 4),
            Text(
              tab.label,
              style: AppText.caption.copyWith(
                color: color,
                fontWeight: selected ? FontWeight.w700 : FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
