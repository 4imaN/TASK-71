<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('username', 100)->unique();
            $table->string('display_name', 200);
            $table->string('password');
            $table->timestamp('password_changed_at')->nullable();

            // Brute-force / lockout
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            // Booking freeze (no-show policy)
            $table->timestamp('booking_freeze_until')->nullable();

            // Audience type for eligibility filtering
            $table->string('audience_type', 50)->nullable();

            // Account status
            $table->enum('status', ['active', 'locked', 'frozen', 'suspended'])->default('active');

            // Flags
            $table->boolean('must_change_password')->default(false);

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
    }
};
