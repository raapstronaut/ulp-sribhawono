<?php

namespace App\Support;

/**
 * Satu sumber menu Web Admin untuk sidebar desktop dan navigasi seluler.
 *
 * Item memakai `svg` (daftar path ikon) atau `icon` (singkatan dua huruf).
 */
final class AdminNavigation
{
    /**
     * @return array<int, array{label: ?string, items: array<int, array{label: string, route: string, active: string, icon?: string, svg?: array<int, string>}>}>
     */
    public static function sections(): array
    {
        return [
            [
                'label' => null,
                'items' => [
                    [
                        'label' => 'Dashboard',
                        'route' => 'admin.dashboard',
                        'active' => 'admin.dashboard',
                        'svg' => ['M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z'],
                    ],
                ],
            ],
            [
                'label' => 'Pekerjaan',
                'items' => [
                    ['label' => 'P0', 'route' => 'admin.p0.index', 'active' => 'admin.p0.*', 'icon' => 'P0'],
                    [
                        'label' => 'Work Order',
                        'route' => 'admin.work-orders.index',
                        'active' => 'admin.work-orders.*',
                        'svg' => ['M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 7h6m-6 4h4'],
                    ],
                    ['label' => 'APKUK', 'route' => 'admin.apkuk.index', 'active' => 'admin.apkuk.*', 'icon' => 'AP'],
                    ['label' => 'PASILA', 'route' => 'admin.pasila.index', 'active' => 'admin.pasila.*', 'icon' => 'PS'],
                    ['label' => 'Inspeksi', 'route' => 'admin.inspeksi.index', 'active' => 'admin.inspeksi.*', 'icon' => 'IN'],
                    ['label' => 'ROW', 'route' => 'admin.row.index', 'active' => 'admin.row.*', 'icon' => 'RW'],
                    ['label' => 'HARSUS', 'route' => 'admin.harsus.index', 'active' => 'admin.harsus.*', 'icon' => 'HS'],
                ],
            ],
            [
                'label' => 'Kontrol',
                'items' => [
                    ['label' => 'Peta Jaringan', 'route' => 'admin.maps.index', 'active' => 'admin.maps.*', 'icon' => 'PJ'],
                    ['label' => 'Review & Validasi', 'route' => 'admin.reviews.index', 'active' => 'admin.reviews.*', 'icon' => 'RV'],
                    ['label' => 'Rekonsiliasi APKUK', 'route' => 'admin.apkuk-reconciliation.index', 'active' => 'admin.apkuk-reconciliation.*', 'icon' => 'RK'],
                    ['label' => 'Special Case', 'route' => 'admin.special-case.index', 'active' => 'admin.special-case.*', 'icon' => 'SC'],
                ],
            ],
            [
                'label' => 'Data & Admin',
                'items' => [
                    ['label' => 'Master Data', 'route' => 'admin.master-data.index', 'active' => 'admin.master-data.*', 'icon' => 'MD'],
                    ['label' => 'Analitik', 'route' => 'admin.analytics.index', 'active' => 'admin.analytics.*', 'icon' => 'AN'],
                    ['label' => 'User & Hak Akses', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'icon' => 'UA'],
                    ['label' => 'Audit Log', 'route' => 'admin.audit-logs.index', 'active' => 'admin.audit-logs.*', 'icon' => 'AL'],
                    [
                        'label' => 'Pengaturan Akun',
                        'route' => 'admin.account.edit',
                        'active' => 'admin.account.*',
                        'svg' => [
                            'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
                            'M15 12a3 3 0 11-6 0 3 3 0 016 0z',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function routeNames(): array
    {
        return collect(self::sections())
            ->flatMap(fn (array $section): array => array_column($section['items'], 'route'))
            ->values()
            ->all();
    }
}
