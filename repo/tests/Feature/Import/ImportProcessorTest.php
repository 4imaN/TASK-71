<?php

namespace Tests\Feature\Import;

use App\Models\Department;
use App\Models\ImportJob;
use App\Models\User;
use App\Services\Import\ImportProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportProcessorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);
        $this->admin = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);
        $this->admin->assignRole('administrator');
    }

    private function makeJob(array $overrides = []): ImportJob
    {
        return ImportJob::create(array_merge([
            'uuid'                         => (string) Str::uuid(),
            'entity_type'                  => 'departments',
            'file_format'                  => 'csv',
            'status'                       => 'pending',
            'conflict_resolution_strategy' => 'prefer_newest',
            'created_by'                   => $this->admin->id,
        ], $overrides));
    }

    // ── New row applied directly ──────────────────────────────────────────────────

    public function test_process_new_row_creates_department(): void
    {
        $job    = $this->makeJob();
        $csv    = "code,name\nDEP01,Engineering";
        $processor = app(ImportProcessorService::class);

        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals('completed', $job->status);
        $this->assertEquals(1, $job->processed_count);
        $this->assertEquals(0, $job->error_count);
        $this->assertDatabaseHas('departments', ['code' => 'DEP01', 'name' => 'Engineering']);
    }

    public function test_process_multiple_new_rows(): void
    {
        $job = $this->makeJob();
        $csv = "code,name\nDEP01,Engineering\nDEP02,Research\nDEP03,Science";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals('completed', $job->status);
        $this->assertEquals(3, $job->processed_count);
        $this->assertDatabaseCount('departments', 3);
    }

    // ── Duplicate with prefer_newest: skip old, apply new ──────────────────────

    public function test_prefer_newest_skips_when_existing_is_newer(): void
    {
        // Create existing department with a newer timestamp
        Department::create([
            'code'            => 'DEP01',
            'name'            => 'Engineering',
            'is_active'       => true,
            'last_updated_at' => '2025-06-01 10:00:00',
        ]);

        $job = $this->makeJob(['conflict_resolution_strategy' => 'prefer_newest']);

        // Incoming row has an older timestamp
        $csv = "code,name,last_updated_at\nDEP01,Engineering UPDATED,2024-01-01 10:00:00";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals('completed', $job->status);
        // The existing record should not have been changed
        $this->assertDatabaseHas('departments', ['code' => 'DEP01', 'name' => 'Engineering']);
        $this->assertDatabaseMissing('departments', ['name' => 'Engineering UPDATED']);
    }

    public function test_prefer_newest_applies_when_incoming_is_newer(): void
    {
        // Create existing department with an older timestamp
        Department::create([
            'code'            => 'DEP01',
            'name'            => 'Engineering',
            'is_active'       => true,
            'last_updated_at' => '2024-01-01 10:00:00',
        ]);

        $job = $this->makeJob(['conflict_resolution_strategy' => 'prefer_newest']);

        // Incoming row has a newer timestamp
        $csv = "code,name,last_updated_at\nDEP01,Engineering UPDATED,2025-06-01 10:00:00";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals('completed', $job->status);
        $this->assertDatabaseHas('departments', ['code' => 'DEP01', 'name' => 'Engineering UPDATED']);
    }

    // ── Duplicate with admin_override: creates conflict ──────────────────────────

    public function test_admin_override_creates_conflict_record(): void
    {
        Department::create([
            'code' => 'DEP01',
            'name' => 'Engineering',
        ]);

        $job = $this->makeJob(['conflict_resolution_strategy' => 'admin_override']);
        $csv = "code,name\nDEP01,Engineering CHANGED";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals('needs_review', $job->status);
        $this->assertEquals(1, $job->conflict_count);
        $this->assertDatabaseHas('import_conflicts', ['import_job_id' => $job->id]);
        // Original department should be unchanged
        $this->assertDatabaseHas('departments', ['code' => 'DEP01', 'name' => 'Engineering']);
    }

    // ── needs_review status when conflicts remain ─────────────────────────────────

    public function test_job_is_needs_review_when_unresolved_conflicts(): void
    {
        Department::create(['code' => 'DEP01', 'name' => 'Existing']);

        $job = $this->makeJob(['conflict_resolution_strategy' => 'pending']);
        $csv = "code,name\nDEP01,Changed Name\nDEP02,New Department";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals('needs_review', $job->status);
        // DEP02 was applied (new row), DEP01 created a conflict
        $this->assertDatabaseHas('departments', ['code' => 'DEP02']);
    }

    // ── Failed rows for missing required fields ────────────────────────────────────

    public function test_missing_required_field_increments_failed_count(): void
    {
        $job = $this->makeJob();
        // Missing required 'name' field
        $csv = "code,name\nDEP01,\nDEP02,Valid Name";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        // DEP01 row is missing required 'name' → error
        // DEP02 should be applied
        $this->assertEquals(1, $job->error_count);
        $this->assertEquals(1, $job->processed_count);
        $this->assertDatabaseHas('departments', ['code' => 'DEP02']);
    }

    public function test_missing_code_field_increments_failed_count(): void
    {
        $job = $this->makeJob();
        $csv = "code,name\n,No Code";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals(1, $job->error_count);
        $this->assertEquals(0, $job->processed_count);
    }

    // ── JSON format ──────────────────────────────────────────────────────────────

    public function test_process_json_format(): void
    {
        $job = $this->makeJob(['file_format' => 'json']);
        $json = json_encode([
            ['code' => 'DEP01', 'name' => 'Engineering'],
            ['code' => 'DEP02', 'name' => 'Research'],
        ]);

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $json, $this->admin);

        $this->assertEquals('completed', $job->status);
        $this->assertEquals(2, $job->processed_count);
        $this->assertDatabaseHas('departments', ['code' => 'DEP01']);
        $this->assertDatabaseHas('departments', ['code' => 'DEP02']);
    }

    // ── Idempotency: no diffs means no conflict ───────────────────────────────────

    public function test_no_diffs_does_not_create_conflict(): void
    {
        Department::create([
            'code' => 'DEP01',
            'name' => 'Engineering',
        ]);

        $job = $this->makeJob(['conflict_resolution_strategy' => 'admin_override']);
        // Same data, no change
        $csv = "code,name\nDEP01,Engineering";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals('completed', $job->status);
        $this->assertEquals(0, $job->conflict_count);
        $this->assertDatabaseMissing('import_conflicts', ['import_job_id' => $job->id]);
    }

    // ── Field mapping applied during processing ───────────────────────────────────

    public function test_process_with_field_mapping(): void
    {
        $job = $this->makeJob([
            'field_mapping' => ['dept_code' => 'code', 'dept_name' => 'name'],
        ]);

        $csv = "dept_code,dept_name\nHR001,Human Resources";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals('completed', $job->status);
        $this->assertDatabaseHas('departments', ['code' => 'HR001', 'name' => 'Human Resources']);
    }

    // ── Audit log written ─────────────────────────────────────────────────────────

    public function test_process_writes_audit_log(): void
    {
        $job = $this->makeJob();
        $csv = "code,name\nDEP01,Engineering";

        $processor = app(ImportProcessorService::class);
        $processor->process($job, $csv, $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'import.job_started',
            'entity_type' => 'import_job',
            'entity_id'   => $job->id,
        ]);
    }

    // ── entity_type=users: provisions new User by username ───────────────────────

    public function test_process_users_creates_new_account_by_username(): void
    {
        $job = $this->makeJob(['entity_type' => 'users']);
        $csv = "username,display_name,audience_type\njdoe,Jane Doe,faculty";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals('completed', $job->status);
        $this->assertEquals(1, $job->processed_count);
        $this->assertDatabaseHas('users', ['username' => 'jdoe', 'display_name' => 'Jane Doe', 'audience_type' => 'faculty']);
        // Account should be provisioned with must_change_password=true
        $user = \App\Models\User::where('username', 'jdoe')->first();
        $this->assertTrue((bool) $user->must_change_password);
    }

    public function test_process_users_updates_existing_account_by_username(): void
    {
        \App\Models\User::factory()->create([
            'username'      => 'jdoe',
            'display_name'  => 'Jane Doe OLD',
            'audience_type' => 'staff',
        ]);

        $job = $this->makeJob(['entity_type' => 'users']);
        $csv = "username,display_name,audience_type\njdoe,Jane Doe UPDATED,faculty";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals('completed', $job->status);
        $this->assertDatabaseHas('users', ['username' => 'jdoe', 'display_name' => 'Jane Doe UPDATED', 'audience_type' => 'faculty']);
    }

    /**
     * Offline identity model: import NEVER changes an existing password.
     * The password hash must be identical before and after the import run.
     */
    public function test_process_users_never_modifies_existing_password(): void
    {
        $existingUser = \App\Models\User::factory()->create([
            'username'  => 'jdoe',
            'password'  => \Illuminate\Support\Facades\Hash::make('CorrectHorseBatteryStaple1!'),
        ]);
        $originalHash = $existingUser->fresh()->password;

        $job = $this->makeJob(['entity_type' => 'users']);
        // Row includes a password field — it must be silently ignored
        $csv = "username,display_name,password\njdoe,Jane Doe,NewHackedPassword99!";

        $processor = app(ImportProcessorService::class);
        $processor->process($job, $csv, $this->admin);

        $afterHash = \App\Models\User::where('username', 'jdoe')->value('password');
        $this->assertSame($originalHash, $afterHash, 'Import must not modify an existing user password.');
    }

    /**
     * Offline identity model: newly provisioned accounts require an admin
     * to set the credential — must_change_password=true signals this.
     */
    public function test_process_users_new_account_requires_admin_credential_set(): void
    {
        $job = $this->makeJob(['entity_type' => 'users']);
        $csv = "username,display_name\nnewstaff,New Staff Member";

        $processor = app(ImportProcessorService::class);
        $processor->process($job, $csv, $this->admin);

        $user = \App\Models\User::where('username', 'newstaff')->firstOrFail();

        // must_change_password=true — account cannot be considered usable until
        // an administrator sets the credential via the admin management surface.
        $this->assertTrue((bool) $user->must_change_password);

        // The stored hash is NOT the plain username or any predictable value.
        $this->assertFalse(
            \Illuminate\Support\Facades\Hash::check('newstaff', $user->password),
            'Provisioned password must not be a predictable value.'
        );
    }

    public function test_process_users_missing_display_name_fails_row(): void
    {
        $job = $this->makeJob(['entity_type' => 'users']);
        $csv = "username,display_name\njdoe,";  // display_name is empty

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals(1, $job->error_count);
        $this->assertEquals(0, $job->processed_count);
        $this->assertDatabaseMissing('users', ['username' => 'jdoe']);
    }

    // ── entity_type=user_profiles: resolves user_id via username column ──────────

    public function test_process_user_profiles_resolves_user_id_via_username(): void
    {
        // Pre-existing user account (created by a prior SSO sync or users import)
        $user = \App\Models\User::factory()->create(['username' => 'jsmith']);

        $job = $this->makeJob([
            'entity_type' => 'user_profiles',
            'file_format' => 'csv',
        ]);
        // Row carries username (SSO key) but no internal user_id
        $csv = "employee_id,username,job_title,employment_status\nEMP001,jsmith,Researcher,active";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals('completed', $job->status);
        $this->assertEquals(1, $job->processed_count);

        // employee_id is stored encrypted at rest (see UserProfile::casts()).
        // assertDatabaseHas() performs a raw SQL comparison against the encrypted
        // ciphertext and would never match a plain-text value.  Read through the
        // Eloquent model instead so the encrypted cast decrypts the value.
        $profile = \App\Models\UserProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile, 'A UserProfile row must exist for the imported user');
        $this->assertEquals('EMP001', $profile->employee_id, 'employee_id must decrypt to the imported value');
        $this->assertEquals('Researcher', $profile->job_title);
    }

    public function test_process_user_profiles_fails_row_when_user_unresolvable(): void
    {
        $job = $this->makeJob(['entity_type' => 'user_profiles']);
        // No matching user exists; row has no user_id or username
        $csv = "employee_id,job_title\nEMP999,Unknown Role";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        $this->assertEquals(1, $job->error_count);
        $this->assertEquals(0, $job->processed_count);
    }

    // ── employee_id blind-index matching (Issue 2) ───────────────────────────────

    /**
     * Verify that the blind-index hash is populated on create and that a
     * repeat import (update path) finds the existing profile via the hash.
     */
    public function test_process_user_profiles_repeat_import_matches_via_blind_index(): void
    {
        $user = \App\Models\User::factory()->create(['username' => 'jsmith']);

        // First import — creates the profile
        $job1 = $this->makeJob(['entity_type' => 'user_profiles']);
        $csv1 = "employee_id,username,job_title,employment_status\nEMP100,jsmith,Researcher,active";

        $processor = app(ImportProcessorService::class);
        $job1 = $processor->process($job1, $csv1, $this->admin);

        $this->assertEquals('completed', $job1->status);
        $this->assertEquals(1, $job1->processed_count);

        // Verify hash column is populated
        $profile = \App\Models\UserProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertNotNull($profile->employee_id_hash, 'employee_id_hash must be populated');
        $this->assertEquals(
            \App\Models\UserProfile::hashEmployeeId('EMP100'),
            $profile->employee_id_hash,
            'Hash must match the expected HMAC'
        );

        // Second import — same employee_id, different job_title → update path
        $job2 = $this->makeJob([
            'entity_type' => 'user_profiles',
            'conflict_resolution_strategy' => 'prefer_newest',
        ]);
        $csv2 = "employee_id,username,job_title,employment_status\nEMP100,jsmith,Senior Researcher,active";
        $job2 = $processor->process($job2, $csv2, $this->admin);

        $this->assertEquals('completed', $job2->status);
        // The profile should be updated, not duplicated
        $this->assertEquals(1, \App\Models\UserProfile::where('user_id', $user->id)->count());
        $this->assertEquals('Senior Researcher', $profile->fresh()->job_title);
    }

    /**
     * Verify that UserProfile::findByEmployeeId() returns the correct profile
     * when the column is encrypted at rest.
     */
    public function test_user_profile_find_by_employee_id_uses_blind_index(): void
    {
        $user = \App\Models\User::factory()->create(['username' => 'test_user']);
        $profile = \App\Models\UserProfile::create([
            'user_id'           => $user->id,
            'employee_id'       => 'BLIND-IDX-42',
            'employment_status' => 'active',
        ]);

        // findByEmployeeId must locate the profile via the hash
        $found = \App\Models\UserProfile::findByEmployeeId('BLIND-IDX-42');
        $this->assertNotNull($found);
        $this->assertEquals($profile->id, $found->id);
        $this->assertEquals('BLIND-IDX-42', $found->employee_id);

        // Non-matching plaintext must return null
        $this->assertNull(\App\Models\UserProfile::findByEmployeeId('NONEXISTENT'));
    }

    // ── service import uses admin-managed similarity threshold (Issue 7) ─────────

    public function test_service_import_uses_system_config_similarity_threshold(): void
    {
        // Set a very high threshold so the default (0.85) would match but this won't
        /** @var \App\Services\Admin\SystemConfigService $configSvc */
        $configSvc = app(\App\Services\Admin\SystemConfigService::class);
        $configSvc->set('import_similarity_threshold', '0.99');

        // Create an existing service (draft status avoids check constraints on published services)
        $admin = $this->admin;
        $service = \App\Models\Service::create([
            'uuid'        => (string) \Illuminate\Support\Str::uuid(),
            'title'       => 'Data Consultation',
            'slug'        => 'data-consultation',
            'status'      => 'draft',
            'is_free'     => true,
            'fee_amount'  => 0,
            'fee_currency' => 'USD',
            'requires_manual_confirmation' => false,
        ]);

        // Import with a slightly different title — at 0.99 threshold it should NOT match
        $job = $this->makeJob([
            'entity_type' => 'services',
            'conflict_resolution_strategy' => 'prefer_newest',
        ]);
        $csv = "title,description\nData Consultatoin,Spelling variant";

        $processor = app(ImportProcessorService::class);
        $job = $processor->process($job, $csv, $this->admin);

        // With 0.99 threshold, the slightly misspelled title should be treated as new
        $this->assertEquals('completed', $job->status);
        // Should now have 2 services (original + new)
        $this->assertEquals(2, \App\Models\Service::count());
    }
}
