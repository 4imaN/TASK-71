<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime instances of admin-defined entity relationships.
 *
 * Each row links a specific source entity (by its PK) to a specific target
 * entity (by its PK) under a registered relationship_definition.  The schema
 * is generic — entity type is known from the definition row — so no new tables
 * or columns are needed when admins register a new relationship type.
 *
 * Soft-deletion is used instead of hard-DELETE so that relationship history
 * is preserved in audit trails and can be restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_relationship_instances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('definition_id')
                ->constrained('relationship_definitions')
                ->cascadeOnDelete();

            // Primary keys of the linked entities.  Integer PKs cover all
            // current domain models (User, Service, Department, etc.).
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('target_id');

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Each source–target pair may only be linked once per definition
            $table->unique(['definition_id', 'source_id', 'target_id'], 'rel_instance_unique');

            $table->index(['definition_id', 'source_id']);
            $table->index(['definition_id', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_relationship_instances');
    }
};
