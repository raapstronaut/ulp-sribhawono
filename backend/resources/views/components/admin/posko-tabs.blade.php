@php
    // Tab POSKO menggunakan route yang sudah ada, tanpa endpoint/duplikasi controller baru.
    $tabs = [
        ['label' => 'WO APKUK', 'route' => 'admin.work-orders.index', 'active' => 'admin.work-orders.*'],
        ['label' => 'PASILA', 'route' => 'admin.pasila.index', 'active' => 'admin.pasila.*'],
        ['label' => 'P0', 'route' => 'admin.p0.index', 'active' => 'admin.p0.*'],
        ['label' => 'Kuota Shift', 'route' => 'admin.shift-quota.index', 'active' => 'admin.shift-quota.*'],
    ];
@endphp
<nav aria-label="Tab pekerjaan POSKO" class="rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
    <div class="px-2 pb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Pekerjaan POSKO</div>
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach ($tabs as $tab)
            @php($active = request()->routeIs($tab['active']))
            <a href="{{ route($tab['route']) }}"
               class="rounded-lg px-3 py-2.5 text-center text-sm font-semibold transition-colors {{ $active ? 'bg-blue-600 text-white shadow-sm' : 'bg-slate-50 text-slate-600 hover:bg-blue-50 hover:text-blue-700' }}"
               @if ($active) aria-current="page" @endif>{{ $tab['label'] }}</a>
        @endforeach
    </div>
</nav>
