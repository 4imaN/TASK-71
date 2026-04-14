<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies that all domain tables exist and have the expected core columns.
 * Uses RefreshDatabase so each run gets a clean schema.
 */
class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_user_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('password_history'));
        $this->assertTrue(Schema::hasTable('sessions'));
    }

    public function test_users_table_has_required_columns(): void
    {
        foreach (['id', 'uuid', 'username', 'password', 'status', 'failed_attempts', 'audience_type'] as $col) {
            $this->assertTrue(Schema::hasColumn('users', $col), "Missing column: {$col}");
        }
    }

    public function test_rbac_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasTable('permissions'));
        $this->assertTrue(Schema::hasTable('model_has_roles'));
    }

    public function test_audit_log_table_exists_and_is_immutable(): void
    {
        $this->assertTrue(Schema::hasTable('audit_logs'));

        // Attempt to delete — should be silently blocked by PostgreSQL rule
        DB::table('audit_logs')->insert([
            'action'      => 'test.action',
            'actor_type'  => 'system',
            'occurred_at' => now(),
        ]);

        $id = DB::table('audit_logs')->where('action', 'test.action')->value('id');
        $this->assertNotNull($id);

        // DELETE should be blocked by PostgreSQL rule (no rows affected, no exception)
        $deleted = DB::table('audit_logs')->where('id', $id)->delete();
        $this->assertEquals(0, $deleted, 'Audit log rows must not be deletable');

        // UPDATE should be blocked too
        DB::table('audit_logs')->where('id', $id)->update(['action' => 'tampered']);
        $action = DB::table('audit_logs')->where('id', $id)->value('action');
        $this->assertEquals('test.action', $action, 'Audit log rows must not be updatable');
    }

    public function test_catalog_tables_exist(): void
    {
        foreach (['service_categories', 'tags', 'services', 'target_audiences', 'service_tags', 'service_audiences'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_reservation_tables_exist(): void
    {
        foreach (['time_slots', 'reservations', 'reservation_status_history', 'no_show_breaches', 'booking_freezes', 'points_ledger'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_import_export_tables_exist(): void
    {
        foreach (['import_jobs', 'import_conflicts', 'import_field_mapping_templates'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_backup_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('backup_logs'));
        $this->assertTrue(Schema::hasTable('restore_test_logs'));
    }

    public function test_system_config_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('system_config'));
        $this->assertTrue(Schema::hasTable('data_dictionary_types'));
        $this->assertTrue(Schema::hasTable('data_dictionary_values'));
        $this->assertTrue(Schema::hasTable('form_rules'));
    }

    /**
     * The user_profiles table must include the employee_id_hash blind-index
     * column used for deterministic lookups against the encrypted employee_id.
     */
    public function test_user_profiles_has_employee_id_hash_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('user_profiles', 'employee_id_hash'),
            'user_profiles must have employee_id_hash for blind-index lookups'
        );
    }

    /**
     * Static verification that the PostgreSQL connection config file
     * defaults to verify-ca sslmode when no env override is present.
     * In the test environment DB_SSLMODE is overridden to 'prefer' by
     * phpunit.xml, so we read the raw config file to verify the code-level
     * default that ships in production.
     */
    public function test_pgsql_config_file_defaults_to_verify_ca_sslmode(): void
    {
        // Read the raw config file and evaluate the env() default
        $configPath = base_path('config/database.php');
        $this->assertFileExists($configPath);

        $content = file_get_contents($configPath);
        // The config line should contain: env('DB_SSLMODE', 'verify-ca')
        $this->assertStringContainsString(
            "'verify-ca'",
            $content,
            'config/database.php must default pgsql sslmode to verify-ca'
        );
    }
}
