<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents an admin-declared relationship type between two entity kinds.
 *
 * The set of allowed entity types is bounded — admins cannot introduce
 * arbitrary entity types, which ensures the schema stays stable.
 */
class RelationshipDefinition extends Model
{
    /**
     * The bounded set of entity types that may participate in relationships.
     * Extend this list if new domain entities are added; do NOT remove entries
     * that have existing instances.
     */
    public const ALLOWED_ENTITY_TYPES = [
        'service',
        'department',
        'user',
        'research_project',
        'tag',
        'target_audience',
        'service_category',
    ];

    public const ALLOWED_CARDINALITIES = [
        'many_to_many',
        'one_to_many',
    ];

    protected $fillable = [
        'name',
        'source_entity_type',
        'target_entity_type',
        'cardinality',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(EntityRelationshipInstance::class, 'definition_id');
    }

    public function activeInstances(): HasMany
    {
        return $this->hasMany(EntityRelationshipInstance::class, 'definition_id')
            ->whereNull('deleted_at');
    }
}
