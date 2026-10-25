@extends('layouts.admin')

@section('content')
<div class="mx-auto max-w-7xl space-y-5">

    {{-- Judul Halaman --}}
    <div>
        <h1 class="text-2xl font-bold text-slate-900">
            Peta Jaringan
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            Monitoring jaringan distribusi ULP Sribawono
        </p>
    </div>

    {{-- Kontainer Peta --}}
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">

        {{-- Header --}}
        <div class="border-b border-slate-200 p-4">
            <h2 class="text-base font-semibold text-slate-800">
                Peta Interaktif
            </h2>
            <p class="mt-1 text-xs text-slate-500">
                Lokasi Keypoint berdasarkan data Neon PostgreSQL
            </p>
        </div>

        {{-- Filter Peta --}}
        <div class="border-b border-slate-200 bg-slate-50 p-4">

            <div class="flex flex-wrap items-end gap-3">

                {{-- Penyulang --}}
                <div class="min-w-[180px] flex-1">
                    <label
                        for="maps-penyulang-filter"
                        class="mb-1.5 block text-xs font-semibold text-slate-600"
                    >
                        Penyulang
                    </label>

                    <select
                        id="maps-penyulang-filter"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                    >
                        <option value="">Semua Penyulang</option>
                    </select>
                </div>

                {{-- Jenis Peralatan --}}
                <div class="min-w-[180px] flex-1">
                    <label
                        for="maps-jenis-filter"
                        class="mb-1.5 block text-xs font-semibold text-slate-600"
                    >
                        Jenis Peralatan
                    </label>

                    <select
                        id="maps-jenis-filter"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                    >
                        <option value="">Semua Jenis</option>
                    </select>
                </div>

                {{-- Reset Filter --}}
                <div>
                    <button
                        id="maps-reset-filter"
                        type="button"
                        class="rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700"
                    >
                        Reset Filter
                    </button>
                </div>

            </div>

            {{-- Status Marker --}}
            <p
                id="maps-filter-status"
                class="mt-3 text-xs font-medium text-slate-500"
                role="status"
                aria-live="polite"
            >
                Memuat data Keypoint...
            </p>

        </div>

        {{-- Peta Leaflet --}}
        <div
            id="admin-network-map"
            class="h-[520px] w-full"
        ></div>

        {{-- Footer --}}
        <div class="border-t border-slate-200 px-4 py-3">
            <p class="text-xs text-slate-500">
                Klik marker untuk melihat detail peralatan.
                Klik area peta untuk mengetahui koordinat lokasi.
            </p>
        </div>

    </div>

</div>
@endsection