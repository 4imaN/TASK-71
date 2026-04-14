<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen user_profiles.employee_id from varchar(60) to TEXT.
 *
 * The original varchar(60) was sized for plain-text values.  In Round 1
 * the column was given an 'encrypted' cast so that employee IDs are stored
 * as AES-256-CBC ciphertexts.  An encrypted value is ~300 characters after
 * base64 encoding, which exceeds varchar(60) and causes a PostgreSQL
 * "value too long for type character varying(60)" error at INSERT time.
 *
 * Changing to TEXT removes the arbitrary length cap while preserving the
 * nullable and unique constraints.  The existing unique index is automatically
 * preserved by PostgreSQL when only the column type is altered; specifying
 * ->unique() inside a ->change() call would attempt to CREATE a second unique
 * constraint on the same column and fail with "constraint already exists".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            // Change column type only.  Do NOT chain ->unique() here — the
            // existing unique index is preserved automatically by PostgreSQL.
            $table->text('employee_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Restoring to varchar(60) would truncate any encrypted values already
        // stored.  This down() path is provided for completeness but should
        // only be run on a fresh test database.
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('employee_id', 60)->nullable()->change();
        });
    }
};
