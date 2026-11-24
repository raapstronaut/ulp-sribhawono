<?php

namespace App\Support;

/**
 * Presentasi navigasi bergaya menu pohon. Daftar route resmi tetap di
 * AdminNavigation::sections(): tidak ada route, hak akses, atau fitur dihapus.
 * Modul pendukung masuk grup tertutup secara default agar sidebar lebih ringkas.
 */
final class AdminWorkspaceMenu
{
    /** @return array<int, array<string, mixed>> */
    public static function groups(): array
    {
        $items = collect(AdminNavigation::sections())
            ->flatMap(fn (array $section): array => $section['items'])
            ->keyBy('route');

        $item = static fn (string $route): array => ['item' => $items->get($route)];
        $sub = static fn (string $label, array $routes): array => [
            'label' => $label,
            'children' => array_map($item, $routes),
        ];

        return [
            [
                'key' => 'utama',
                'label' => 'Beranda',
                'default_open' => true,
                'nodes' => [$item('admin.dashboard')],
            ],
            [
                'key' => 'pekerjaan',
                'label' => 'PEKERJAAN LAPANGAN',
                'default_open' => true,
                'nodes' => [
                    $item('admin.work-orders.index'),
                    $item('admin.row.index'),
                    $item('admin.harsus.index'),
                    $sub('INSPEKSI', ['admin.inspeksi.index', 'admin.inspeksi.queue']),
                ],
            ],
            [
                'key' => 'jaringan',
                'label' => 'DATA & JARINGAN',
                'default_open' => true,
                'nodes' => [
                    $item('admin.aset.index'),
                    $item('admin.maps.index'),
                    $item('admin.master-data.index'),
                ],
            ],
            [
                'key' => 'monitor',
                'label' => 'MONITORING',
                'default_open' => false,
                'nodes' => [
                    $item('admin.reviews.index'),
                    $item('admin.analytics.index'),
                ],
            ],
            [
                'key' => 'lainnya',
                'label' => 'MENU PENDUKUNG',
                'default_open' => false,
                'nodes' => [
                    $sub('Data sumber & rekonsiliasi', ['admin.apkuk.index', 'admin.apkuk-reconciliation.index']),
                    $sub('Kontrol & pengaturan', [
                        'admin.special-case.index',
                        'admin.users.index',
                        'admin.audit-logs.index',
                        'admin.account.edit',
                    ]),
                ],
            ],
        ];
    }
}
