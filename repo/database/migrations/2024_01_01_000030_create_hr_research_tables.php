<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting tables for import/export exchange with on-prem systems:
 * - HR/finance extracts (departments, user_profiles)
 * - Research administration records (research_projects)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 200);
            $table->foreignId('parent_department_id')->nullable()
                ->constrained('departments')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('employee_id', 60)->nullable()->unique();
            $table->foreignId('department_id')->nullable()
                ->constrained('departments')->nullOnDelete();
            $table->string('cost_center', 60)->nullable();
            $table->string('job_title', 150)->nullable();
            // employment_classification links to data_dictionary_values (audience_type)
            $table->foreignId('employment_classification_id')->nullable()
                ->constrained('data_dictionary_values')->nullOnDelete();
            $table->enum('employment_status', ['active', 'on_leave', 'terminated'])->default('active');
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('research_projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_number', 80)->unique();
            $table->string('title', 300);
            $table->string('normalized_title', 300)->nullable(); // lowercased, stripped for similarity
            $table->string('principal_investigator_name', 200)->nullable();
            $table->foreignId('department_id')->nullable()
                ->constrained('departments')->nullOnDelete();
            $table->string('grant_id', 100)->nullable()->index();
            $table->string('patent_number', 100)->nullable()->index();
            $table->foreignId('project_status_id')->nullable()
                ->constrained('data_dictionary_values')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('service_research_project_links', function (Blueprint $table) {
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('research_project_id')->constrained()->cascadeOnDelete();
            $table->primary(['service_id', 'research_project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_research_project_links');
        Schema::dropIfExists('research_projects');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('departments');
    }
};
