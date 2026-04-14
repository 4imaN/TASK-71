<?php

namespace Tests\Feature\Import;

use App\Models\Department;
use App\Models\ImportConflict;
use App\Models\ImportJob;
use App\Models\User;
use App\Services\Import\ConflictResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConflictResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private ConflictResolutionService $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);
        $this->admin = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);
        $this->admin->assignRole('administrator');

        $this->resolver = app(ConflictResolutionService::class);
    }

    private function makeJob(array $overrides = []): ImportJob
    {
        return ImportJob::create(array_merge([
            'uuid'                         => (string) Str::uuid(),
            'entity_type'                  => 'departments',
            'file_format'                  => 'csv',
            'status'                       => 'processing',
            'conflict_resolution_strategy' => 'pending',
            'created_by'                   => $this->admin->id,
        ], $overrides));
    }

    // ── prefer_newest strategy ────────────────────────────────────────────────────

    public function test_prefer_newest_applies_when_incoming_is_newer(): void
    {
        $existing = Department::create([
            'code'            => 'DEP01',
            'name'            => 'Engineering',
            'last_updated_at' => '2024-01-01 00:00:00',
        ]);

        $row    = ['code' => 'DEP01', 'name' => 'Engineering UPDATED', 'last_updated_at' => '2025-01-01 00:00:00'];
        $diffs  = [['field' => 'name', 'local_value' => 'Engineering', 'incoming_value' => 'Engineering UPDATED']];
        $job    = $this->makeJob();

        $result = $this->resolver->resolve('prefer_newest', $row, $existing, $diffs, $job);

        $this->assertEquals('apply', $result['action']);
        $this->assertEquals($row, $result['resolvedRow']);
    }

    public function test_prefer_newest_skips_when_existing_is_newer(): void
    {
        $existing = Department::create([
            'code'            => 'DEP01',
            'name'            => 'Engineering',
            'last_updated_at' => '2025-12-01 00:00:00',
        ]);

        $row   = ['code' => 'DEP01', 'name' => 'Engineering UPDATED', 'last_updated_at' => '2024-01-01 00:00:00'];
        $diffs = [['field' => 'name', 'local_value' => 'Engineering', 'incoming_value' => 'Engineering UPDATED']];
        $job   = $this->makeJob();

        $result = $this->resolver->resolve('prefer_newest', $row, $existing, $diffs, $job);

        $this->assertEquals('skip', $result['action']);
        $this->assertNull($result['resolvedRow']);
    }

    public function test_prefer_newest_applies_when_no_timestamps(): void
    {
        $existing = Department::create([
            'code' => 'DEP01',
            'name' => 'Engineering',
        ]);

        $row   = ['code' => 'DEP01', 'name' => 'Engineering UPDATED'];
        $diffs = [['field' => 'name', 'local_value' => 'Engineering', 'incoming_value' => 'Engineering UPDATED']];
        $job   = $this->makeJob();

        $result = $this->resolver->resolve('prefer_newest', $row, $existing, $diffs, $job);

        $this->assertEquals('apply', $result['action']);
    }

    // ── admin_override / pending strategy ────────────────────────────────────────

    public function test_admin_override_creates_conflict_record(): void
    {
        $existing = Department::create([
            'code' => 'DEP01',
            'name' => 'Engineering',
        ]);

        $row   = ['code' => 'DEP01', 'name' => 'Engineering CHANGED'];
        $diffs = [['field' => 'name', 'local_value' => 'Engineering', 'incoming_value' => 'Engineering CHANGED']];
        $job   = $this->makeJob();

        $result = $this->resolver->resolve('admin_override', $row, $existing, $diffs, $job);

        $this->assertEquals('conflict', $result['action']);
        $this->assertNull($result['resolvedRow']);
        $this->assertDatabaseHas('import_conflicts', [
            'import_job_id' => $job->id,
            'resolution'    => 'pending',
        ]);
    }

    public function test_pending_strategy_creates_conflict_record(): void
    {
        $existing = Department::create([
            'code' => 'DEP02',
            'name' => 'Research',
        ]);

        $row   = ['code' => 'DEP02', 'name' => 'Research Division'];
        $diffs = [['field' => 'name', 'local_value' => 'Research', 'incoming_value' => 'Research Division']];
        $job   = $this->makeJob();

        $result = $this->resolver->resolve('pending', $row, $existing, $diffs, $job);

        $this->assertEquals('conflict', $result['action']);
        $this->assertDatabaseHas('import_conflicts', [
            'import_job_id' => $job->id,
            'resolution'    => 'pending',
        ]);
    }

    // ── adminResolve: prefer_newest ───────────────────────────────────────────────

    public function test_admin_resolve_prefer_newest_uses_incoming_record(): void
    {
        $job      = $this->makeJob();
        $conflict = ImportConflict::create([
            'import_job_id'     => $job->id,
            'record_identifier' => '1',
            'local_record'      => ['code' => 'DEP01', 'name' => 'Old Name'],
            'incoming_record'   => ['code' => 'DEP01', 'name' => 'New Name'],
            'field_diffs'       => [['field' => 'name', 'local_value' => 'Old Name', 'incoming_value' => 'New Name']],
            'resolution'        => 'pending',
        ]);

        $resolved = $this->resolver->adminResolve($conflict, 'prefer_newest', [], $this->admin);

        $this->assertEquals('prefer_newest', $resolved->resolution);
        $this->assertEquals(['code' => 'DEP01', 'name' => 'New Name'], $resolved->resolved_record);
        $this->assertEquals($this->admin->id, $resolved->resolved_by);
        $this->assertNotNull($resolved->resolved_at);
    }

    // ── adminResolve: admin_override ──────────────────────────────────────────────

    public function test_admin_resolve_admin_override_uses_provided_record(): void
    {
        $job      = $this->makeJob();
        $conflict = ImportConflict::create([
            'import_job_id'     => $job->id,
            'record_identifier' => '1',
            'local_record'      => ['code' => 'DEP01', 'name' => 'Old Name'],
            'incoming_record'   => ['code' => 'DEP01', 'name' => 'New Name'],
            'field_diffs'       => [['field' => 'name', 'local_value' => 'Old Name', 'incoming_value' => 'New Name']],
            'resolution'        => 'pending',
        ]);

        $customRecord = ['code' => 'DEP01', 'name' => 'Custom Admin Name'];

        $resolved = $this->resolver->adminResolve($conflict, 'admin_override', $customRecord, $this->admin);

        $this->assertEquals('admin_override', $resolved->resolution);
        $this->assertEquals($customRecord, $resolved->resolved_record);
        $this->assertEquals($this->admin->id, $resolved->resolved_by);
    }

    // ── Conflict stores field diffs correctly ─────────────────────────────────────

    public function test_conflict_stores_field_diffs(): void
    {
        $existing = Department::create([
            'code' => 'DEP01',
            'name' => 'Engineering',
        ]);

        $diffs = [
            ['field' => 'name', 'local_value' => 'Engineering', 'incoming_value' => 'Engineering Updated'],
            ['field' => 'is_active', 'local_value' => true, 'incoming_value' => false],
        ];

        $row = ['code' => 'DEP01', 'name' => 'Engineering Updated', 'is_active' => false];
        $job = $this->makeJob();

        $this->resolver->resolve('admin_override', $row, $existing, $diffs, $job);

        $conflict = ImportConflict::where('import_job_id', $job->id)->first();
        $this->assertNotNull($conflict);
        $this->assertCount(2, $conflict->field_diffs);
        $this->assertEquals('name', $conflict->field_diffs[0]['field']);
    }

    // ── Sensitive fields redacted in conflict records (Issue 3) ─────────────────

    /**
     * When creating import conflicts for user_profile entities, classified
     * sensitive fields (employee_id) must be redacted from both the
     * incoming_record and local_record JSON columns.
     */
    public function test_sensitive_fields_redacted_in_user_profile_conflict(): void
    {
        // Seed the sensitive_data_classifications
        $this->seed(\Database\Seeders\SensitiveDataClassificationSeeder::class);

        $user = \App\Models\User::factory()->create(['username' => 'jdoe']);
        $profile = \App\Models\UserProfile::create([
            'user_id'           => $user->id,
            'employee_id'       => 'EMP-SECRET-123',
            'job_title'         => 'Researcher',
            'employment_status' => 'active',
        ]);

        $job = $this->makeJob(['entity_type' => 'user_profiles']);

        $row = [
            'employee_id'       => 'EMP-SECRET-123',
            'job_title'         => 'Senior Researcher',
            'employment_status' => 'active',
        ];

        $diffs = [['field' => 'job_title', 'local_value' => 'Researcher', 'incoming_value' => 'Senior Researcher']];

        $this->resolver->resolve('admin_override', $row, $profile, $diffs, $job);

        $conflict = ImportConflict::where('import_job_id', $job->id)->first();
        $this->assertNotNull($conflict);

        // employee_id must be redacted in both records
        $this->assertEquals('[REDACTED]', $conflict->incoming_record['employee_id'] ?? null,
            'employee_id must be redacted in incoming_record');
        $this->assertEquals('[REDACTED]', $conflict->local_record['employee_id'] ?? null,
            'employee_id must be redacted in local_record');
    }

    // ── REST reprocess endpoint (Issue 5) ─────────────────────────────────────────

    public function test_rest_reprocess_applies_resolved_conflicts(): void
    {
        $existing = Department::create([
            'code' => 'DEP01',
            'name' => 'Engineering',
        ]);

        $job = $this->makeJob(['conflict_resolution_strategy' => 'admin_override']);

        // Create a resolved conflict with new name
        $conflict = ImportConflict::create([
            'import_job_id'     => $job->id,
            'record_identifier' => (string) $existing->id,
            'local_record'      => ['code' => 'DEP01', 'name' => 'Engineering'],
            'incoming_record'   => ['code' => 'DEP01', 'name' => 'Eng Reprocessed'],
            'field_diffs'       => [['field' => 'name', 'local_value' => 'Engineering', 'incoming_value' => 'Eng Reprocessed']],
            'resolution'        => 'admin_override',
            'resolved_record'   => ['code' => 'DEP01', 'name' => 'Eng Reprocessed'],
            'resolved_by'       => $this->admin->id,
            'resolved_at'       => now(),
        ]);

        session([\App\Services\Admin\StepUpService::SESSION_KEY => now()->toIso8601String()]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/import/{$job->id}/reprocess");

        $response->assertOk();

        // The resolved record should have been applied to the department
        $this->assertDatabaseHas('departments', ['code' => 'DEP01', 'name' => 'Eng Reprocessed']);
    }
}
