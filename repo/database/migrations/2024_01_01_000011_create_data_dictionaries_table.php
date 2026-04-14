<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_dictionary_types', function (Blueprint $table) {
            $table->id();
            // Closed set of codes — new types require code changes, not admin UI
            $table->string('code', 60)->unique();
            $table->string('label', 150);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(true); // system types cannot be deleted
            $table->timestamps();
        });

        Schema::create('data_dictionary_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('type_id')->constrained('data_dictionary_types')->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('label', 200);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type_id', 'key']);
            $table->index(['type_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_dictionary_values');
        Schema::dropIfExists('data_dictionary_types');
    }
};
