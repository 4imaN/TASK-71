<?php

namespace Tests\Feature\Admin;

use App\Models\BackupLog;
use App\Models\User;
use App\Services\Admin\BackupService;
use App\Services\Admin\StepUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BackupTest — covers BackupService behavior and the REST API surface.
 *
 * Driver-aware test coverage:
 *
 *   SQLite :memory: (local targeted tests, CI with sqlite driver)
 *     BackupService writes a zero-byte .sqlite-placeholder file so the
 *     full backup workflow (log creation, retention, audit) is exercisable.
 *
 *   PostgreSQL (Dockerized broad test path via ./run_tests.sh)
 *     BackupService runs pg_dump --format=custom | gzip producing a real
 *     .dump.gz file.  Tests that assert driver-specific file properties are
 *     guarded with markTestSkipped so they execute only in the correct
 *     environment.  Tests that assert driver-agnostic properties (status,
 *     type, audit log, API shape) run in both environments.
 *
 * tearDown cleans up both file types so no artifacts are left on disk.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);
        $this->admin = User::factory()->create([
            'password' => Hash::make('AdminPass1!'),
        ]);
        $this->admin->assignRole('administrator');

        $this->backupDir = storage_path('app/backups');
    }

    protected function tearDown(): void
    {
        // Clean up all backup artifacts written by BackupService::run() — covers
        // both the SQLite :memory: placeholder path and the PostgreSQL dump path.
        if (is_dir($this->backupDir)) {
            foreach (glob($this->backupDir . '/*.sqlite-placeholder') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($this->backupDir . '/*.dump.gz') ?: [] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    // ── Driver detection helpers ──────────────────────────────────────────────

    private function isMemorySqlite(): bool
    {
        return config('database.default') === 'sqlite'
            && config('database.connections.sqlite.database') === ':memory:';
    }

    private function isPostgres(): bool
    {
        return config('database.default') === 'pgsql';
    }

    // ── BackupService: run() ─────────────────────────────────────────────────

    public function test_run_creates_backup_log_with_success_status(): void
    {
        $service = app(BackupService::class);
        $log     = $service->run($this->admin, 'manual');

        $this->assertInstanceOf(BackupLog::class, $log);
        $this->assertEquals('success', $log->status);
        $this->assertEquals('manual', $log->type);
        $this->assertNotEmpty($log->snapshot_filename);
        $this->assertDatabaseHas('backup_logs', ['id' => $log->id, 'status' => 'success']);
    }

    public function test_run_writes_audit_log(): void
    {
        app(BackupService::class)->run($this->admin, 'manual');

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'backup.completed',
            'actor_id'    => $this->admin->id,
            'entity_type' => 'backup_log',
        ]);
    }

    public function test_run_daily_type_is_recorded(): void
    {
        $log = app(BackupService::class)->run($this->admin, 'daily');

        $this->assertEquals('daily', $log->type);
        $this->assertDatabaseHas('backup_logs', ['id' => $log->id, 'type' => 'daily']);
    }

    public function test_run_placeholder_file_is_created_for_sqlite_memory(): void
    {
        if (!$this->isMemorySqlite()) {
            $this->markTestSkipped('SQLite :memory: placeholder path only — skipped in PostgreSQL environment.');
        }

        $log = app(BackupService::class)->run($this->admin);

        // SQLite :memory: path → zero-byte placeholder file
        $this->assertFileExists($log->snapshot_path);
        $this->assertEquals(0, $log->file_size_bytes);
        $this->assertStringEndsWith('.sqlite-placeholder', $log->snapshot_filename);
    }

    public function test_run_postgres_produces_real_dump_gz_file(): void
    {
        if (!$this->isPostgres()) {
            $this->markTestSkipped('PostgreSQL pg_dump path only — skipped in SQLite environment.');
        }

        $log = app(BackupService::class)->run($this->admin);

        // PostgreSQL path → real pg_dump | gzip archive
        $this->assertEquals('success', $log->status);
        $this->assertStringEndsWith('.dump.gz', $log->snapshot_filename);
        $this->assertFileExists($log->snapshot_path);
        // A real pg_dump archive always contains at least a binary header
        $this->assertGreaterThan(0, $log->file_size_bytes);
    }

    // ── BackupService: applyRetention() ──────────────────────────────────────

    public function test_retention_prunes_backups_older_than_30_days(): void
    {
        // Create an old successful backup record
        $old = BackupLog::create([
            'snapshot_filename' => 'backup_old.sqlite-placeholder',
            'snapshot_path'     => '',
            'file_size_bytes'   => 0,
            'type'              => 'daily',
            'status'            => 'success',
            'created_at'        => now()->subDays(31),
        ]);

        $pruned = app(BackupService::class)->applyRetention();

        $this->assertEquals(1, $pruned);
        $this->assertDatabaseMissing('backup_logs', ['id' => $old->id]);
    }

    public function test_retention_does_not_prune_backups_within_30_days(): void
    {
        $recent = BackupLog::create([
            'snapshot_filename' => 'backup_recent.sqlite-placeholder',
            'snapshot_path'     => '',
            'file_size_bytes'   => 0,
            'type'              => 'daily',
            'status'            => 'success',
            'created_at'        => now()->subDays(29),
        ]);

        $pruned = app(BackupService::class)->applyRetention();

        $this->assertEquals(0, $pruned);
        $this->assertDatabaseHas('backup_logs', ['id' => $recent->id]);
    }

    public function test_retention_removes_file_on_disk_when_present(): void
    {
        // Create a real placeholder file
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        $placeholderPath = $this->backupDir . '/backup_old_prune_' . uniqid() . '.sqlite-placeholder';
        file_put_contents($placeholderPath, '');

        BackupLog::create([
            'snapshot_filename' => basename($placeholderPath),
            'snapshot_path'     => $placeholderPath,
            'file_size_bytes'   => 0,
            'type'              => 'daily',
            'status'            => 'success',
            'created_at'        => now()->subDays(35),
        ]);

        app(BackupService::class)->applyRetention();

        $this->assertFileDoesNotExist($placeholderPath);

        // Extra safety: clean up if retention somehow didn't delete it
        @unlink($placeholderPath);
    }

    // ── BackupService: recordRestoreTest() ───────────────────────────────────

    public function test_record_restore_test_creates_record(): void
    {
        $backup = BackupLog::create([
            'snapshot_filename' => 'backup_test.sqlite-placeholder',
            'snapshot_path'     => '',
            'file_size_bytes'   => 0,
            'type'              => 'manual',
            'status'            => 'success',
        ]);

        $result = app(BackupService::class)->recordRestoreTest(
            backup: $backup,
            tester: $this->admin,
            result: 'success',
            notes:  'Full restore verified in 4 minutes on dev box.',
        );

        $this->assertDatabaseHas('restore_test_logs', [
            'backup_log_id' => $backup->id,
            'tested_by'     => $this->admin->id,
            'test_result'   => 'success',
        ]);
        $this->assertNotNull($result->tested_at);
    }

    public function test_record_restore_test_writes_audit_log(): void
    {
        $backup = BackupLog::create([
            'snapshot_filename' => 'backup_audit.sqlite-placeholder',
            'snapshot_path'     => '',
            'file_size_bytes'   => 0,
            'type'              => 'manual',
            'status'            => 'success',
        ]);

        app(BackupService::class)->recordRestoreTest($backup, $this->admin, 'partial', null);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'backup.restore_test_recorded',
            'actor_id'    => $this->admin->id,
            'entity_type' => 'restore_test_log',
        ]);
    }

    public function test_record_restore_test_accepts_all_result_values(): void
    {
        $backup = BackupLog::create([
            'snapshot_filename' => 'backup_results.sqlite-placeholder',
            'snapshot_path'     => '',
            'file_size_bytes'   => 0,
            'type'              => 'manual',
            'status'            => 'success',
        ]);

        $service = app(BackupService::class);

        $service->recordRestoreTest($backup, $this->admin, 'success', null);
        $service->recordRestoreTest($backup, $this->admin, 'partial', 'some tables missing');
        $service->recordRestoreTest($backup, $this->admin, 'failed', 'pg_restore error on constraints');

        $this->assertEquals(3, $backup->restoreTests()->count());
    }

    // ── API: GET /api/v1/admin/backups ────────────────────────────────────────

    public function test_api_list_backups_returns_paginated_results(): void
    {
        BackupLog::create([
            'snapshot_filename' => 'backup_api.sqlite-placeholder',
            'snapshot_path'     => '',
            'file_size_bytes'   => 0,
            'type'              => 'manual',
            'status'            => 'success',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/backups');

        $response->assertOk()
            ->assertJsonStructure(['data', 'total', 'per_page', 'current_page']);
    }

    public function test_api_list_backups_requires_administrator_role(): void
    {
        $learner = User::factory()->create();

        $this->actingAs($learner)
            ->getJson('/api/v1/admin/backups')
            ->assertForbidden();
    }

    // ── API: POST /api/v1/admin/backups ───────────────────────────────────────

    public function test_api_trigger_backup_requires_stepup(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/backups');

        $response->assertStatus(403)
            ->assertJsonFragment(['message' => 'Step-up verification required.']);
    }

    public function test_api_trigger_backup_succeeds_with_stepup(): void
    {
        // Grant step-up
        session([StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/backups');

        $response->assertStatus(201)
            ->assertJsonPath('backup.status', 'success')
            ->assertJsonPath('backup.type', 'manual');
    }

    // ── API: GET /api/v1/admin/backups/{id} ───────────────────────────────────

    public function test_api_show_backup_includes_restore_tests(): void
    {
        $backup = BackupLog::create([
            'snapshot_filename' => 'backup_show.sqlite-placeholder',
            'snapshot_path'     => '',
            'file_size_bytes'   => 0,
            'type'              => 'manual',
            'status'            => 'success',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/backups/{$backup->id}");

        $response->assertOk()
            ->assertJsonPath('backup.id', $backup->id)
            ->assertJsonStructure(['backup' => ['restore_tests']]);
    }

    // ── API: POST /api/v1/admin/backups/{id}/restore-tests ───────────────────

    public function test_api_record_restore_test_creates_entry(): void
    {
        $backup = BackupLog::create([
            'snapshot_filename' => 'backup_rt.sqlite-placeholder',
            'snapshot_path'     => '',
            'file_size_bytes'   => 0,
            'type'              => 'daily',
            'status'            => 'success',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/backups/{$backup->id}/restore-tests", [
                'test_result' => 'success',
                'notes'       => 'Restore drill completed in staging.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('restore_test.test_result', 'success');

        $this->assertDatabaseHas('restore_test_logs', [
            'backup_log_id' => $backup->id,
            'tested_by'     => $this->admin->id,
            'test_result'   => 'success',
        ]);
    }

    public function test_api_record_restore_test_validates_result_values(): void
    {
        $backup = BackupLog::create([
            'snapshot_filename' => 'backup_val.sqlite-placeholder',
            'snapshot_path'     => '',
            'file_size_bytes'   => 0,
            'type'              => 'manual',
            'status'            => 'success',
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/backups/{$backup->id}/restore-tests", [
                'test_result' => 'bogus_value',
            ])
            ->assertStatus(422);
    }
}
