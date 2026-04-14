<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A concrete link between two entity instances under a registered
 * RelationshipDefinition.
 *
 * Uses soft-delete so that link history is preserved and relationship
 * removals are auditable.
 */
class EntityRelationshipInstance extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'definition_id',
        'source_id',
        'target_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'target_id' => 'integer',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(RelationshipDefinition::class, 'definition_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
