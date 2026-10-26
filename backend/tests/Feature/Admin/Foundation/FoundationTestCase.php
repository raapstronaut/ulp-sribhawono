<?php

namespace Tests\Feature\Admin\Foundation;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Basis test modul Web Admin Foundation v1. Data dibuat per test sebagai fixture,
 * bukan data operasional.
 */
abstract class FoundationTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    private int $workOrderSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // 09:00 WIB → shift A (08–16) pada 20 Okt 2026.
        $this->travelTo(Carbon::create(2026, 10, 20, 9, 0, 0, 'Asia/Jakarta'));
        $this->admin = User::factory()->admin()->create(['username' => 'FOUNDATION_ADMIN']);
    }

    protected function makeWorkOrder(array $attributes = []): WorkOrder
    {
        $this->workOrderSequence++;

        // forceFill agar fixture dapat menetapkan created_at untuk urutan yang deterministik.
        return tap((new WorkOrder)->forceFill(array_merge([
            'code' => sprintf('WO-TEST-%04d', $this->workOrderSequence),
            'team_type' => WorkOrder::TEAM_POSKO,
            'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
            'status' => WorkOrderStatus::Ditugaskan,
            'work_date' => '2026-10-20',
            'shift_code' => 'A',
        ], $attributes)))->save();
    }

    protected function makeGangguan(string $id, array $attributes = []): void
    {
        DB::table('gangguans')->insert(array_merge([
            'id_gangguan' => $id,
            'tanggal_kejadian' => '2026-10-15',
            'penyulang' => 'REOG',
            'peralatan' => 'REC TEST',
            'penyebab' => 'POHON',
            'lama_padam_menit' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
