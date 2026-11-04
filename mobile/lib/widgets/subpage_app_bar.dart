import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../theme/app_colors.dart';
import '../theme/app_text.dart';

/// App bar putih setinggi 60 untuk layar turunan: judul dan satu baris keterangan.
class SubpageAppBar extends StatelessWidget implements PreferredSizeWidget {
  const SubpageAppBar({
    super.key,
    required this.title,
    required this.subtitle,
    required this.backTooltip,
  });

  final String title;
  final String subtitle;
  final String backTooltip;

  @override
  Size get preferredSize => const Size.fromHeight(60);

  @override
  Widget build(BuildContext context) {
    return AppBar(
      toolbarHeight: 60,
      backgroundColor: AppColors.surface,
      surfaceTintColor: Colors.transparent,
      systemOverlayStyle: SystemUiOverlayStyle.dark,
      titleSpacing: 0,
      shape: const Border(bottom: BorderSide(color: AppColors.border)),
      leading: IconButton(
        tooltip: backTooltip,
        icon: const Icon(Icons.arrow_back_rounded),
        onPressed: () => Navigator.of(context).pop(),
      ),
      title: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: AppText.titleSmall),
          Text(subtitle, style: AppText.caption),
        ],
      ),
    );
  }
}
