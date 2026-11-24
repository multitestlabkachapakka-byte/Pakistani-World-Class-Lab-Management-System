<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LabSetting;
use App\Models\Patient;
use App\Models\ReferenceRange;
use App\Models\Report;
use App\Models\ReportResult;
use App\Models\TestDefinition;
use App\Models\User;
use App\Services\BackupService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const PASS = 'backup-passphrase-1';
    private const ADMIN_PASSWORD = 'Passw0rd-test';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/lims-backup-test-'.bin2hex(random_bytes(4));
        config(['lims.backup_dir' => $this->dir]);

        $this->signIn('admin');
        $this->seed(CatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function makePatient(string $name): array
    {
        return $this->postJson('/api/patients', [
            'name' => $name, 'gender' => 'male', 'phone' => '03001234567', 'address' => 'Test Address',
            'date_of_birth' => now('Asia/Karachi')->subYears(30)->subDays(10)->format('Y-m-d'),
        ])->assertCreated()->json('data');
    }

    /** A patient, a finalized report with one result, and a changed setting. */
    private function populate(): array
    {
        $patient = $this->makePatient('Backup Test Patient');
        $report = $this->postJson('/api/reports', ['patient_id' => $patient['id'], 'test_codes' => ['LFT']])
            ->assertCreated()->json('data');
        $first = $report['report_tests'][0]['results'][0];

        $this->putJson("/api/reports/{$report['id']}/results", [
            'results' => [['id' => $first['id'], 'result_value' => '1.1']],
        ])->assertOk();
        $this->postJson("/api/reports/{$report['id']}/finalize", ['allow_incomplete' => true])->assertOk();
        $this->putJson('/api/settings', ['lab_name' => 'Original Lab'])->assertOk();

        return [$patient, $report];
    }

    private function counts(): array
    {
        return [
            'users' => User::count(),
            'patients' => Patient::count(),
            'reports' => Report::count(),
            'results' => ReportResult::count(),
            'tests' => TestDefinition::count(),
            'ranges' => ReferenceRange::count(),
            'settings' => LabSetting::count(),
        ];
    }

    private function takeBackup(): string
    {
        return $this->postJson('/api/backup', [
            'passphrase' => self::PASS, 'passphrase_confirmation' => self::PASS,
        ])->assertOk()->getContent();
    }

    private function restore(string $blob, array $over = [])
    {
        return $this->withHeaders(['Accept' => 'application/json'])->post('/api/restore', array_merge([
            'file' => UploadedFile::fake()->createWithContent('backup.limsbk', $blob),
            'passphrase' => self::PASS,
            'password' => self::ADMIN_PASSWORD,
            'confirm' => 'RESTORE',
        ], $over));
    }

    private function pack(array $payload): string
    {
        return app(BackupService::class)->encrypt(gzencode(json_encode($payload)), self::PASS);
    }

    public function test_a_backup_is_encrypted_and_restores_everything_and_signs_everyone_out(): void
    {
        [$patient, $report] = $this->populate();
        $before = $this->counts();
        $blob = $this->takeBackup();

        $this->assertStringStartsWith('LIMSBK1', $blob);
        $this->assertStringNotContainsString('Backup Test Patient', $blob);
        $this->assertStringNotContainsString('Original Lab', $blob);

        // Things change after the backup was made ...
        $this->makePatient('Added After Backup');
        $this->putJson('/api/settings', ['lab_name' => 'Changed Lab'])->assertOk();
        $token = User::first()->createToken('phone')->plainTextToken;

        // ... and the restore puts the clock back.
        $this->restore($blob)->assertOk()->assertJsonStructure(['data' => ['tables', 'rows', 'safety_copy']]);

        $this->assertSame($before, $this->counts());
        $this->assertNull(Patient::where('name', 'Added After Backup')->first());
        $this->assertNotNull(Patient::where('name', 'Backup Test Patient')->first());
        $this->assertSame('Original Lab', LabSetting::where('key', 'lab_name')->firstOrFail()->value['value']);

        $restored = Report::findOrFail($report['id']);
        $this->assertSame('final', $restored->status);
        $this->assertSame($patient['mr_number'], $restored->snapshot['patient']['mr_number']);

        // Every login is gone, the account itself still works.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$token])->assertStatus(401);
        $this->postJson('/api/auth/login', ['username' => User::first()->username, 'password' => self::ADMIN_PASSWORD])->assertOk();

        // A safety copy of the state before the restore exists, and the restore is in the audit trail.
        $this->assertCount(1, glob($this->dir.'/pre-restore-*.limsbk'));
        $this->assertSame(1, AuditLog::where('action', 'backup.restored')->count());
    }

    public function test_a_wrong_passphrase_a_damaged_file_or_a_foreign_file_is_refused_and_nothing_changes(): void
    {
        $this->populate();
        $blob = $this->takeBackup();
        $this->makePatient('Added After Backup');
        $before = $this->counts();

        $this->restore($blob, ['passphrase' => 'another-passphrase-9'])->assertStatus(422);

        $flipped = $blob;
        $i = strlen($blob) - 3;
        $flipped[$i] = chr(ord($flipped[$i]) ^ 1);
        $this->restore($flipped)->assertStatus(422);

        $this->restore('this is not a backup file at all')->assertStatus(422);

        $this->assertSame($before, $this->counts());
        $this->assertNotNull(Patient::where('name', 'Added After Backup')->first());
        $this->assertSame([], glob($this->dir.'/*.limsbk') ?: []);
    }

    public function test_restore_and_reset_need_the_admin_password_the_exact_phrase_and_an_admin(): void
    {
        $blob = $this->takeBackup();

        $this->restore($blob, ['password' => 'wrong-password'])->assertStatus(422);
        $this->restore($blob, ['confirm' => 'restore'])->assertStatus(422);
        $this->restore($blob, ['confirm' => null])->assertStatus(422);

        $reset = fn (array $over = []) => $this->postJson('/api/factory-reset', array_merge([
            'passphrase' => self::PASS, 'password' => self::ADMIN_PASSWORD, 'confirm' => 'RESET ALL DATA',
        ], $over));
        $reset(['password' => 'wrong-password'])->assertStatus(422);
        $reset(['confirm' => 'reset all data'])->assertStatus(422);
        $reset(['passphrase' => 'short'])->assertStatus(422);
        $this->assertSame(1, User::count()); // nothing was wiped

        $this->postJson('/api/backup', ['passphrase' => 'short', 'passphrase_confirmation' => 'short'])->assertStatus(422);
        $this->postJson('/api/backup', ['passphrase' => self::PASS, 'passphrase_confirmation' => 'different-pass-1'])->assertStatus(422);

        foreach (['pathologist', 'technician', 'receptionist'] as $role) {
            $this->signIn($role);
            $this->postJson('/api/backup', ['passphrase' => self::PASS, 'passphrase_confirmation' => self::PASS])->assertStatus(403);
            $this->getJson('/api/backups')->assertStatus(403);
            $this->restore($blob)->assertStatus(403);
            $reset()->assertStatus(403);
        }
    }

    public function test_a_backup_from_an_older_version_restores_and_one_from_a_newer_version_is_refused(): void
    {
        $this->populate();
        $payload = json_decode(gzdecode(app(BackupService::class)->decrypt($this->takeBackup(), self::PASS)), true);
        $before = $this->counts();

        // Newer: unknown column, unknown table, unknown migration -> refused, nothing changes.
        $column = $payload;
        $column['tables']['patients'][0]['future_column'] = 'x';
        $table = $payload;
        $table['tables']['future_table'] = [['id' => 1]];
        $migration = $payload;
        $migration['migrations'][] = '2099_01_01_000000_from_the_future';

        foreach ([$column, $table, $migration] as $newer) {
            $this->restore($this->pack($newer))->assertStatus(422);
        }
        $this->assertSame($before, $this->counts());

        // Older: a column that did not exist yet is missing from the data -> its default is used.
        $older = $payload;
        foreach ($older['tables']['patients'] as &$row) {
            unset($row['guardian_name']);
        }
        unset($row);

        $this->restore($this->pack($older))->assertOk();
        $this->assertSame($before, $this->counts());
        $this->assertNull(Patient::where('name', 'Backup Test Patient')->firstOrFail()->guardian_name);
    }

    public function test_factory_reset_wipes_everything_resets_the_catalog_and_keeps_a_restorable_safety_copy(): void
    {
        $this->populate();
        ReferenceRange::firstOrFail()->forceFill([
            'verification_status' => ReferenceRange::STATUS_VERIFIED, 'verified_by' => 'Dr Example',
            'verified_at' => now(), 'rev' => 2,
        ])->save();
        $before = $this->counts();

        $res = $this->postJson('/api/factory-reset', [
            'passphrase' => self::PASS, 'password' => self::ADMIN_PASSWORD, 'confirm' => 'RESET ALL DATA',
        ])->assertOk();
        $copy = $res->json('data.safety_copy');
        $this->assertFileExists($this->dir.'/'.$copy);

        foreach (['users', 'patients', 'reports', 'results', 'settings'] as $name) {
            $this->assertSame(0, $this->counts()[$name], $name);
        }
        $this->assertSame($before['tests'], TestDefinition::count());   // the catalog is back to its shipped state
        $this->assertSame(0, ReferenceRange::where('verification_status', ReferenceRange::STATUS_VERIFIED)->count());
        $this->assertSame(1, AuditLog::count());
        $this->assertSame('factory_reset', AuditLog::firstOrFail()->action);
        $this->getJson('/api/setup/status')->assertJsonPath('data.needs_setup', true);

        // The safety copy brings everything back, including the verified range.
        $this->restore(file_get_contents($this->dir.'/'.$copy))->assertOk();
        $this->assertSame($before, $this->counts());
        $this->assertSame(1, ReferenceRange::where('verification_status', ReferenceRange::STATUS_VERIFIED)->count());
        $this->assertNotNull(Patient::where('name', 'Backup Test Patient')->first());
    }

    public function test_safety_copies_can_be_listed_and_downloaded_by_admins_only(): void
    {
        $this->populate();
        $this->postJson('/api/factory-reset', [
            'passphrase' => self::PASS, 'password' => self::ADMIN_PASSWORD, 'confirm' => 'RESET ALL DATA',
        ])->assertOk();

        $list = $this->getJson('/api/backups')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $name = $list[0]['name'];
        $this->assertStringStartsWith('pre-reset-', $name);
        $this->assertGreaterThan(0, $list[0]['bytes']);

        $download = $this->get("/api/backups/{$name}")->assertOk();
        $this->assertGreaterThan(0, $download->baseResponse->getFile()->getSize());

        $this->getJson('/api/backups/evil.txt')->assertStatus(404);
        $this->getJson('/api/backups/'.rawurlencode('../../../.env'))->assertStatus(404);

        $this->signIn('technician');
        $this->getJson('/api/backups')->assertStatus(403);
        $this->getJson("/api/backups/{$name}")->assertStatus(403);
    }
}
