<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_logs', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_filename', 255);
            $table->string('snapshot_path', 512);
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->enum('type', ['daily', 'manual'])->default('daily');
            $table->enum('status', ['success', 'failed'])->default('success');
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('restore_test_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_log_id')->nullable()
                ->constrained('backup_logs')->nullOnDelete();
            $table->foreignId('tested_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->enum('test_result', ['success', 'partial', 'failed']);
            $table->text('notes')->nullable();
            $table->timestamp('tested_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restore_test_logs');
        Schema::dropIfExists('backup_logs');
    }
};
