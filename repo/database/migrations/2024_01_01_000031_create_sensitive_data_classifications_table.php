<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensitive_data_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 80);
            $table->string('field_name', 80);
            $table->enum('classification', ['pii', 'confidential', 'internal'])->default('internal');
            $table->enum('mask_pattern', ['full', 'partial_last4', 'partial_first2last4', 'hash'])
                ->default('full');
            $table->boolean('encrypt_at_rest')->default(false);
            $table->timestamps();

            $table->unique(['entity_type', 'field_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensitive_data_classifications');
    }
};
