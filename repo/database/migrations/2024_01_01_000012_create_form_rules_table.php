<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_rules', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 80); // service, reservation, user, time_slot, ...
            $table->string('field_name', 80);
            // rules JSON: {required, min_length, max_length, min_value, max_value, regex, allowed_values[]}
            // Dynamic rules can tighten but never override hard-coded safety rules.
            $table->json('rules');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['entity_type', 'field_name']);
            $table->index(['entity_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_rules');
    }
};
