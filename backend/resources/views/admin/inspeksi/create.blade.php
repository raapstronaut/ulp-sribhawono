@extends('layouts.admin')

@section('title', 'Catat inspeksi')

@section('content')
<div class="mx-auto max-w-5xl space-y-6 pb-12">
    <x-admin.page-header section="Pekerjaan" parent="Inspeksi" :parent-url="route('admin.inspeksi.index')" title="Catat kunjungan INSPEKSI"
        state="partial" description="Pilih tim dan lokasi kunjungan, catat petugas, lalu isi temuan hanya bila benar-benar ditemukan." />

    <section class="rounded-xl border border-blue-200 bg-blue-50/50 p-4 shadow-sm sm:p-5">
        <div class="mb-3 flex flex-wrap gap-2 text-[10px] font-bold uppercase tracking-wide">
            <span class="rounded-md bg-blue-600 px-2 py-1 text-white">INPUT INSPEKSI</span>
            <span class="rounded-md border border-blue-200 bg-white px-2 py-1 text-blue-700">Tidak ada temuan tetap sah</span>
        </div>
        <h2 class="text-lg font-bold text-slate-900">Catat kondisi lapangan dengan akurat</h2>
        <p class="mt-1 text-sm text-slate-600">Kunjungan tanpa temuan memerlukan catatan kunjungan. Jika ada temuan, lengkapi jenis, uraian, dan aset atau koordinat. Setiap temuan akan menunggu validasi Admin.</p>
        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-lg border border-blue-100 bg-white p-3">
                <div class="text-[10px] font-bold uppercase text-blue-700">Langkah 1</div>
                <div class="mt-1 text-sm font-semibold text-slate-900">Identitas kunjungan</div>
                <div class="mt-1 text-xs text-slate-500">Tim, petugas, penyulang, waktu, dan catatan.</div>
            </div>
            <div class="rounded-lg border border-blue-100 bg-white p-3">
                <div class="text-[10px] font-bold uppercase text-blue-700">Langkah 2</div>
                <div class="mt-1 text-sm font-semibold text-slate-900">Temuan per titik (jika ada)</div>
                <div class="mt-1 text-xs text-slate-500">Isi Temuan 1 lebih dulu; buka panel tambahan bila diperlukan.</div>
            </div>
        </div>
    </section>

    @if ($teams->isEmpty() || $findingCodes === [])
        <div role="alert" class="space-y-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-900">
            <p class="font-bold">Kesiapan Master Data perlu diperiksa</p>
            @if ($teams->isEmpty())
                <p>Belum ada tim INSPEKSI aktif. Form belum bisa disimpan secara sah sebelum tim dan petugas tersedia. Lengkapi <a class="font-semibold underline" href="{{ route('admin.master-data.index', ['tab' => 'tim']) }}">Master Data - Tim Lapangan</a>.</p>
            @endif
            @if ($findingCodes === [])
                <p>Belum ada kode jenis temuan INSPEKSI. Pelaporan temuan baru memerlukan kode resmi di <a class="font-semibold underline" href="{{ route('admin.master-data.index', ['tab' => 'kode']) }}">Master Data - Kode Referensi</a>. Kunjungan tanpa temuan tetap harus memenuhi syarat tim dan petugas.</p>
            @endif
        </div>
    @endif


    <form method="POST" action="{{ route('admin.inspeksi.store') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf
        <x-admin.panel title="1. Informasi kunjungan" description="Catat identitas tim, waktu WIB, petugas, serta kondisi area yang diperiksa.">
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

        <div class="rounded-xl border border-blue-100 bg-blue-50/40 p-4 text-xs leading-relaxed text-slate-700">
            <h3 class="text-sm font-bold text-slate-900">2. Temuan per titik</h3>
            <p class="mt-1">Jika <strong>tidak ada temuan</strong>, kosongkan seluruh panel temuan dan isi catatan kunjungan. Jika ada temuan, buka panel yang diperlukan dan lengkapi datanya. Temuan 2 dan 3 bersifat opsional.</p>
        </div>

        @for ($i = 0; $i < 3; $i++)
            <details class="group rounded-xl border border-slate-200 bg-white shadow-sm" @if($i === 0 || $errors->any() || filled(old('findings.'.$i.'.finding_code'))) open @endif>
                <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-800">
                    <span>Temuan {{ $i + 1 }}</span>
                    <span class="text-xs font-medium text-slate-500">{{ $i === 0 ? 'Isi jika menemukan masalah' : 'Tambahan (opsional)' }}</span>
                    <span class="text-xs text-blue-700 group-open:hidden">Buka +</span>
                    <span class="hidden text-xs text-blue-700 group-open:inline">Tutup -</span>
                </summary>
                <div class="border-t border-slate-100 p-4">
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
                </div>
            </details>
        @endfor

        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
            <strong class="text-slate-800">Sebelum menyimpan:</strong> periksa tim, petugas, waktu, dan lokasi. Kunjungan tanpa temuan tidak boleh diisi dengan temuan fiktif. Penyimpanan akan diperiksa lagi oleh validasi server.
        </div>
        <div class="flex flex-wrap gap-3">
            <x-admin.button>Simpan inspeksi</x-admin.button>
            <x-admin.button variant="secondary" :href="route('admin.inspeksi.index')">Batal</x-admin.button>
        </div>
    </form>
</div>
@endsection
