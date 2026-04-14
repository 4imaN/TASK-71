<?php

namespace Tests\Feature\Gateway;

use App\Models\BackupLog;
use App\Models\User;
use App\Services\Api\BackupApiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Verifies that BackupApiGateway is the shared contract for backup
 * operations, consumed by both BackupComponent and the REST
 * Admin\BackupController.
 */
class BackupApiGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['password' => Hash::make('AdminPass1!')]);
        $this->admin->assignRole('administrator');
        $this->actingAs($this->admin);
        $this->backupDir = storage_path('app/backups');
    }

    protected function tearDown(): void
    {
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

    // ── List ─────────────────────────────────────────────────────────────────

    public function test_list_returns_paginated_backups(): void
    {
        BackupLog::create([
            'snapshot_filename' => 'backup_gw.sqlite-placeholder',
            'snapshot_path'     => '',
            'file_size_bytes'   => 0,
            'type'              => 'manual',
            'status'            => 'success',
        ]);

        $gateway = app(BackupApiGateway::class);
        $result  = $gateway->list(perPage: 10);

        $this->assertCount(1, $result->items());
    }

    // ── Run ──────────────────────────────────────────────────────────────────

    public function test_run_returns_success_result(): void
    {
        $gateway = app(BackupApiGateway::class);
        $result  = $gateway->run($this->admin, 'manual');

        $this->assertTrue($result->success);
        $this->assertEquals(201, $result->httpStatus);
        $this->assertNotNull($result->data);
        $this->assertEquals('success', $result->data->status);
        $this->assertEquals('manual', $result->data->type);
    }

    public function test_run_generates_audit_log(): void
    {
        $gateway = app(BackupApiGateway::class);
        $gateway->run($this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'backup.completed',
            'actor_id'    => $this->admin->id,
            'entity_type' => 'backup_log',
        ]);
    }

    // ── Restore-test ─────────────────────────────────────────────────────────

    public function test_record_restore_test_returns_success(): void
    {
        $backup = BackupLog::create([
            'snapshot_filename' => 'backup_rt_gw.sqlite-placeholder',
            'snapshot_path'     => '',
            'file_size_bytes'   => 0,
            'type'              => 'manual',
            'status'            => 'success',
        ]);

        $gateway = app(BackupApiGateway::class);
        $result  = $gateway->recordRestoreTest(
            backupLogId: $backup->id,
            tester:      $this->admin,
            result:      'success',
            notes:       'Gateway test drill.',
        );

        $this->assertTrue($result->success);
        $this->assertEquals(201, $result->httpStatus);

        $this->assertDatabaseHas('restore_test_logs', [
            'backup_log_id' => $backup->id,
            'tested_by'     => $this->admin->id,
            'test_result'   => 'success',
        ]);
    }

    public function test_record_restore_test_for_nonexistent_backup_returns_404(): void
    {
        $gateway = app(BackupApiGateway::class);
        $result  = $gateway->recordRestoreTest(
            backupLogId: 99999,
            tester:      $this->admin,
            result:      'success',
        );

        $this->assertFalse($result->success);
        $this->assertEquals(404, $result->httpStatus);
    }

    // ── Constants ────────────────────────────────────────────────────────────

    public function test_retention_days_returns_30(): void
    {
        $gateway = app(BackupApiGateway::class);
        $this->assertEquals(30, $gateway->retentionDays());
    }

    // ── Parity: gateway list matches REST ────────────────────────────────────

    public function test_gateway_list_parity_with_rest_backup_endpoint(): void
    {
        BackupLog::create([
            'snapshot_filename' => 'backup_parity.sqlite-placeholder',
            'snapshot_path'     => '',
            'file_size_bytes'   => 0,
            'type'              => 'manual',
            'status'            => 'success',
        ]);

        // Gateway
        $gateway       = app(BackupApiGateway::class);
        $gatewayResult = $gateway->list(perPage: 20);

        // REST
        $restResponse = $this->getJson('/api/v1/admin/backups');
        $restResponse->assertOk();

        // Both should return the same count
        $this->assertEquals($gatewayResult->total(), $restResponse->json('total'));
    }
}
