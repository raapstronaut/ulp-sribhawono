<?php

namespace Tests\Unit;

use App\Services\Pasila\PasilaCrewReportReview;
use App\Services\Pasila\PoskoCrewPreview;
use App\Services\PoskoCrewRotationService;
use App\Services\ShiftService;
use App\Services\Work\PoskoWorkOrderCrewReview;
use PHPUnit\Framework\TestCase;

final class PoskoWorkOrderCrewReviewTest extends TestCase
{
    private function service(): PoskoWorkOrderCrewReview
    {
        $preview = new PoskoCrewPreview(new PoskoCrewRotationService(new ShiftService));
        return new PoskoWorkOrderCrewReview($preview, new PasilaCrewReportReview($preview));
    }

    /** @return array<string,mixed> */
    private function schedule(): array
    {
        return [
            'status' => 'ready',
            'crew_number' => 1,
            'members' => [
                ['id' => 'petugas-a', 'name' => 'Petugas A'],
                ['id' => 'petugas-b', 'name' => 'Petugas B'],
            ],
        ];
    }

    /** @param array<int, string|null> $ids */
    private function entry(array $ids, bool $unlinked = false): array
    {
        return [
            'id' => 'laporan-1',
            'label' => 'PEMASANGAN_SELESAI',
            'personnel_ids' => $ids,
            'personnel_names' => ['Nama tersimpan'],
            'has_unlinked_personnel' => $unlinked,
            'occurred_at' => '2026-11-09T08:30:00+07:00',
            'received_at' => '2026-11-10T10:00:00+07:00',
        ];
    }

    public function test_matching_two_roster_ids_in_any_order(): void
    {
        $service = $this->service();
        $result = $service->reviewEntries($this->schedule(), [$this->entry(['petugas-b', 'petugas-a'])]);
        $this->assertSame('match', $result['rows'][0]['status']);
        $this->assertSame(1, $result['counts']['match']);
    }

    public function test_one_scheduled_member_is_partial(): void
    {
        $result = $this->service()->reviewEntries($this->schedule(), [$this->entry(['petugas-a'])]);
        $this->assertSame('partial', $result['rows'][0]['status']);
    }

    public function test_member_from_other_pair_is_flagged_not_rejected(): void
    {
        $result = $this->service()->reviewEntries($this->schedule(), [$this->entry(['petugas-z'])]);
        $this->assertSame('different', $result['rows'][0]['status']);
        $this->assertSame('Perlu tinjauan', $result['rows'][0]['status_label']);
    }

    public function test_unknown_free_text_name_is_not_automatically_matched(): void
    {
        $result = $this->service()->reviewEntries($this->schedule(), [$this->entry(['petugas-a', null], true)]);
        $this->assertSame('unverifiable', $result['rows'][0]['status']);
    }

    public function test_external_id_with_free_text_still_requires_review(): void
    {
        $result = $this->service()->reviewEntries($this->schedule(), [$this->entry(['petugas-z', null], true)]);
        $this->assertSame('different', $result['rows'][0]['status']);
    }

    public function test_missing_schedule_or_reported_ids_is_unverifiable(): void
    {
        $service = $this->service();
        $this->assertSame('unverifiable', $service->reviewEntries(['status'=>'unavailable','members'=>[]], [$this->entry(['petugas-a'])])['rows'][0]['status']);
        $this->assertSame('unverifiable', $service->reviewEntries($this->schedule(), [$this->entry([])])['rows'][0]['status']);
    }

    public function test_offline_receipt_time_is_not_used_for_roster_comparison(): void
    {
        $entry = $this->entry(['petugas-a', 'petugas-b']);
        $entry['received_at'] = '2026-11-12T22:00:00+07:00';
        $result = $this->service()->reviewEntries($this->schedule(), [$entry]);
        $this->assertSame('match', $result['rows'][0]['status']);
        $this->assertSame('2026-11-12T22:00:00+07:00', $result['rows'][0]['received_at']);
    }

    public function test_zero_reports_has_zero_counts(): void
    {
        $result = $this->service()->reviewEntries($this->schedule(), []);
        $this->assertSame([], $result['rows']);
        $this->assertSame(['match'=>0,'partial'=>0,'different'=>0,'unverifiable'=>0], $result['counts']);
    }
}
