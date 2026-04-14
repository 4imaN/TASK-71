<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('entity_type', 60); // users, services, research_projects, departments, user_profiles, tags
            $table->string('source_system', 40)->nullable(); // sso | hr_finance | research_admin | custom
            $table->enum('file_format', ['csv', 'json']);
            $table->string('original_filename', 255)->nullable();
            $table->string('stored_path', 512)->nullable();

            // Field mapping: {source_column => target_field, ...}
            $table->json('field_mapping')->nullable();

            $table->enum('status', [
                'pending',
                'mapping',
                'validating',
                'processing',
                'needs_review',
                'completed',
                'failed',
            ])->default('pending');

            $table->timestamp('last_sync_timestamp')->nullable(); // for incremental sync
            $table->enum('conflict_resolution_strategy', ['prefer_newest', 'admin_override', 'pending'])
                ->default('pending');

            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);

            $table->text('error_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::create('import_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained()->cascadeOnDelete();
            $table->string('record_identifier', 255);
            $table->json('local_record')->nullable();
            $table->json('incoming_record')->nullable();
            $table->json('field_diffs')->nullable(); // [{field, local_value, incoming_value}]
            $table->enum('resolution', ['pending', 'prefer_newest', 'admin_override'])->default('pending');
            $table->json('resolved_record')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['import_job_id', 'resolution']);
        });

        Schema::create('import_field_mapping_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('source_system', 40)->nullable(); // sso | hr_finance | research_admin | custom
            $table->string('entity_type', 60);
            $table->json('field_mapping');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entity_type', 'source_system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_field_mapping_templates');
        Schema::dropIfExists('import_conflicts');
        Schema::dropIfExists('import_jobs');
    }
};
