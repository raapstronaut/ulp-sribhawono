<?php

namespace Tests\Feature;

use App\Console\Commands\ImportSourceSheets;
use Tests\TestCase;

/**
 * Testable subclass yang mengekspos protected resolveHeader()
 * agar behavior header validation bisa diuji langsung tanpa
 * memerlukan koneksi Google Sheets.
 */
class TestableImportSourceSheets extends ImportSourceSheets
{
    public function callResolveHeader(array $allRows, array $definition): array
    {
        return $this->resolveHeader($allRows, $definition);
    }
}

class HeaderResolutionTest extends TestCase
{
    private TestableImportSourceSheets $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new TestableImportSourceSheets();
    }

    /**
     * Definisi minimal untuk testing.
     */
    private function sectionPicDefinition(): array
    {
        return [
            'sheet' => 'SECTION_PIC_RAW',
            'column_count' => 3,
            'headers' => ['PENYULANG', 'PEMUTUS', 'POSKO PIC'],
            'skip_leading_blanks' => true,
        ];
    }

    private function keypointDefinition(): array
    {
        return [
            'sheet' => 'KEYPOINT_RAW',
            'column_count' => 5,
            'headers' => ['PENYULANG', 'JENIS', 'NAMA', 'KOORDINAT', 'NOTE'],
        ];
    }

    // ─── Leading blank rows ──────────────────────────────────────

    public function test_leading_blank_rows_are_skipped_for_section_pic(): void
    {
        $allRows = [
            ['', '', ''],       // blank row 1
            ['', ''],           // blank row 2
            ['PENYULANG', 'PEMUTUS', 'POSKO PIC'],  // header
            ['JAIPONG', 'REC JABO', 'SRIBHAWONO'],  // data
        ];

        $result = $this->command->callResolveHeader($allRows, $this->sectionPicDefinition());

        $this->assertNull($result['error'], "Expected no error, got: {$result['error']}");
        $this->assertSame(2, $result['header_row_index']);
        $this->assertCount(1, $result['data_rows']);
    }

    public function test_no_leading_blank_rows_works_normally(): void
    {
        $allRows = [
            ['PENYULANG', 'PEMUTUS', 'POSKO PIC'],
            ['JAIPONG', 'REC JABO', 'SRIBHAWONO'],
        ];

        $result = $this->command->callResolveHeader($allRows, $this->sectionPicDefinition());

        $this->assertNull($result['error']);
        $this->assertSame(0, $result['header_row_index']);
        $this->assertCount(1, $result['data_rows']);
    }

    // ─── First non-empty row wrong header → FAIL ─────────────────

    public function test_first_nonempty_row_wrong_header_fails(): void
    {
        $allRows = [
            ['', '', ''],                         // blank row (skipped)
            ['WRONG', 'HEADERS', 'HERE'],         // non-empty but wrong
            ['PENYULANG', 'PEMUTUS', 'POSKO PIC'],// correct header (should NOT be reached)
            ['JAIPONG', 'REC JABO', 'SRIBHAWONO'],
        ];

        $result = $this->command->callResolveHeader($allRows, $this->sectionPicDefinition());

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('tidak sesuai', $result['error']);
    }

    public function test_first_nonempty_row_with_data_instead_of_header_fails(): void
    {
        $allRows = [
            [''],                                  // blank row (skipped)
            ['JAIPONG', 'REC JABO', 'SRIBHAWONO'],// data, not header
            ['', 'LBSM KARYA TANI', 'PUGUNG'],
        ];

        $result = $this->command->callResolveHeader($allRows, $this->sectionPicDefinition());

        $this->assertNotNull($result['error']);
    }

    // ─── Without skip_leading_blanks: leading blank NOT skipped ───

    public function test_without_skip_flag_leading_blank_treated_as_header(): void
    {
        $allRows = [
            ['', '', '', '', ''],                     // blank row → treated as header
            ['PENYULANG', 'JENIS', 'NAMA', 'KOORDINAT', 'NOTE'],
            ['JAIPONG', 'FCO', 'FCO-01', '-5.21, 105.46', ''],
        ];

        // KEYPOINT_RAW tidak memiliki skip_leading_blanks
        $result = $this->command->callResolveHeader($allRows, $this->keypointDefinition());

        // Harus gagal karena baris pertama (blank) dianggap header dan tidak cocok
        $this->assertNotNull($result['error']);
    }

    // ─── Trailing empty header columns → accepted ────────────────

    public function test_trailing_empty_header_columns_are_trimmed(): void
    {
        $allRows = [
            ['PENYULANG', 'PEMUTUS', 'POSKO PIC', '', ''],  // trailing empties
            ['JAIPONG', 'REC JABO', 'SRIBHAWONO', '', ''],
        ];

        $result = $this->command->callResolveHeader($allRows, $this->sectionPicDefinition());

        $this->assertNull($result['error'], "Expected no error, got: {$result['error']}");
        $this->assertCount(1, $result['data_rows']);
    }

    public function test_trailing_empty_columns_trimmed_for_keypoint(): void
    {
        $allRows = [
            ['PENYULANG', 'JENIS', 'NAMA', 'KOORDINAT', 'NOTE', '', ''],
            ['JAIPONG', 'FCO', 'FCO-01', '-5.21, 105.46', '', '', ''],
        ];

        $result = $this->command->callResolveHeader($allRows, $this->keypointDefinition());

        $this->assertNull($result['error'], "Expected no error, got: {$result['error']}");
    }

    // ─── Trailing non-empty header column → FAIL ─────────────────

    public function test_trailing_nonempty_header_column_fails(): void
    {
        $allRows = [
            ['PENYULANG', 'PEMUTUS', 'POSKO PIC', 'EXTRA_COL'],
            ['JAIPONG', 'REC JABO', 'SRIBHAWONO', 'unexpected'],
        ];

        $result = $this->command->callResolveHeader($allRows, $this->sectionPicDefinition());

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Jumlah kolom tidak sesuai', $result['error']);
    }

    public function test_mixed_trailing_columns_with_any_nonempty_fails(): void
    {
        $allRows = [
            ['PENYULANG', 'PEMUTUS', 'POSKO PIC', '', 'OOPS'],
            ['JAIPONG', 'REC JABO', 'SRIBHAWONO', '', ''],
        ];

        $result = $this->command->callResolveHeader($allRows, $this->sectionPicDefinition());

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Jumlah kolom tidak sesuai', $result['error']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('OOPS', $result['warnings'][0]);
    }

    // ─── Edge case: all blank rows with skip → error ─────────────

    public function test_all_blank_rows_with_skip_returns_error(): void
    {
        $allRows = [
            ['', ''],
            [''],
            ['', '', ''],
        ];

        $result = $this->command->callResolveHeader($allRows, $this->sectionPicDefinition());

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Tidak ditemukan header', $result['error']);
    }

    // ─── Column count mismatch (too few) ─────────────────────────

    public function test_too_few_columns_fails(): void
    {
        $allRows = [
            ['PENYULANG', 'PEMUTUS'],  // missing POSKO PIC
            ['JAIPONG', 'REC JABO'],
        ];

        $result = $this->command->callResolveHeader($allRows, $this->sectionPicDefinition());

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Jumlah kolom tidak sesuai', $result['error']);
    }
}
