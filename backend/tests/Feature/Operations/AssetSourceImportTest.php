<?php

namespace Tests\Feature\Operations;

use App\Models\AssetSourceRow;
use App\Models\Posko;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\GoogleSheetValues;
use ArrayObject;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * Import ASSET_RAW 10 kolom lewat `sources:import`. Google Sheets diganti tiruan GoogleSheetValues
 * (tanpa jaringan); database SQLite uji. Data simulasi.
 */
class AssetSourceImportTest extends OperationsTestCase
{
    private const ASSET = 'ASSET_RAW!A:ZZ';

    private const VEGETASI = 'VEGETASI_RAW!A:ZZ';

    private const SECTION = 'SECTION_PIC_RAW!A:ZZ';

    private const HEADER = ['ID_ASET', 'NAMA_ASET', 'JENIS_ASET', 'PENYULANG', 'LATITUDE', 'LONGITUDE', 'STATUS', 'Foto', 'Section', 'Posko'];

    private const VEGETASI_HEADER = [
        'NO', 'NAMA POHON', 'UP3', 'ULP', 'PENYULANG', 'ID JTM', 'ID TIANG', 'NAMA TIANG', 'JENIS POHON',
        'PERTUMBUHAN POHON', 'JARAK AMAN', 'JARAK BAHAYA', 'KONDISI', 'SECTION', 'EAM', 'LATITUDE', 'LONGITUDE',
        'JARAK POHON', 'JARAK POHON SAAT INI', 'IZIN PADAM', 'IZIN TEBANG BAWAH', 'TANGGAL UPDATE', 'TANGGAL PERIKSA',
        'TANGGAL PERKIRAAN BAHAYA', 'USER PEMBUAT', 'USER UPDATE', 'KETERANGAN', 'ID POHON',
    ];

    private string $credentialsPath;

    private ArrayObject $reads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'asset-cred');
        file_put_contents($this->credentialsPath, '{}');
        config(['services.google_sheets.credentials_path' => $this->credentialsPath, 'services.google_sheets.spreadsheet_id' => 'sheet-test']);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsPath);

        parent::tearDown();
    }

    public function test_ten_column_asset_sheet_is_imported_with_every_source_value_intact(): void
    {
        $foto = 'https://example.test/foto/'.str_repeat('x', 400).'.jpg';
        $this->fakeSheets([self::ASSET => [
            self::HEADER,
            ['17GD01', 'PG0108', 'GARDU', 'BAUNG 2', '-5.19833', '105.50047', 'VALID', $foto, 'LBSM TRISINAR', 'PUGUNG'],
            // Google Sheets memotong sel kosong di akhir baris; baris ini tidak punya Foto/Section/Posko.
            ['17TG02', 'F.REOG.000001', 'TIANG_TM', 'REOG', '-5,20000', '105,70000', 'VALID'],
            ['', '', '', '', '', '', '', '', '', ''],
            ['17TG03', 'F.REOG.000002', 'TIANG_TM', 'REOG', '-5.21', '105.71', 'VALID', '', 'SEC TANPA POSKO'],
        ]]);

        [$code, $output] = $this->import(['--only' => ['ASSET_RAW']]);

        $this->assertSame(0, $code, $output);
        $this->assertSame([self::ASSET], $this->reads->getArrayCopy());
        $rows = AssetSourceRow::query()->orderBy('source_row_number')->get();
        $this->assertSame(3, $rows->count());
        $this->assertSame(
            ['17GD01', 'PG0108', 'GARDU', 'BAUNG 2', '-5.19833', '105.50047', 'VALID', $foto, 'LBSM TRISINAR', 'PUGUNG', 2],
            $this->values($rows[0]),
            'Sepuluh kolom tersimpan apa adanya, termasuk URL foto panjang.',
        );
        $this->assertSame(
            ['17TG02', 'F.REOG.000001', 'TIANG_TM', 'REOG', '-5,20000', '105,70000', 'VALID', null, null, null, 3],
            $this->values($rows[1]),
            'Sel akhir yang dipotong Google tersimpan NULL; desimal koma tidak diubah.',
        );
        $this->assertSame([null, 'SEC TANPA POSKO', null, 5], [$rows[2]->foto_raw, $rows[2]->section_raw, $rows[2]->posko_raw, $rows[2]->source_row_number]);
    }

    public function test_raw_section_and_posko_do_not_become_official_references(): void
    {
        DB::table('section_pic_source_rows')->insert(['penyulang_raw' => 'REOG', 'pemutus_raw' => 'LBSM GUNUNG MAS', 'posko_pic_raw' => 'PUGUNG', 'source_row_number' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $entries = fn () => collect(SectionPoskoMap::load()->entries())->map(fn (array $e) => [$e['section'], $e['penyulang'], $e['posko']?->nama_posko])->all();
        $before = [$entries(), Posko::query()->count(), DB::table('penyulangs')->count(), DB::table('posko_penyulang')->count()];
        $this->fakeSheets([self::ASSET => [
            self::HEADER,
            ['A1', 'TIANG 1', 'TIANG_TM', 'REOG', '-5.2', '105.7', 'VALID', '', 'SECTION LIAR', 'POSKO LIAR'],
            ['A2', 'TIANG 2', 'TIANG_TM', 'REOG', '-5.2', '105.7', 'VALID', '', 'LBSM GUNUNG MAS', 'POSKO LAIN'],
        ]]);

        [$code, $output] = $this->import(['--only' => ['ASSET_RAW']]);

        $this->assertSame(0, $code, $output);
        $this->assertSame(['SECTION LIAR', 'POSKO LIAR'], [AssetSourceRow::query()->where('id_aset_raw', 'A1')->value('section_raw'), AssetSourceRow::query()->where('id_aset_raw', 'A1')->value('posko_raw')]);
        $this->assertSame($before, [$entries(), Posko::query()->count(), DB::table('penyulangs')->count(), DB::table('posko_penyulang')->count()], 'Kepemilikan Section dan master tidak berubah oleh nilai mentah ASSET_RAW.');
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function invalidHeaders(): array
    {
        return [
            'format lama 7 kolom' => [array_slice(self::HEADER, 0, 7), 'Jumlah kolom tidak sesuai: expected 10, actual 7'],
            'kolom ke-11 berisi' => [[...self::HEADER, 'KOLOM BARU'], 'Jumlah kolom tidak sesuai: expected 10, actual 11'],
            'urutan kolom baru tertukar' => [['ID_ASET', 'NAMA_ASET', 'JENIS_ASET', 'PENYULANG', 'LATITUDE', 'LONGITUDE', 'STATUS', 'Foto', 'Posko', 'Section'], 'Header "ASSET_RAW" tidak sesuai.'],
            'nama kolom berubah' => [['ID_ASET', 'NAMA_ASET', 'JENIS_ASET', 'PENYULANG', 'LATITUDE', 'LONGITUDE', 'STATUS', 'FOTO', 'Section', 'Posko'], 'Header "ASSET_RAW" tidak sesuai.'],
        ];
    }

    /**
     * @param  list<string>  $header
     */
    #[DataProvider('invalidHeaders')]
    public function test_unexpected_headers_are_rejected_and_existing_staging_is_kept(array $header, string $message): void
    {
        AssetSourceRow::query()->insert(['id_aset_raw' => 'LAMA', 'source_row_number' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->fakeSheets([self::ASSET => [$header, ['A1', 'TIANG 1', 'TIANG_TM', 'REOG', '-5.2', '105.7', 'VALID', '', '', '']]]);

        [$code, $output] = $this->import(['--only' => ['ASSET_RAW'], '--replace' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString($message, $output);
        $this->assertSame(['LAMA'], AssetSourceRow::query()->pluck('id_aset_raw')->all(), 'Header salah tidak menghapus staging lama walau memakai --replace.');
    }

    public function test_trailing_empty_header_columns_are_still_accepted(): void
    {
        $this->fakeSheets([self::ASSET => [[...self::HEADER, '', ''], ['A1', 'TIANG 1', 'TIANG_TM', 'REOG', '-5.2', '105.7', 'VALID', '', 'SEC', 'PUGUNG', '', '']]]);

        [$code, $output] = $this->import(['--only' => ['ASSET_RAW']]);

        $this->assertSame(0, $code, $output);
        $this->assertSame(['SEC', 'PUGUNG'], [AssetSourceRow::query()->value('section_raw'), AssetSourceRow::query()->value('posko_raw')]);
    }

    public function test_vegetasi_and_section_importers_keep_working_and_are_not_touched_by_asset_import(): void
    {
        $vegetasi = array_map(fn (int $i) => "v{$i}", range(1, 28));
        $this->fakeSheets([
            self::ASSET => [self::HEADER, ['A1', 'TIANG 1', 'TIANG_TM', 'REOG', '-5.2', '105.7', 'VALID', 'foto.jpg', 'SEC', 'PUGUNG']],
            self::VEGETASI => [self::VEGETASI_HEADER, $vegetasi],
            self::SECTION => [[], ['PENYULANG', 'PEMUTUS', 'POSKO PIC'], ['REOG', 'LBSM GUNUNG MAS', 'PUGUNG']],
        ]);

        // Hanya ASSET_RAW: tab lain tidak dibaca dan stagingnya tetap kosong.
        [$code, $output] = $this->import(['--only' => ['ASSET_RAW']]);
        $this->assertSame(0, $code, $output);
        $this->assertSame([self::ASSET], $this->reads->getArrayCopy());
        $this->assertSame([0, 0], [DB::table('vegetasi_source_rows')->count(), DB::table('section_pic_source_rows')->count()]);

        // Vegetasi dan Section tetap memakai struktur masing-masing; aset tidak bertambah.
        [$code, $output] = $this->import(['--only' => ['VEGETASI_RAW', 'SECTION_PIC_RAW']]);
        $this->assertSame(0, $code, $output);
        $row = DB::table('vegetasi_source_rows')->sole();
        $this->assertSame(['v1', 'v14', 'v28'], [$row->no_raw, $row->section_raw, $row->id_pohon_raw]);
        $section = DB::table('section_pic_source_rows')->sole();
        $this->assertSame(['REOG', 'LBSM GUNUNG MAS', 'PUGUNG', 3], [$section->penyulang_raw, $section->pemutus_raw, $section->posko_pic_raw, (int) $section->source_row_number]);
        $this->assertSame(1, AssetSourceRow::query()->count());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function snapshotTabs(): array
    {
        return [
            'ASSET_RAW' => ['ASSET_RAW', 'asset_source_rows', 'id_aset_raw'],
            'VEGETASI_RAW' => ['VEGETASI_RAW', 'vegetasi_source_rows', 'id_pohon_raw'],
        ];
    }

    #[DataProvider('snapshotTabs')]
    public function test_import_without_replace_is_rejected_when_staging_has_rows(string $tab, string $table, string $idColumn): void
    {
        DB::table($table)->insert([$idColumn => 'LAMA-1', 'source_row_number' => 2, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $before = DB::table($table)->orderBy('id')->get()->toArray();
        $this->fakeSheets($this->validSheets());

        [$code, $output] = $this->import(['--only' => [$tab]]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Staging {$tab} sudah berisi 1 baris: import tanpa --replace ditolak", $output);
        $this->assertSame([], $this->reads->getArrayCopy(), 'Ditolak sebelum menghubungi Google Sheets.');
        $this->assertEquals($before, DB::table($table)->orderBy('id')->get()->toArray(), 'Data lama tidak berubah sama sekali.');
    }

    #[DataProvider('snapshotTabs')]
    public function test_explicit_replace_swaps_the_snapshot_without_duplicates(string $tab, string $table, string $idColumn): void
    {
        DB::table($table)->insert([$idColumn => 'LAMA-1', 'source_row_number' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->fakeSheets($this->validSheets());

        [$code, $output] = $this->import(['--only' => [$tab], '--replace' => true]);
        $this->assertSame(0, $code, $output);
        $first = DB::table($table)->pluck($idColumn)->all();
        $this->assertNotContains('LAMA-1', $first);
        $this->assertCount(1, $first);

        [$code, $output] = $this->import(['--only' => [$tab], '--replace' => true]);
        $this->assertSame(0, $code, $output);
        $this->assertSame($first, DB::table($table)->pluck($idColumn)->all(), 'Import ulang dengan --replace tidak menggandakan baris.');
    }

    #[DataProvider('snapshotTabs')]
    public function test_replace_keeps_old_rows_when_the_source_cannot_be_read(string $tab, string $table, string $idColumn): void
    {
        DB::table($table)->insert([$idColumn => 'LAMA-1', 'source_row_number' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->fakeSheets([$tab.'!A:ZZ' => new RuntimeException('Google Sheets tidak dapat dihubungi')]);

        [$code, $output] = $this->import(['--only' => [$tab], '--replace' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Google Sheets tidak dapat dihubungi', $output);
        $this->assertSame(['LAMA-1'], DB::table($table)->pluck($idColumn)->all());
    }

    public function test_full_run_rejects_filled_tabs_imports_empty_tabs_and_never_duplicates(): void
    {
        AssetSourceRow::query()->insert(['id_aset_raw' => 'LAMA-1', 'source_row_number' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->fakeSheets($this->validSheets());

        [$code, $output] = $this->import([]);

        $this->assertSame(1, $code, 'Satu tab ditolak membuat command gagal walau tab lain berhasil.');
        $this->assertStringContainsString('Staging ASSET_RAW sudah berisi 1 baris', $output);
        $this->assertSame(['LAMA-1'], AssetSourceRow::query()->pluck('id_aset_raw')->all());
        $this->assertSame([1, 1], [DB::table('vegetasi_source_rows')->count(), DB::table('section_pic_source_rows')->count()], 'Tab yang masih kosong tetap dapat diimport tanpa --replace.');

        // Ulangi tanpa --replace: ketiga tab kini berisi, semuanya ditolak, dan tidak ada baris ganda.
        [$code, $output] = $this->import([]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Staging VEGETASI_RAW sudah berisi 1 baris', $output);
        $this->assertStringContainsString('SECTION_PIC_RAW adalah snapshot satu tab dan staging sudah berisi', $output);
        $this->assertSame([1, 1, 1], [AssetSourceRow::query()->count(), DB::table('vegetasi_source_rows')->count(), DB::table('section_pic_source_rows')->count()]);
    }

    public function test_guard_on_one_tab_does_not_block_section_import(): void
    {
        DB::table('asset_source_rows')->insert(['id_aset_raw' => 'LAMA-1', 'source_row_number' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vegetasi_source_rows')->insert(['id_pohon_raw' => 'LAMA-2', 'source_row_number' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->fakeSheets($this->validSheets());

        [$code, $output] = $this->import(['--only' => ['SECTION_PIC_RAW']]);
        $this->assertSame(0, $code, $output);
        $this->assertSame(1, DB::table('section_pic_source_rows')->count());

        [$code, $output] = $this->import(['--only' => ['SECTION_PIC_RAW'], '--replace' => true]);
        $this->assertSame(0, $code, $output);
        $this->assertSame(1, DB::table('section_pic_source_rows')->count());
        $this->assertSame([1, 1], [DB::table('asset_source_rows')->count(), DB::table('vegetasi_source_rows')->count()]);
    }

    public function test_replace_is_never_run_automatically(): void
    {
        Artisan::call('schedule:list');
        $schedule = Artisan::output();
        $this->assertStringContainsString('apkuk:sync', $schedule, 'Daftar jadwal benar-benar terbaca.');
        $this->assertStringNotContainsString('sources:import', $schedule);
        $this->assertStringNotContainsString('sources:import', (string) file_get_contents(base_path('railway-sync.sh')));

        foreach ((array) glob(app_path('{Console/Commands,Http/Controllers/Admin,Services}/*.php'), GLOB_BRACE) as $file) {
            if (basename($file) !== 'ImportSourceSheets.php') {
                $this->assertDoesNotMatchRegularExpression('/(Artisan::call|->call)\(\s*[\'"]sources:import/', (string) file_get_contents($file), $file);
            }
        }
    }

    public function test_migration_is_additive_and_keeps_existing_rows(): void
    {
        $migration = require database_path('migrations/2026_11_02_000001_add_foto_section_posko_to_asset_source_rows_table.php');
        $this->assertTrue(Schema::hasColumns('asset_source_rows', ['foto_raw', 'section_raw', 'posko_raw']));
        AssetSourceRow::query()->insert(['id_aset_raw' => 'A1', 'status_raw' => 'VALID', 'foto_raw' => 'foto.jpg', 'section_raw' => 'SEC', 'posko_raw' => 'PUGUNG', 'source_row_number' => 2, 'created_at' => now(), 'updated_at' => now()]);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('asset_source_rows', 'foto_raw'));
        $this->assertSame(['A1', 'VALID'], [AssetSourceRow::query()->value('id_aset_raw'), AssetSourceRow::query()->value('status_raw')], 'Baris dan kolom lama tetap ada.');

        $migration->up();
        $this->assertTrue(Schema::hasColumns('asset_source_rows', ['foto_raw', 'section_raw', 'posko_raw']));
        $this->assertSame(['A1', null], [AssetSourceRow::query()->value('id_aset_raw'), AssetSourceRow::query()->value('foto_raw')]);
    }

    /**
     * @return list<mixed>
     */
    private function values(AssetSourceRow $row): array
    {
        return [$row->id_aset_raw, $row->nama_aset_raw, $row->jenis_aset_raw, $row->penyulang_raw, $row->latitude_raw, $row->longitude_raw, $row->status_raw, $row->foto_raw, $row->section_raw, $row->posko_raw, $row->source_row_number];
    }

    /**
     * @return array<string, array<int, array<int, string>>>
     */
    private function validSheets(): array
    {
        return [
            self::ASSET => [self::HEADER, ['BARU-1', 'TIANG 1', 'TIANG_TM', 'REOG', '-5.2', '105.7', 'VALID']],
            self::VEGETASI => [self::VEGETASI_HEADER, array_map(fn (int $i) => $i === 28 ? 'BARU-2' : "v{$i}", range(1, 28))],
            self::SECTION => [['PENYULANG', 'PEMUTUS', 'POSKO PIC'], ['REOG', 'LBSM GUNUNG MAS', 'PUGUNG']],
        ];
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
        $this->reads->exchangeArray([]);
        $code = Artisan::call('sources:import', $options);

        return [$code, Artisan::output()];
    }
}
