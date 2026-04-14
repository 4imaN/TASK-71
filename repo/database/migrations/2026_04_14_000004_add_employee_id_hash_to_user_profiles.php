<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a blind-index column for employee_id exact-match lookups.
 *
 * employee_id is encrypted at rest (Laravel 'encrypted' cast) which produces
 * non-deterministic ciphertext — a simple WHERE clause can never match.
 * This column stores an HMAC-SHA256 of the plaintext employee_id, keyed by
 * APP_KEY, enabling deterministic lookups without exposing the value.
 *
 * The existing unique constraint on employee_id (ciphertext) is dropped
 * because encrypted values are non-deterministic and uniqueness cannot be
 * enforced at the ciphertext level. Uniqueness is now enforced on the
 * deterministic hash column instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('employee_id_hash', 64)->nullable()->after('employee_id');
            $table->unique('employee_id_hash');
        });

        // Drop the unique index on the encrypted employee_id column since
        // encrypted ciphertext is non-deterministic and cannot enforce uniqueness.
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropUnique(['employee_id']);
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropUnique(['employee_id_hash']);
            $table->dropColumn('employee_id_hash');
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->unique('employee_id');
        });
    }
};
