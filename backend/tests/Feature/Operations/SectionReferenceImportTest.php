<?php

namespace Tests\Feature\Operations;

use App\Models\ApkukGenerationRun;
use App\Models\PasilaSession;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Services\Apkuk\Generation\ApkukShiftGenerator;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\GoogleSheetValues;
use App\Services\Pasila\PasilaShiftOpener;
use App\Services\ShiftService;
use ArrayObject;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * Kesiapan menerima referensi Section resmi lewat `sources:import --only=SECTION_PIC_RAW --replace`.
 * Google Sheets diganti tiruan GoogleSheetValues (tanpa jaringan); database SQLite uji. Data simulasi.
 */
class SectionReferenceImportTest extends OperationsTestCase
{
    private const RANGE = 'SECTION_PIC_RAW!A:ZZ';

    private const HEADER = ['PENYULANG', 'PEMUTUS', 'POSKO PIC'];

    private string $credentialsPath;

    /** Range yang dibaca tiruan Google Sheets. */
    private ArrayObject $reads;

    private Posko $wayJepara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'sec-cred');
        file_put_contents($this->credentialsPath, '{}');
        config(['services.google_sheets.credentials_path' => $this->credentialsPath, 'services.google_sheets.spreadsheet_id' => 'sheet-test']);
        $this->wayJepara = Posko::create(['kode_posko' => 'WAY JEPARA', 'nama_posko' => 'WAY JEPARA']);
        Penyulang::create(['kode_penyulang' => 'BEDANA', 'nama_penyulang' => 'BEDANA']);

        // Referensi lama (hasil import sebelumnya) dan staging tab lain yang tidak boleh tersentuh.
        foreach ([['REOG', 'LBSM GUNUNG MAS', 'PUGUNG'], [null, 'REC BRAJA DEWA', 'WAY JEPARA']] as $i => [$p, $s, $k]) {
            DB::table('section_pic_source_rows')->insert(['penyulang_raw' => $p, 'pemutus_raw' => $s, 'posko_pic_raw' => $k, 'source_row_number' => $i + 2, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('asset_source_rows')->insert(['id_aset_raw' => 'ASET-1', 'nama_aset_raw' => 'TIANG 1', 'source_row_number' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vegetasi_source_rows')->insert(['id_pohon_raw' => 'POHON-1', 'penyulang_raw' => 'REOG', 'source_row_number' => 2, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsPath);

        parent::tearDown();
    }

    public function test_only_section_tab_is_read_and_replaced_while_asset_and_vegetation_stay_untouched(): void
    {
        $others = $this->otherStaging();
        $this->fakeSheets([self::RANGE => [[], [], self::HEADER, ['REOG', 'LBSM GUNUNG MAS', 'PUGUNG'], ['', 'REC BRAJA DEWA', 'WAY JEPARA'], ['BEDANA', 'LBSM BEDANA', 'PUGUNG'], ['', '', '']]]);

        [$code, $output] = $this->import(['--only' => ['SECTION_PIC_RAW'], '--replace' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertSame([self::RANGE], $this->reads->getArrayCopy(), 'Hanya tab SECTION_PIC_RAW yang dibaca.');
        $this->assertSame($others, $this->otherStaging(), 'Staging ASSET_RAW dan VEGETASI_RAW tidak dihapus atau diubah.');
        $this->assertSame([
            ['REOG', 'LBSM GUNUNG MAS', 'PUGUNG', 4],
            [null, 'REC BRAJA DEWA', 'WAY JEPARA', 5],
            ['BEDANA', 'LBSM BEDANA', 'PUGUNG', 6],
        ], $this->sectionRows(), 'Tiga kolom tersimpan apa adanya; nomor baris sumber memperhitungkan baris kosong sebelum header.');
        $this->assertStringContainsString('Section terverifikasi: sebelum 2, sesudah 3.', $output);
        $this->assertSame(['LBSM BEDANA', 'LBSM GUNUNG MAS'], array_column(SectionPoskoMap::load()->sectionsForPosko($this->posko->id), 'section'));
    }

    public function test_reimporting_the_same_data_with_replace_does_not_duplicate(): void
    {
        $this->fakeSheets([self::RANGE => [self::HEADER, ['REOG', 'LBSM GUNUNG MAS', 'PUGUNG'], ['', 'REC BRAJA DEWA', 'WAY JEPARA']]]);

        $this->import(['--only' => ['SECTION_PIC_RAW'], '--replace' => true]);
        $first = $this->sectionRows();
        [$code] = $this->import(['--only' => ['SECTION_PIC_RAW'], '--replace' => true]);

        $this->assertSame(0, $code);
        $this->assertSame($first, $this->sectionRows());
        $this->assertSame(2, DB::table('section_pic_source_rows')->count());
    }

    public function test_section_import_without_replace_is_refused_once_staging_has_rows(): void
    {
        $before = $this->sectionRows();
        $this->fakeSheets([self::RANGE => [self::HEADER, ['BEDANA', 'LBSM BEDANA', 'PUGUNG'], ['', 'REC LAIN', 'WAY JEPARA']]]);

        [$code, $output] = $this->import(['--only' => ['SECTION_PIC_RAW']]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('snapshot satu tab dan staging sudah berisi: jalankan dengan --replace', $output);
        $this->assertSame($before, $this->sectionRows(), 'Tanpa --replace baris baru akan bercampur dengan baris lama dan merusak fill-down PENYULANG.');

        // Import pertama ke staging kosong tetap boleh tanpa --replace.
        DB::table('section_pic_source_rows')->delete();
        [$code] = $this->import(['--only' => ['SECTION_PIC_RAW']]);
        $this->assertSame(0, $code);
        $this->assertSame(2, DB::table('section_pic_source_rows')->count());
    }

    /**
     * @return array<string, array{array<int, mixed>|Throwable, string}>
     */
    public static function rejectedSheets(): array
    {
        return [
            'urutan header salah' => [[['PEMUTUS', 'PENYULANG', 'POSKO PIC'], ['LBSM X', 'REOG', 'PUGUNG']], 'tidak sesuai'],
            'header huruf kecil' => [[['Penyulang', 'Pemutus', 'Posko PIC'], ['REOG', 'LBSM X', 'PUGUNG']], 'tidak sesuai'],
            'kolom tambahan berisi' => [[['PENYULANG', 'PEMUTUS', 'POSKO PIC', 'CATATAN'], ['REOG', 'LBSM X', 'PUGUNG', 'baru']], 'Jumlah kolom tidak sesuai'],
            'tab kosong' => [[], 'kosong'],
            'gagal membaca Google' => [new RuntimeException('403 Forbidden'), 'Gagal membaca sheet'],
            'kolom tertukar, tanpa Section terverifikasi' => [[self::HEADER, ['REOG', 'PUGUNG', 'LBSM GUNUNG MAS'], ['', 'WAY JEPARA', 'REC BRAJA DEWA']], 'tidak menghasilkan satu pun Section terverifikasi; 2 referensi lama dipertahankan'],
        ];
    }

    #[DataProvider('rejectedSheets')]
    public function test_rejected_or_unusable_sheet_keeps_the_old_section_references(array|Throwable $sheet, string $message): void
    {
        $before = $this->sectionRows();
        $this->fakeSheets([self::RANGE => $sheet]);

        [$code, $output] = $this->import(['--only' => ['SECTION_PIC_RAW'], '--replace' => true]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString($message, $output);
        $this->assertSame($before, $this->sectionRows());
        $this->assertSame('PUGUNG', SectionPoskoMap::load()->entry('LBSM GUNUNG MAS')['posko']?->nama_posko);
    }

    public function test_header_only_tab_imports_nothing_and_keeps_old_references(): void
    {
        $before = $this->sectionRows();
        $this->fakeSheets([self::RANGE => [self::HEADER, ['', '', '']]]);

        [, $output] = $this->import(['--only' => ['SECTION_PIC_RAW'], '--replace' => true]);

        $this->assertStringContainsString('Tidak ada data untuk diimport', $output);
        $this->assertSame($before, $this->sectionRows());
    }

    public function test_imported_change_is_used_immediately_by_map_pasila_and_apkuk_generator(): void
    {
        config(['pasila.opener.enabled' => true]);
        $this->travelTo(Carbon::create(2026, 10, 29, 8, 5, 0, ShiftService::TIMEZONE));
        $opener = app(PasilaShiftOpener::class);
        $opener->openCurrent(PasilaSession::TRIGGER_CLI);
        $pugungSession = PasilaSession::query()->where('posko_id', $this->posko->id)->sole();
        $this->assertSame('LBSM GUNUNG MAS', $pugungSession->section_name);
        $this->apkuk(['section' => 'LBSM GUNUNG MAS', 'no_tiang' => 'F.REOG.900001']);
        $this->assertSame(1, $this->previewCandidates('PUGUNG'));

        // Data resmi: LBSM GUNUNG MAS kini milik WAY JEPARA; PUGUNG memegang LBSM BEDANA di penyulang lain.
        $this->fakeSheets([self::RANGE => [self::HEADER, ['REOG', 'LBSM GUNUNG MAS', 'WAY JEPARA'], ['', 'REC BRAJA DEWA', 'WAY JEPARA'], ['BEDANA', 'LBSM BEDANA', 'PUGUNG']]]);
        [$code, $output] = $this->import(['--only' => ['SECTION_PIC_RAW'], '--replace' => true]);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Berganti Posko: LBSM GUNUNG MAS (REOG), dari PUGUNG ke WAY JEPARA', $output);

        // SectionPoskoMap membaca staging baru tanpa cache.
        $this->assertSame('WAY JEPARA', SectionPoskoMap::load()->entry('LBSM GUNUNG MAS')['posko']?->nama_posko);

        // Generator B2: kandidat Section itu kini dialokasikan ke WAY JEPARA, bukan PUGUNG.
        $this->assertSame([0, 1], [$this->previewCandidates('PUGUNG'), $this->previewCandidates('WAY JEPARA')]);

        // PASILA: sesi lama tetap (riwayat), tetapi ditandai; Section milik Posko lain ditolak; Section baru diterima.
        $this->actingAs($this->admin)->get(route('admin.pasila.show', ['posko' => $this->posko, 'work_date' => '2026-10-29', 'shift_code' => 'A']))
            ->assertOk()
            ->assertSee('Referensi terkini mencatat Section ini milik Posko WAY JEPARA.')
            ->assertSee('data-owned-section="LBSM BEDANA"', false)
            ->assertDontSee('data-owned-section="LBSM GUNUNG MAS"', false);
        $this->actingAs($this->admin)->post(route('admin.pasila.section', $pugungSession), ['section' => 'REC BRAJA DEWA', 'reason' => 'Uji setelah import resmi'])
            ->assertSessionHasErrors('section');
        $this->assertStringContainsString('milik Posko WAY JEPARA, bukan Posko PUGUNG', session('errors')->first('section'));
        $this->actingAs($this->admin)->post(route('admin.pasila.section', $pugungSession), ['section' => 'LBSM BEDANA', 'reason' => 'Uji setelah import resmi'])
            ->assertSessionHasNoErrors();
        $this->assertSame('LBSM BEDANA', $pugungSession->refresh()->section_name);

        // Sesi shift berikutnya merekomendasikan dari referensi baru.
        $this->travelTo(Carbon::create(2026, 10, 29, 16, 5, 0, ShiftService::TIMEZONE));
        $opener->openCurrent(PasilaSession::TRIGGER_CLI);
        $next = PasilaSession::query()->whereHas('shiftInstance', fn ($q) => $q->where('shift_code', 'B'))->with('posko')->get()->mapWithKeys(fn ($s) => [$s->posko->nama_posko => $s->section_name])->all();
        $this->assertSame('LBSM BEDANA', $next['PUGUNG']);
        // WAY JEPARA belum pernah ditugaskan LBSM GUNUNG MAS, jadi rotasi memilihnya.
        $this->assertSame('LBSM GUNUNG MAS', $next['WAY JEPARA']);
    }

    public function test_importer_only_reads_through_the_read_only_sheet_reader(): void
    {
        $this->fakeSheets([self::RANGE => [self::HEADER, ['REOG', 'LBSM GUNUNG MAS', 'PUGUNG']]]);

        $this->import(['--only' => ['SECTION_PIC_RAW'], '--replace' => true]);

        $this->assertSame([self::RANGE], $this->reads->getArrayCopy());
        $importer = (string) file_get_contents(app_path('Console/Commands/ImportSourceSheets.php'));
        $this->assertStringNotContainsString('Google\\', $importer, 'Importer tidak membuat klien Google sendiri; hanya lewat GoogleSheetValues.');
        $reader = (string) file_get_contents(app_path('Services/GoogleSheetValues.php'));
        $this->assertStringContainsString('SPREADSHEETS_READONLY', $reader);
        $this->assertDoesNotMatchRegularExpression('/->(update|append|batchUpdate|clear)\(/', $reader);
    }

    /**
     * @param  array<string, array<int, mixed>|Throwable>  $byRange
     */
    private function fakeSheets(array $byRange): void
    {
        $this->reads = new ArrayObject;
        $this->app->instance(GoogleSheetValues::class, new class($byRange, $this->reads) extends GoogleSheetValues
        {
            public function __construct(private array $byRange, private ArrayObject $reads) {}

            public function get(string $credentialsPath, string $spreadsheetId, string $range, array $params = []): array
            {
                $this->reads[] = $range;
                $value = $this->byRange[$range] ?? [];
                if ($value instanceof Throwable) {
                    throw $value;
                }

                return $value;
            }
        });
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function import(array $options): array
    {
        $code = Artisan::call('sources:import', $options);

        return [$code, Artisan::output()];
    }

    /**
     * @return array<int, array{?string, ?string, ?string, int}>
     */
    private function sectionRows(): array
    {
        return DB::table('section_pic_source_rows')->orderBy('source_row_number')->orderBy('id')->get()
            ->map(fn ($r) => [$r->penyulang_raw, $r->pemutus_raw, $r->posko_pic_raw, (int) $r->source_row_number])->all();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function otherStaging(): array
    {
        return [
            'asset' => DB::table('asset_source_rows')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'vegetasi' => DB::table('vegetasi_source_rows')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function previewCandidates(string $posko): int
    {
        $run = app(ApkukShiftGenerator::class)->run('2026-10-29', 'C', ApkukGenerationRun::MODE_PREVIEW, ApkukGenerationRun::TRIGGER_CLI);

        return (int) (collect($run->summary)->firstWhere('posko', $posko)['eligible_candidates'] ?? 0);
    }
}
