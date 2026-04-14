<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relationship definition registry.
 *
 * Admins use this table to declare that two entity types may be associated
 * at runtime — for example, "a Service can be linked to a Department".
 * No schema mutations are required; the definition only records metadata
 * about a logical association.  Actual instance links are stored in
 * entity_relationship_instances using a generic pivot pattern.
 *
 * Allowed entity types are enforced at the application layer (not by a
 * foreign key), keeping the schema stable while allowing new pairings to
 * be registered without migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationship_definitions', function (Blueprint $table) {
            $table->id();

            // Human-readable label shown in the admin UI
            $table->string('name', 120);

            // The two entity types participating in this relationship.
            // Values are drawn from RelationshipDefinition::ALLOWED_ENTITY_TYPES.
            $table->string('source_entity_type', 80);
            $table->string('target_entity_type', 80);

            // Cardinality hint — informational only; enforcement is optional at
            // the application layer (the instances table does not enforce it).
            // 'many_to_many' | 'one_to_many'
            $table->string('cardinality', 20)->default('many_to_many');

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            // Prevent duplicate definitions for the same entity pair + name
            $table->unique(['source_entity_type', 'target_entity_type', 'name'], 'rel_def_unique');

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_definitions');
    }
};
