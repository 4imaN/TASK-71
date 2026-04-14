<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('slug', 80)->unique();
            $table->timestamps();
        });

        Schema::create('target_audiences', function (Blueprint $table) {
            $table->id();
            // e.g. faculty, staff, graduate_learner, undergraduate_learner
            $table->string('code', 60)->unique();
            $table->string('label', 150);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 200)->unique();
            $table->string('title', 250);
            $table->text('description')->nullable();
            $table->text('eligibility_notes')->nullable();

            $table->foreignId('category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->foreignId('service_type_id')->nullable()->constrained('data_dictionary_values')->nullOnDelete();

            $table->boolean('is_free')->default(true);
            $table->decimal('fee_amount', 10, 2)->default(0);
            $table->string('fee_currency', 3)->default('USD');
            $table->boolean('requires_manual_confirmation')->default(false);

            $table->enum('status', ['draft', 'active', 'inactive', 'archived'])->default('draft');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_free']);
            $table->index('category_id');
        });

        Schema::create('service_tags', function (Blueprint $table) {
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['service_id', 'tag_id']);
        });

        Schema::create('service_audiences', function (Blueprint $table) {
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audience_id')->constrained('target_audiences')->cascadeOnDelete();
            $table->primary(['service_id', 'audience_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_audiences');
        Schema::dropIfExists('service_tags');
        Schema::dropIfExists('services');
        Schema::dropIfExists('target_audiences');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('service_categories');
    }
};
