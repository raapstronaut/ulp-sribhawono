{{-- Audit informatif pelaksana WO POSKO, bukan identitas petugas pembuat temuan PASILA. --}}
@php
    $schedule = $crewAudit['schedule'] ?? [];
    $rows = $crewAudit['rows'] ?? [];
    $counts = $crewAudit['counts'] ?? [];
    $source = $crewAudit['p0_source'] ?? null;
    $tones = ['match' => 'emerald', 'partial' => 'amber', 'different' => 'red', 'unverifiable' => 'slate'];
    $formatWib = static fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB' : '—';
@endphp
<x-admin.panel title="Audit jadwal pelaksana WO" description="Baca-saja: perbandingan petugas laporan dengan pasangan terjadwal untuk tanggal dan shift WO. Tidak memblokir laporan atau mengubah status pekerjaan.">
    <div class="space-y-3 text-sm" data-wo-crew-audit>
        <div class="grid gap-3 md:grid-cols-2">
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs font-semibold uppercase text-slate-500">Jadwal pekerjaan</p>
                <p class="mt-1 font-semibold text-slate-800">{{ $crewAudit['work_date'] ?? '—' }} · Shift {{ $crewAudit['shift_code'] ?? '—' }}</p>
                @if (($schedule['status'] ?? '') === 'ready')
                    <p class="mt-1 text-slate-700">TIM {{ $schedule['crew_number'] ?? '—' }} · {{ collect($schedule['members'] ?? [])->pluck('name')->implode(' dan ') }}</p>
                @else
                    <p class="mt-1 text-amber-800">Belum bisa diverifikasi: {{ $schedule['message'] ?? 'Jadwal atau roster belum tersedia.' }}</p>
                @endif
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs font-semibold uppercase text-slate-500">Laporan yang diperiksa</p>
                <p class="mt-1 text-lg font-bold text-slate-800">{{ count($rows) }} laporan</p>
                <p class="mt-1 text-xs text-slate-600">Sesuai {{ $counts['match'] ?? 0 }} · Sebagian {{ $counts['partial'] ?? 0 }} · Perlu tinjauan {{ $counts['different'] ?? 0 }} · Belum terverifikasi {{ $counts['unverifiable'] ?? 0 }}</p>
            </div>
        </div>

        @if ($source)
            <p class="rounded-lg border border-blue-100 bg-blue-50 p-3 text-xs text-blue-900">
                Sumber P0: {{ $source['p0_code'] ?? '—' }} · {{ $source['source_type'] ?? '—' }}.
                Identitas pelapor temuan sumber tidak otomatis sama dengan pelaksana WO P0.
            </p>
        @endif

        @if (count($rows) === 0)
            <p class="text-xs text-slate-600">Belum ada laporan pelaksanaan WO yang dapat dibandingkan.</p>
        @else
            <div class="overflow-x-auto rounded-lg border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-left text-xs">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th scope="col" class="px-3 py-2">Jenis laporan</th>
                            <th scope="col" class="px-3 py-2">Petugas tercatat</th>
                            <th scope="col" class="px-3 py-2">Hasil pemeriksaan</th>
                            <th scope="col" class="px-3 py-2">Waktu kejadian</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-3 py-3 align-top">{{ $row['label'] ?? 'Laporan WO' }}</td>
                                <td class="px-3 py-3 align-top">{{ implode(', ', $row['personnel_names'] ?? []) ?: 'Belum tercatat' }}</td>
                                <td class="px-3 py-3 align-top">
                                    <x-admin.status-badge :tone="$tones[$row['status'] ?? 'unverifiable'] ?? 'slate'">{{ $row['status_label'] ?? 'Belum terverifikasi' }}</x-admin.status-badge>
                                    <p class="mt-1 max-w-sm text-slate-600">{{ $row['note'] ?? '' }}</p>
                                </td>
                                <td class="px-3 py-3 align-top">{{ $formatWib($row['occurred_at'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p class="text-xs text-slate-500">{{ $crewAudit['disclaimer'] ?? 'Audit informatif, bukan bukti pelanggaran.' }} Laporan offline dan pergantian petugas tetap mungkin sah. Jadwal WO yang pernah diubah perlu diperiksa melalui riwayat.</p>
    </div>
</x-admin.panel>