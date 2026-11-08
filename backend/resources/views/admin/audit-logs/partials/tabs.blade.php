<nav class="flex gap-1 border-b border-slate-200" aria-label="Jenis audit">
    @foreach (['wo' => ['admin.audit-logs.index', 'Work Order (status, laporan, keputusan)'], 'general' => ['admin.audit-logs.general', 'Master data, akun & keputusan lain']] as $key => [$route, $label])
        <a href="{{ route($route) }}" data-loading-link class="whitespace-nowrap rounded-t-md border-b-2 px-3 py-2 text-sm font-semibold {{ $active === $key ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}" @if ($active === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
