<?php

namespace Tests\Feature;

use Tests\TestCase;

class PoskoCrewAuditWebPanelTest extends TestCase
{
    private function sample(): array
    {
        return [
            'work_date' => '2026-11-09',
            'shift_code' => 'A',
            'schedule' => [
                'status' => 'ready',
                'crew_number' => 1,
                'members' => [['id' => 'a', 'name' => 'Anung'], ['id' => 'b', 'name' => 'Riris']],
            ],
            'p0_source' => null,
            'rows' => [[
                'label' => 'PEKERJAAN_SELESAI',
                'personnel_names' => ['Anung', 'Riris'],
                'status' => 'match',
                'status_label' => 'Sesuai jadwal',
                'note' => 'Kedua petugas sesuai pasangan.',
                'occurred_at' => '2026-11-09T01:30:00+00:00',
            ]],
            'counts' => ['match' => 1, 'partial' => 0, 'different' => 0, 'unverifiable' => 0],
            'disclaimer' => 'Audit tidak membuktikan identitas operator perangkat.',
        ];
    }

    public function test_panel_renders_roster_status_and_local_time(): void
    {
        $html = view('admin.partials.posko-crew-audit', ['crewAudit' => $this->sample()])->render();
        $this->assertStringContainsString('Audit jadwal pelaksana WO', $html);
        $this->assertStringContainsString('TIM 1', $html);
        $this->assertStringContainsString('Anung dan Riris', $html);
        $this->assertStringContainsString('Sesuai jadwal', $html);
        $this->assertStringContainsString('09/11/2026 08:30 WIB', $html);
    }

    public function test_panel_renders_unavailable_schedule_and_empty_reports(): void
    {
        $audit = $this->sample();
        $audit['schedule'] = ['status' => 'unavailable', 'message' => 'Belum aktif', 'members' => []];
        $audit['rows'] = [];
        $audit['counts'] = ['match' => 0, 'partial' => 0, 'different' => 0, 'unverifiable' => 0];
        $html = view('admin.partials.posko-crew-audit', ['crewAudit' => $audit])->render();
        $this->assertStringContainsString('Belum aktif', $html);
        $this->assertStringContainsString('Belum ada laporan', $html);
    }

    public function test_panel_escapes_untrusted_personnel_names_and_distinguishes_p0_source(): void
    {
        $audit = $this->sample();
        $audit['rows'][0]['personnel_names'] = ['<script>alert(1)</script>'];
        $audit['p0_source'] = ['p0_code' => 'P0-TEST', 'source_type' => 'PASILA_FINDING'];
        $html = view('admin.partials.posko-crew-audit', ['crewAudit' => $audit])->render();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Sumber P0', $html);
        $this->assertStringContainsString('pelapor temuan sumber', $html);
    }
}