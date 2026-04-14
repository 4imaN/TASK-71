<?php

namespace App\Services\Admin;

use App\Models\Department;
use App\Models\EntityRelationshipInstance;
use App\Models\RelationshipDefinition;
use App\Models\ResearchProject;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tag;
use App\Models\TargetAudience;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Manages the lifecycle of admin-configurable relationship definitions and
 * their runtime instances.
 *
 * Design constraints:
 *   - No schema mutations: all data is stored in the two relationship tables.
 *   - Entity types are bounded to RelationshipDefinition::ALLOWED_ENTITY_TYPES.
 *   - Instances use soft-delete so history is auditable.
 */
class RelationshipManagerService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    // ── Definitions ───────────────────────────────────────────────────────────

    public function allDefinitions(): Collection
    {
        return RelationshipDefinition::withCount(['activeInstances as instance_count'])
            ->orderBy('source_entity_type')
            ->orderBy('target_entity_type')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a new relationship definition.
     *
     * @throws \InvalidArgumentException if entity types are not in the allowed list.
     */
    public function createDefinition(array $data, User $admin): RelationshipDefinition
    {
        $this->validateEntityTypes($data['source_entity_type'], $data['target_entity_type']);

        $definition = RelationshipDefinition::create([
            'name'               => $data['name'],
            'source_entity_type' => $data['source_entity_type'],
            'target_entity_type' => $data['target_entity_type'],
            'cardinality'        => $data['cardinality'] ?? 'many_to_many',
            'is_active'          => true,
            'created_by'         => $admin->id,
        ]);

        $this->auditLogger->log(
            action:     'admin.relationship_definition_created',
            actorId:    $admin->id,
            entityType: 'relationship_definition',
            entityId:   $definition->id,
            afterState: $definition->toArray(),
        );

        return $definition;
    }

    /**
     * Deactivate a relationship definition (soft disable — does not remove instances).
     */
    public function deactivateDefinition(RelationshipDefinition $definition, User $admin): RelationshipDefinition
    {
        $before = $definition->toArray();
        $definition->update(['is_active' => false]);
        $definition->refresh();

        $this->auditLogger->log(
            action:     'admin.relationship_definition_deactivated',
            actorId:    $admin->id,
            entityType: 'relationship_definition',
            entityId:   $definition->id,
            beforeState: $before,
            afterState:  $definition->toArray(),
        );

        return $definition;
    }

    // ── Instances ─────────────────────────────────────────────────────────────

    /**
     * List active (non-soft-deleted) instances for a definition.
     */
    public function listInstances(RelationshipDefinition $definition): Collection
    {
        return EntityRelationshipInstance::where('definition_id', $definition->id)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Create a link between two entity instances under the given definition.
     *
     * Idempotent: if a soft-deleted instance with the same pair exists, it is
     * restored rather than creating a duplicate row.
     *
     * @throws \InvalidArgumentException if the definition is inactive, if either
     *         entity record does not exist, or if a one_to_many cardinality
     *         constraint would be violated.
     */
    public function createInstance(
        RelationshipDefinition $definition,
        int $sourceId,
        int $targetId,
        User $admin,
    ): EntityRelationshipInstance {
        if (!$definition->is_active) {
            throw new \InvalidArgumentException(
                "Cannot create an instance under an inactive relationship definition."
            );
        }

        // Validate that the referenced entity records actually exist.
        $this->assertEntityExists($definition->source_entity_type, $sourceId);
        $this->assertEntityExists($definition->target_entity_type, $targetId);

        // Enforce one_to_many cardinality: the source entity may only have one
        // active target per definition.
        if ($definition->cardinality === 'one_to_many') {
            $existing = EntityRelationshipInstance::where('definition_id', $definition->id)
                ->where('source_id', $sourceId)
                ->whereNull('deleted_at')
                ->where('target_id', '!=', $targetId)
                ->exists();

            if ($existing) {
                throw new \InvalidArgumentException(
                    "Cardinality violation: this source entity already has a target linked under " .
                    "the '{$definition->name}' definition (one_to_many allows only one target per source)."
                );
            }
        }

        // Restore soft-deleted instance if present, otherwise create
        $instance = EntityRelationshipInstance::withTrashed()
            ->where('definition_id', $definition->id)
            ->where('source_id', $sourceId)
            ->where('target_id', $targetId)
            ->first();

        if ($instance) {
            $instance->restore();
            $instance->update(['created_by' => $admin->id]);
        } else {
            $instance = EntityRelationshipInstance::create([
                'definition_id' => $definition->id,
                'source_id'     => $sourceId,
                'target_id'     => $targetId,
                'created_by'    => $admin->id,
            ]);
        }

        $this->auditLogger->log(
            action:     'admin.relationship_instance_created',
            actorId:    $admin->id,
            entityType: 'entity_relationship_instance',
            entityId:   $instance->id,
            metadata: [
                'definition_id'      => $definition->id,
                'definition_name'    => $definition->name,
                'source_entity_type' => $definition->source_entity_type,
                'target_entity_type' => $definition->target_entity_type,
                'source_id'          => $sourceId,
                'target_id'          => $targetId,
            ],
        );

        return $instance;
    }

    /**
     * Remove (soft-delete) a relationship instance.
     */
    public function deleteInstance(EntityRelationshipInstance $instance, User $admin): void
    {
        $instance->delete();

        $this->auditLogger->log(
            action:     'admin.relationship_instance_deleted',
            actorId:    $admin->id,
            entityType: 'entity_relationship_instance',
            entityId:   $instance->id,
            metadata: [
                'definition_id' => $instance->definition_id,
                'source_id'     => $instance->source_id,
                'target_id'     => $instance->target_id,
            ],
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validateEntityTypes(string $source, string $target): void
    {
        $allowed = RelationshipDefinition::ALLOWED_ENTITY_TYPES;

        if (!in_array($source, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Entity type '{$source}' is not in the allowed entity type list."
            );
        }

        if (!in_array($target, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Entity type '{$target}' is not in the allowed entity type list."
            );
        }
    }

    /**
     * Map an entity type slug to its Eloquent model class.
     *
     * @return class-string<Model>
     */
    private function entityTypeToModel(string $entityType): string
    {
        return match ($entityType) {
            'service'          => Service::class,
            'department'       => Department::class,
            'user'             => User::class,
            'research_project' => ResearchProject::class,
            'tag'              => Tag::class,
            'target_audience'  => TargetAudience::class,
            'service_category' => ServiceCategory::class,
            default            => throw new \InvalidArgumentException(
                "No model mapping found for entity type '{$entityType}'."
            ),
        };
    }

    /**
     * Assert that an entity of the given type with the given primary key exists.
     *
     * @throws \InvalidArgumentException if the record is not found.
     */
    private function assertEntityExists(string $entityType, int $id): void
    {
        $modelClass = $this->entityTypeToModel($entityType);

        if (!$modelClass::find($id)) {
            throw new \InvalidArgumentException(
                "Entity of type '{$entityType}' with id {$id} does not exist."
            );
        }
    }
}
