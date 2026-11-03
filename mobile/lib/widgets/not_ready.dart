import 'package:flutter/material.dart';

/// Layar tujuan belum dibuat: beri tahu dengan jujur, jangan diam saja.
void showNotReady(BuildContext context, String feature) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text('$feature belum tersedia')));
}
