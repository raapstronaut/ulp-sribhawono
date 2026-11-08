<?php

namespace Tests\Unit;

use App\Models\FieldPersonnel;
use App\Models\FieldTeam;
use App\Services\Pasila\PoskoCrewPreview;
use App\Services\PoskoCrewRotationService;
use App\Services\ShiftService;
use Tests\TestCase;

class PasilaCrewPreviewTest extends TestCase
{
    private PoskoCrewPreview $preview;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('field_ops.posko_crew_anchor_date', '2026-11-09');
        $this->preview = new PoskoCrewPreview(new PoskoCrewRotationService(new ShiftService));
    }

    private function team(int $activeCount = 8): FieldTeam
    {
        $team = new FieldTeam(['name' => 'POSKO JABUNG', 'role' => 'POSKO', 'is_active' => true]);
        $members = collect();
        for ($i = 0; $i < $activeCount; $i++) {
            $members->push(new FieldPersonnel([
                'name' => 'Person '.$i,
                'nickname' => 'Petugas '.$i,
                'crew_number' => intdiv($i, 2) + 1,
                'is_active' => true,
            ]));
        }
        $team->setRelation('personnel', $members);
        return $team;
    }

    public function test_initial_four_days_resolve_the_correct_two_person_crews(): void
    {
        $team = $this->team();
        foreach ([1, 4, 3, 2] as $day => $expectedCrew) {
            $date = sprintf('2026-11-%02d', 9 + $day);
            $result = $this->preview->fromTeams(collect([$team]), $date, 'A');
            $this->assertSame('ready', $result['status']);
            $this->assertSame($expectedCrew, $result['crew_number']);
            $this->assertCount(2, $result['members']);
        }
    }

    public function test_before_anchor_is_unavailable_even_with_complete_roster(): void
    {
        $result = $this->preview->fromTeams(collect([$this->team()]), '2026-11-08', 'A');
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame([], $result['members']);
    }

    public function test_incomplete_roster_and_ambiguous_parent_team_are_rejected(): void
    {
        $this->assertSame('unavailable', $this->preview
            ->fromTeams(collect([$this->team(7)]), '2026-11-09', 'A')['status']);
        $this->assertSame('unavailable', $this->preview
            ->fromTeams(collect([$this->team(), $this->team()]), '2026-11-09', 'A')['status']);
        $this->assertSame('unavailable', $this->preview
            ->fromTeams(collect(), '2026-11-09', 'A')['status']);
    }
}