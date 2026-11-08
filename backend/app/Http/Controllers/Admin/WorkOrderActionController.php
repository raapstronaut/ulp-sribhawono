<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldTeam;
use App\Models\WorkOrder;
use App\Models\WorkOrderIssue;
use App\Models\WorkOrderMedia;
use App\Services\ShiftService;
use App\Services\Work\FieldActor;
use App\Services\Work\MediaStore;
use App\Services\Work\WorkOrderEditor;
use App\Services\Work\WorkOrderExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Tindakan Admin pada WO selain buat/batal: koreksi data & jadwal, serta pencatatan
 * atas nama tim lapangan (mulai, laporan + foto, limpahan, kendala) selama aplikasi
 * Android belum dipakai. Semua tindakan melewati service yang sama dengan API mobile.
 */
class WorkOrderActionController extends Controller
{
    public function edit(WorkOrder $workOrder, ShiftService $shifts): View
    {
        abort_unless(WorkOrderEditor::isEditable($workOrder), 409, 'WO dengan status ini tidak dapat diubah.');
        $workOrder->load(['posko', 'penyulang', 'fieldTeam']);

        return view('admin.work-orders.edit', [
            'wo' => $workOrder,
            'teamOptions' => FieldTeam::query()->where('role', $workOrder->team_type)->where('is_active', true)
                ->when($workOrder->team_type === WorkOrder::TEAM_POSKO && $workOrder->posko_id, fn ($q) => $q->where('posko_id', $workOrder->posko_id))
                ->orderBy('name')->pluck('name', 'id')->all(),
            'shiftOptions' => collect($shifts->codes())->mapWithKeys(fn ($c) => [$c => 'Shift '.$c.' — '.$shifts->describe($c)['name'].' ('.$shifts->describe($c)['time'].')'])->all(),
            'dueDate' => $workOrder->due_at?->copy()->timezone(ShiftService::TIMEZONE)->toDateString(),
        ]);
    }

    public function update(Request $request, WorkOrder $workOrder, WorkOrderEditor $editor): RedirectResponse
    {
        $data = $request->validate([
            'field_team_id' => ['nullable', 'uuid'],
            'section_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'shift_code' => ['nullable', 'string', 'size:1'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $editor->update($workOrder, $request->user(), $data, $data['reason']);

        return redirect()->route('admin.work-orders.show', $workOrder)->with('flash', 'Perubahan WO tersimpan dan tercatat di riwayat audit.');
    }

    public function start(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution): RedirectResponse
    {
        $data = $request->validate(['field_team_id' => ['nullable', 'uuid']]);
        $execution->start($workOrder, FieldActor::admin($request->user(), $this->team($data['field_team_id'] ?? null, $workOrder)));

        return redirect()->route('admin.work-orders.show', $workOrder)->with('flash', 'WO ditandai sedang dikerjakan.');
    }

    public function report(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution): RedirectResponse
    {
        $image = ['file', 'mimes:'.implode(',', MediaStore::MIMES), 'max:'.MediaStore::MAX_KILOBYTES];
        $data = $request->validate([
            'report_type' => ['required', 'string', Rule::in(WorkOrderExecutionService::reportTypesFor($workOrder))],
            'field_team_id' => ['nullable', 'uuid'],
            'installed_quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Waktu WIB dari form; batas "tidak di masa depan" diperiksa service setelah konversi zona.
            'occurred_at' => ['required', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'personnel_ids' => ['nullable', 'array', 'max:20'],
            'personnel_ids.*' => ['uuid'],
            'personnel_names' => ['nullable', 'string', 'max:500'],
            'photos_before' => ['nullable', 'array', 'max:10'],
            'photos_before.*' => $image,
            'photos_after' => ['nullable', 'array', 'max:10'],
            'photos_after.*' => $image,
        ]);

        $team = $this->team($data['field_team_id'] ?? null, $workOrder);
        $data['personnel_names'] = array_map('trim', explode(',', (string) ($data['personnel_names'] ?? '')));
        $data['occurred_at'] = CarbonImmutable::parse($data['occurred_at'], ShiftService::TIMEZONE)->utc()->toIso8601String();

        $report = $execution->submitReport($workOrder, FieldActor::admin($request->user(), $team), $data, [
            'before' => $request->file('photos_before', []),
            'after' => $request->file('photos_after', []),
        ], WorkOrderMedia::SOURCE_GALLERY);

        return redirect()->route('admin.work-orders.show', $workOrder)->with('flash', $report->verification_status === 'TIDAK_PERLU'
            ? 'Laporan tersimpan. WO selesai.'
            : 'Laporan tersimpan dan masuk antrean Review & Validasi.');
    }

    public function escalate(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution): RedirectResponse
    {
        $data = $request->validate([
            // Format "TIM|JENIS", mis. HARSUS|PEKERJAAN_KHUSUS.
            'target' => ['required', 'string', 'max:80'],
            'reason_code' => ['required', 'string', 'max:80'],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        [$teamType, $workType] = array_pad(explode('|', $data['target'], 2), 2, '');

        $execution->requestEscalation($workOrder, FieldActor::admin($request->user()), [
            'target_team_type' => $teamType,
            'target_work_type' => $workType,
            'reason_code' => $data['reason_code'],
            'note' => $data['note'],
        ]);

        return redirect()->route('admin.work-orders.show', $workOrder)->with('flash', 'Permintaan limpahan dibuat dan menunggu ACC di Review & Validasi.');
    }

    public function issue(Request $request, WorkOrder $workOrder, WorkOrderExecutionService $execution): RedirectResponse
    {
        $data = $request->validate([
            'issue_type' => ['required', 'string', Rule::in(array_keys(WorkOrderIssue::TYPE_LABELS))],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $execution->reportIssue($workOrder, FieldActor::admin($request->user()), $data);

        return redirect()->route('admin.work-orders.show', $workOrder)->with('flash', 'Kendala tercatat dan dipantau di Special Case.');
    }

    private function team(?string $teamId, WorkOrder $workOrder): ?FieldTeam
    {
        if (! filled($teamId)) {
            return $workOrder->fieldTeam;
        }

        $team = FieldTeam::query()->whereKey($teamId)->where('is_active', true)->first();
        abort_if($team === null || $team->role !== $workOrder->team_type, 422, 'Tim tidak sesuai dengan WO.');

        return $team;
    }
}
