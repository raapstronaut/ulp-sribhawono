<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\FieldTeam;
use App\Models\Inspection;
use App\Models\Posko;
use App\Models\User;
use App\Models\WorkEscalation;
use App\Models\WorkOrder;
use App\Models\WorkOrderMedia;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderStatusLog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * B1.5 — retry API lapangan: pengiriman ulang dengan client_request_id sama tidak membuat
 * transaksi atau media ganda, termasuk saat dua request berbalapan. Retry sah dari outbox mengirim
 * payload dan file yang sama persis (OFFLINE_SYNC_SPEC §4); kunci sama dengan isi berbeda ditolak.
 *
 * SQLite in-memory tidak menjalankan request paralel. Balapan disimulasikan secara deterministik:
 * record "pemenang" (isi identik) disisipkan tepat setelah pemeriksaan kunci request yang kalah dan
 * sebelum request itu menulis, sehingga request kalah melewati jalur unique violation / konflik
 * status / validasi yang sama seperti pada PostgreSQL.
 */
class MobileRetryResilienceTest extends OperationsTestCase
{
    private User $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->fieldAccount($this->poskoTeam, 'posko_retry');
    }

    public function test_identical_retry_returns_same_report_without_new_transaction_or_media(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $this->actingAs($this->account, 'sanctum');
        $photos = $this->photos();

        $first = $this->report($wo, 'retry-1', $photos)->assertCreated()->assertJsonPath('meta.idempotent_replay', false);
        $logs = WorkOrderStatusLog::query()->count();
        $files = Storage::disk('local')->allFiles();

        // Timeout di sisi HP: respons hilang, outbox mengirim ulang payload dan file yang sama.
        $second = $this->report($wo, 'retry-1', $photos)->assertCreated()->assertJsonPath('meta.idempotent_replay', true);

        $this->assertSame($first->json('data.report_id'), $second->json('data.report_id'));
        $this->assertSame(1, WorkOrderReport::query()->count());
        $this->assertSame(2, WorkOrderMedia::query()->count());
        $this->assertSame($files, Storage::disk('local')->allFiles(), 'Foto pengiriman ulang tidak disimpan ganda.');
        $this->assertSame($logs, WorkOrderStatusLog::query()->count());
        $second->assertJsonPath('data.work_order.status', 'SELESAI');
    }

    public function test_retry_with_different_formatting_of_the_same_content_is_still_a_replay(): void
    {
        $second = $this->poskoTeam->personnel()->create(['name' => 'Ani Posko']);
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $this->actingAs($this->account, 'sanctum');
        $before = $this->photo('before.jpg');
        $afterA = $this->photo('after-a.jpg');
        $afterB = UploadedFile::fake()->image('after-b.jpg', 60, 60);

        $this->report($wo, 'fmt-1', ['photos_before' => [$before], 'photos_after' => [$afterA, $afterB]], [
            'installed_quantity' => 3, 'latitude' => -5.2, 'longitude' => 105.7, 'gps_accuracy_m' => 4.5,
            'notes' => 'Terpasang di JTR', 'occurred_at' => '2026-10-28 08:30:00',
            'personnel_ids' => [$this->poskoPerson->id, $second->id], 'personnel_names' => ['Pak Manual'],
        ])->assertCreated();

        // Isi sama, bentuk penulisan berbeda: angka sebagai teks, urutan petugas/foto, instan waktu dengan offset.
        $this->report($wo, 'fmt-1', ['photos_before' => [$before], 'photos_after' => [$afterB, $afterA]], [
            'installed_quantity' => '3', 'latitude' => '-5.2000000', 'longitude' => '105.70', 'gps_accuracy_m' => '4.50',
            'notes' => ' Terpasang di JTR ', 'occurred_at' => '2026-10-28T01:30:00Z',
            'personnel_ids' => [$second->id, $this->poskoPerson->id], 'personnel_names' => [' Pak Manual '],
        ])->assertCreated()->assertJsonPath('meta.idempotent_replay', true);

        $this->assertSame(1, WorkOrderReport::query()->count());
        $this->assertSame(3, WorkOrderMedia::query()->count());
    }

    public function test_same_key_with_changed_notes_photos_personnel_or_time_is_rejected_without_losing_data(): void
    {
        $other = $this->poskoTeam->personnel()->create(['name' => 'Ani Posko']);
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $this->actingAs($this->account, 'sanctum');
        $photos = $this->photos();
        $this->report($wo, 'ubah-1', $photos, ['notes' => 'Catatan asli', 'occurred_at' => '2026-10-28 08:30:00'])->assertCreated();
        $files = Storage::disk('local')->allFiles();

        $cases = [
            'notes' => [$photos, ['notes' => 'Catatan lain', 'occurred_at' => '2026-10-28 08:30:00']],
            'photos' => [['photos_before' => $photos['photos_before'], 'photos_after' => [UploadedFile::fake()->image('lain.jpg', 70, 70)]], ['notes' => 'Catatan asli', 'occurred_at' => '2026-10-28 08:30:00']],
            'personnel' => [$photos, ['notes' => 'Catatan asli', 'occurred_at' => '2026-10-28 08:30:00', 'personnel_ids' => [$other->id]]],
            'occurred_at' => [$photos, ['notes' => 'Catatan asli', 'occurred_at' => '2026-10-28 08:45:00']],
            'latitude' => [$photos, ['notes' => 'Catatan asli', 'occurred_at' => '2026-10-28 08:30:00', 'latitude' => -5.25]],
        ];
        foreach ($cases as $field => [$files2, $overrides]) {
            $this->report($wo, 'ubah-1', $files2, $overrides)->assertUnprocessable()
                ->assertJsonPath('errors.client_request_id.0', "client_request_id sudah dipakai untuk permintaan dengan isi berbeda ({$field}). Gunakan ID baru untuk pengiriman baru.");
        }

        $this->assertSame(1, WorkOrderReport::query()->count());
        $this->assertSame('Catatan asli', WorkOrderReport::query()->value('notes'));
        $this->assertSame($files, Storage::disk('local')->allFiles(), 'Foto asli tetap; foto kiriman yang ditolak tidak disimpan.');
    }

    public function test_occurred_at_is_stored_as_the_correct_utc_instant(): void
    {
        $this->actingAs($this->account, 'sanctum');
        foreach (['wib-naif' => '2026-10-28 08:30:00', 'wib-offset' => '2026-10-28T08:30:00+07:00', 'utc' => '2026-10-28T01:30:00Z'] as $key => $value) {
            $wo = $this->createApkukWorkOrder($this->apkuk());
            $this->actingAs($this->account, 'sanctum');
            $this->report($wo, $key, $this->photos(), ['occurred_at' => $value])->assertCreated();
            // Nilai mentah kolom (UTC), bukan hasil cast Eloquent.
            $this->assertSame('2026-10-28 01:30:00', DB::table('work_order_reports')->where('client_request_id', $key)->value('occurred_at'), $key);
        }
    }

    public function test_start_retry_is_idempotent_and_finished_work_still_conflicts(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $this->actingAs($this->account, 'sanctum');

        $this->postJson("/api/v1/mobile/work-orders/{$wo->id}/start")->assertOk()->assertJsonPath('data.status', 'DIKERJAKAN');
        $this->postJson("/api/v1/mobile/work-orders/{$wo->id}/start")->assertOk()->assertJsonPath('data.status', 'DIKERJAKAN');
        $this->assertSame(1, WorkOrderStatusLog::query()->where('work_order_id', $wo->id)->where('action', WorkOrderStatusLog::ACTION_STARTED)->count());

        $wo->forceFill(['status' => WorkOrderStatus::Selesai])->save();
        $this->postJson("/api/v1/mobile/work-orders/{$wo->id}/start")->assertStatus(409);
    }

    public function test_reused_key_for_other_work_or_different_payload_is_rejected_with_422(): void
    {
        $first = $this->createApkukWorkOrder($this->apkuk());
        $second = $this->createApkukWorkOrder($this->apkuk());
        $this->actingAs($this->account, 'sanctum');
        $photos = $this->photos();
        $this->report($first, 'kunci-1', $photos)->assertCreated();

        $this->report($second, 'kunci-1', $photos)->assertUnprocessable()
            ->assertJsonPath('errors.client_request_id.0', 'client_request_id sudah dipakai untuk WO lain.');
        $this->report($first, 'kunci-1', $photos, ['installed_quantity' => 2])->assertUnprocessable()
            ->assertJsonPath('errors.client_request_id.0', 'client_request_id sudah dipakai untuk permintaan dengan isi berbeda (installed_quantity). Gunakan ID baru untuk pengiriman baru.');
        $this->assertSame(1, WorkOrderReport::query()->count());
        $this->assertSame(WorkOrderStatus::Ditugaskan, $second->refresh()->status);

        $reschedule = ['client_request_id' => 'rs-kunci', 'reason_code' => 'CUACA', 'note' => 'Hujan deras', 'proposed_work_date' => '2026-10-29', 'proposed_shift_code' => 'A'];
        $this->postJson("/api/v1/mobile/work-orders/{$second->id}/reschedules", $reschedule)->assertCreated()->assertJsonPath('meta.idempotent_replay', false);
        $this->postJson("/api/v1/mobile/work-orders/{$second->id}/reschedules", $reschedule)->assertCreated()->assertJsonPath('meta.idempotent_replay', true);
        $this->postJson("/api/v1/mobile/work-orders/{$second->id}/reschedules", ['proposed_work_date' => '2026-10-30'] + $reschedule)
            ->assertUnprocessable()->assertJsonValidationErrors('client_request_id');
        $this->postJson("/api/v1/mobile/work-orders/{$second->id}/reschedules", ['note' => 'Hujan deras dan banjir'] + $reschedule)
            ->assertUnprocessable()->assertJsonPath('errors.client_request_id.0', 'client_request_id sudah dipakai untuk permintaan dengan isi berbeda (note). Gunakan ID baru untuk pengiriman baru.');
        $this->assertSame(1, $second->reschedules()->count());
    }

    public function test_racing_duplicate_hits_unique_index_and_loser_media_is_discarded(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $photos = $this->photos();
        $winner = $this->injectWinnerAfterKeyCheck('work_order_reports', 'race-1', fn () => $this->insertReport($wo, 'race-1', $photos));
        $this->actingAs($this->account, 'sanctum');

        $this->report($wo, 'race-1', $photos)->assertCreated()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.report_id', $winner());

        $this->assertSame(1, WorkOrderReport::query()->count());
        $this->assertSame(2, WorkOrderMedia::query()->count(), 'Hanya media milik laporan pemenang.');
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Foto request yang kalah dibuang, tidak menjadi media yatim.');
    }

    public function test_racing_duplicate_that_already_finished_the_work_order_is_replayed_not_409(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $photos = $this->photos();
        $winner = $this->injectWinnerAfterKeyCheck('work_order_reports', 'race-2', function () use ($wo, $photos): string {
            $id = $this->insertReport($wo, 'race-2', $photos);
            DB::table('work_orders')->where('id', $wo->id)->update(['status' => WorkOrderStatus::Selesai->value]);

            return $id;
        });
        $this->actingAs($this->account, 'sanctum');

        $this->report($wo, 'race-2', $photos)->assertCreated()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.report_id', $winner());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_racing_escalation_returns_the_pending_request_instead_of_a_validation_error(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $winner = $this->injectWinnerAfterKeyCheck('work_escalations', 'esc-race', function () use ($wo): string {
            $id = (string) Str::uuid();
            DB::table('work_escalations')->insert([
                'id' => $id, 'source_work_order_id' => $wo->id, 'target_team_type' => 'HARSUS', 'target_work_type' => 'PEKERJAAN_KHUSUS',
                'reason_code' => 'BUTUH_PADAM', 'note' => 'Butuh padam', 'requested_by_field_team_id' => $this->poskoTeam->id,
                'requested_by_user_id' => $this->account->id, 'status' => WorkEscalation::STATUS_MENUNGGU_ACC, 'client_request_id' => 'esc-race',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });
        $this->actingAs($this->account, 'sanctum');

        $this->postJson("/api/v1/mobile/work-orders/{$wo->id}/escalations", [
            'client_request_id' => 'esc-race', 'target_team_type' => 'HARSUS', 'target_work_type' => 'PEKERJAAN_KHUSUS', 'reason_code' => 'BUTUH_PADAM', 'note' => 'Butuh padam',
        ])->assertCreated()->assertJsonPath('meta.idempotent_replay', true)->assertJsonPath('data.escalation_id', $winner());
        $this->assertSame(1, WorkEscalation::query()->count());
    }

    public function test_failed_request_leaves_no_partial_data_and_can_be_resent_with_the_same_key(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $this->actingAs($this->account, 'sanctum');

        $this->post("/api/v1/mobile/work-orders/{$wo->id}/reports", $this->reportPayload('ulang-1') + ['photos_before' => [$this->photo()]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->assertSame(0, WorkOrderReport::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Setelah diperbaiki (foto AFTER ditambahkan), kunci yang sama sah dipakai karena belum ada data tersimpan.
        $this->report($wo, 'ulang-1', $this->photos())->assertCreated()->assertJsonPath('meta.idempotent_replay', false);
    }

    public function test_replay_never_crosses_team_scope(): void
    {
        $otherPosko = Posko::create(['kode_posko' => 'JABUNG', 'nama_posko' => 'JABUNG']);
        $otherTeam = FieldTeam::create(['code' => 'POSKO-JABUNG-A', 'name' => 'Tim Jabung A', 'role' => 'POSKO', 'posko_id' => $otherPosko->id]);
        $mine = $this->createApkukWorkOrder($this->apkuk());
        $this->actingAs($this->account, 'sanctum');
        $photos = $this->photos();
        $this->report($mine, 'scope-1', $photos)->assertCreated();
        auth()->forgetGuards();

        // Tim lain memakai kunci yang sama pada WO milik tim pertama: 404, data tidak bocor.
        $this->actingAs($this->fieldAccount($otherTeam, 'jabung_retry'), 'sanctum');
        $this->report($mine, 'scope-1', $photos)->assertNotFound()->assertJsonMissingPath('data');

        // Inspeksi: kunci milik tim INSPEKSI lain ditolak, kunjungan tim pertama tidak dikembalikan.
        $secondInspeksi = FieldTeam::create(['code' => 'INSPEKSI-2', 'name' => 'Tim Inspeksi 2', 'role' => 'INSPEKSI']);
        $secondInspeksi->personnel()->create(['name' => 'Petugas Inspeksi 2']);
        $payload = fn (FieldTeam $team) => [
            'client_request_id' => 'ins-shared', 'penyulang_id' => $this->penyulang->id, 'visited_at' => '2026-10-28 08:00:00',
            'latitude' => -5.2, 'longitude' => 105.7, 'personnel_ids' => [$team->personnel()->value('id')], 'summary' => 'Area aman',
        ];
        auth()->forgetGuards();
        $this->actingAs($this->fieldAccount($this->inspeksiTeam, 'inspeksi_a'), 'sanctum');
        $code = $this->postJson('/api/v1/mobile/inspections', $payload($this->inspeksiTeam))->assertCreated()->json('data.code');
        $this->postJson('/api/v1/mobile/inspections', $payload($this->inspeksiTeam))->assertCreated()->assertJsonPath('meta.idempotent_replay', true);
        $this->postJson('/api/v1/mobile/inspections', ['summary' => 'Area aman, ada pohon condong'] + $payload($this->inspeksiTeam))
            ->assertUnprocessable()->assertJsonValidationErrors('client_request_id');
        auth()->forgetGuards();
        $this->actingAs($this->fieldAccount($secondInspeksi, 'inspeksi_b'), 'sanctum');
        $this->postJson('/api/v1/mobile/inspections', $payload($secondInspeksi))->assertUnprocessable()
            ->assertJsonPath('errors.client_request_id.0', 'client_request_id sudah dipakai untuk tim lain.')
            ->assertDontSee($code);
        $this->assertSame(1, Inspection::query()->count());
    }

    /**
     * @return array{photos_before: array<int, UploadedFile>, photos_after: array<int, UploadedFile>}
     */
    private function photos(): array
    {
        return ['photos_before' => [$this->photo('before.jpg')], 'photos_after' => [$this->photo('after.jpg')]];
    }

    /**
     * @param  array<string, array<int, UploadedFile>>  $photos
     * @param  array<string, mixed>  $overrides
     */
    private function report(WorkOrder $wo, string $key, array $photos, array $overrides = []): TestResponse
    {
        return $this->post("/api/v1/mobile/work-orders/{$wo->id}/reports", $overrides + $this->reportPayload($key) + $photos, ['Accept' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    private function reportPayload(string $key): array
    {
        return [
            'client_request_id' => $key, 'report_type' => WorkOrderReport::TYPE_PEMASANGAN_SELESAI, 'installed_quantity' => 3,
            'latitude' => -5.2, 'longitude' => 105.7, 'personnel_ids' => [$this->poskoPerson->id],
        ];
    }

    /**
     * Laporan "pemenang" dengan isi identik dengan request uji (petugas, koordinat, SHA-256 foto).
     *
     * @param  array{photos_before: array<int, UploadedFile>, photos_after: array<int, UploadedFile>}  $photos
     */
    private function insertReport(WorkOrder $wo, string $key, array $photos): string
    {
        $id = (string) Str::uuid();
        DB::table('work_order_reports')->insert([
            'id' => $id, 'work_order_id' => $wo->id, 'report_type' => WorkOrderReport::TYPE_PEMASANGAN_SELESAI,
            'verification_status' => WorkOrderReport::VERIFICATION_TIDAK_PERLU, 'installed_quantity' => 3, 'remaining_quantity' => 0,
            'latitude' => -5.2, 'longitude' => 105.7, 'occurred_at' => now(), 'received_at' => now(),
            'submitted_by_field_team_id' => $this->poskoTeam->id, 'submitted_by_user_id' => $this->account->id,
            'client_request_id' => $key, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('work_order_report_personnel')->insert([
            'work_order_report_id' => $id, 'field_personnel_id' => $this->poskoPerson->id,
            'personnel_name' => $this->poskoPerson->name, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['BEFORE' => $photos['photos_before'], 'AFTER' => $photos['photos_after']] as $type => $files) {
            foreach ($files as $file) {
                DB::table('work_order_media')->insert([
                    'id' => (string) Str::uuid(), 'work_order_id' => $wo->id, 'work_order_report_id' => $id, 'photo_type' => $type,
                    'capture_source' => 'CAMERA', 'disk' => 'local', 'storage_path' => 'pemenang/'.Str::uuid().'.jpg',
                    'sha256' => hash_file('sha256', $file->getRealPath()), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return $id;
    }

    /**
     * Menyisipkan record pemenang tepat setelah request kalah memeriksa client_request_id-nya.
     *
     * @param  callable(): string  $insertWinner
     * @return callable(): ?string ID record pemenang setelah request berjalan
     */
    private function injectWinnerAfterKeyCheck(string $table, string $key, callable $insertWinner): callable
    {
        $winner = null;
        DB::listen(function (QueryExecuted $query) use ($table, $key, $insertWinner, &$winner): void {
            if ($winner === null && str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, '"'.$table.'"') && str_contains($query->sql, 'client_request_id')
                && in_array($key, $query->bindings, true)) {
                $winner = 'sedang disisipkan';
                $winner = $insertWinner();
            }
        });

        return function () use (&$winner): ?string {
            return $winner;
        };
    }
}
