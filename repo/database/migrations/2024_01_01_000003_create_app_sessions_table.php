<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom session table that extends Laravel's default session tracking
 * with device fingerprinting and single-logout revocation support.
 *
 * This supplements (replaces) the default 'sessions' table used by
 * the database session driver, adding security metadata fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();

            // Extended fields for security tracking
            $table->string('device_fingerprint', 64)->nullable()->index();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
