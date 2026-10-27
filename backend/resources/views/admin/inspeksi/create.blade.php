@extends('layouts.admin')

@section('title', 'Catat inspeksi')

@section('content')
<div class="mx-auto max-w-5xl space-y-6 pb-12">
    <x-admin.page-header section="Pekerjaan" parent="Inspeksi" :parent-url="route('admin.inspeksi.index')" title="Catat kunjungan inspeksi"
        description="Input Admin atas nama tim INSPEKSI. Kosongkan semua temuan untuk mencatat 'Tidak Ada Temuan' (wajib catatan). Setiap temuan wajib punya jenis, uraian, dan aset atau koordinat." />

    @if ($teams->isEmpty())
        <x-admin.notice tone="warning">Belum ada tim INSPEKSI aktif. Tambahkan di <a class="underline" href="{{ route('admin.master-data.index', ['tab' => 'tim']) }}">Master Data → Tim Lapangan</a>.</x-admin.notice>
    @endif
    @if ($findingCodes === [])
        <x-admin.notice tone="warning">Belum ada kode <strong>Jenis temuan inspeksi</strong>. Kunjungan tanpa temuan tetap dapat dicatat; untuk temuan, tambahkan kode di <a class="underline" href="{{ route('admin.master-data.index', ['tab' => 'kode']) }}">Master Data → Kode Referensi</a>.</x-admin.notice>
    @endif

    <form method="POST" action="{{ route('admin.inspeksi.store') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf
        <x-admin.panel title="Kunjungan">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-admin.select name="field_team_id" label="Tim INSPEKSI" :options="$teams->pluck('name', 'id')->all()" placeholder="Pilih tim" required />
                <x-admin.select name="penyulang_id" label="Penyulang" :options="$penyulangOptions" placeholder="Pilih penyulang" required />
                <x-admin.input name="section_name" label="Section / area" maxlength="255" />
                <x-admin.input type="datetime-local" name="visited_at" label="Waktu kunjungan (WIB)" :value="$nowWib" required />
            </div>
            <fieldset class="mt-4">
                <legend class="text-xs font-semibold text-slate-700">Petugas <span class="text-red-600">*</span></legend>
                <div class="mt-1 grid max-h-40 grid-cols-1 gap-1 overflow-y-auto rounded-lg border border-slate-200 p-2 sm:grid-cols-3">
                    @forelse ($teams as $team)
                        @foreach ($team->personnel as $person)
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="personnel_ids[]" value="{{ $person->id }}" @checked(in_array($person->id, old('personnel_ids', []), true))> {{ $person->name }} <span class="text-slate-400">({{ $team->name }})</span></label>
                        @endforeach
                    @empty
                        <p class="text-xs text-slate-500">Belum ada petugas INSPEKSI.</p>
                    @endforelse
                </div>
            </fieldset>
            <div class="mt-4"><x-admin.input name="personnel_names" label="Nama petugas lain (pisahkan dengan koma)" /></div>
            <div class="mt-4"><x-admin.textarea name="summary" label="Catatan kunjungan" rows="2" hint="Wajib bila tidak ada temuan (mis. ruas yang diperiksa dan kondisinya)." /></div>
        </x-admin.panel>

        @for ($i = 0; $i < 3; $i++)
            <x-admin.panel :title="'Temuan '.($i + 1)" description="Kosongkan seluruh isian bila tidak dipakai.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-admin.select :name="'findings['.$i.'][finding_code]'" label="Jenis temuan" :options="$findingCodes" placeholder="—" />
                    <x-admin.input :name="'findings['.$i.'][keypoint_id]'" label="ID aset keypoint (opsional)" inputmode="numeric" hint="Lihat ID di Master Data → Keypoint." />
                    <x-admin.input :name="'findings['.$i.'][asset_label]'" label="Label aset/lokasi" maxlength="255" />
                    <x-admin.input :name="'findings['.$i.'][latitude]'" label="Latitude" placeholder="-5.20" />
                    <x-admin.input :name="'findings['.$i.'][longitude]'" label="Longitude" placeholder="105.70" />
                    <x-admin.select :name="'findings['.$i.'][suggested_team_type]'" label="Saran tim penerima" :options="$teamTypeOptions" placeholder="—" />
                </div>
                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <x-admin.textarea :name="'findings['.$i.'][description]'" label="Uraian temuan" rows="2" />
                    <x-admin.input type="file" :name="'findings['.$i.'][photos][]'" label="Foto temuan" accept="image/*" multiple />
                </div>
            </x-admin.panel>
        @endfor

        <div class="flex flex-wrap gap-3">
            <x-admin.button>Simpan inspeksi</x-admin.button>
            <x-admin.button variant="secondary" :href="route('admin.inspeksi.index')">Batal</x-admin.button>
        </div>
    </form>
</div>
@endsection
