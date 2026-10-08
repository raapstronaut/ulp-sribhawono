<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FieldTeamFoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_personnel_shift_and_reference_tables_have_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('field_teams', [
            'id', 'code', 'name', 'role', 'posko_id', 'is_active',
        ]));
        $this->assertTrue(Schema::hasColumns('field_personnel', [
            'id', 'field_team_id', 'personnel_code', 'name', 'is_active',
        ]));
        $this->assertTrue(Schema::hasColumns('field_team_shift_assignments', [
            'id', 'field_team_id', 'shift_code', 'effective_from', 'effective_until',
        ]));
        $this->assertTrue(Schema::hasColumns('reference_codes', [
            'id', 'category', 'code', 'label', 'role_scope', 'is_active',
        ]));
    }
}
