<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log table — append-only by application contract.
 *
 * PostgreSQL-level immutability is applied via rules after table creation
 * (see AuditImmutabilitySeeder / AuditImmutabilityMigration).
 *
 * The application DB user must NOT have TRUNCATE on this table in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('correlation_id')->nullable()->index();

            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 20)->default('user'); // user | system

            // Action identifier, e.g. auth.login.success, reservation.cancelled
            $table->string('action', 100)->index();

            $table->string('entity_type', 80)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();

            // Sensitive fields in before/after are replaced with [REDACTED] before insert
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('device_fingerprint', 64)->nullable();
            $table->json('metadata')->nullable();

            // Immutable timestamp — no updated_at to avoid tooling that auto-touches it
            $table->timestampTz('occurred_at')->index();

            $table->index(['actor_id', 'occurred_at']);
            $table->index(['entity_type', 'entity_id']);
        });

        // Apply PostgreSQL immutability rules (skipped on other drivers, e.g. SQLite in tests)
        // The app user cannot UPDATE or DELETE audit_logs rows
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE RULE no_update_audit_logs AS ON UPDATE TO audit_logs DO INSTEAD NOTHING');
            DB::statement('CREATE RULE no_delete_audit_logs AS ON DELETE TO audit_logs DO INSTEAD NOTHING');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP RULE IF EXISTS no_update_audit_logs ON audit_logs');
            DB::statement('DROP RULE IF EXISTS no_delete_audit_logs ON audit_logs');
        }
        Schema::dropIfExists('audit_logs');
    }
};
