<?php

namespace App\Services\Editor;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Service;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Str;

/**
 * Business logic for content editors to create, update, publish,
 * and archive services.
 *
 * All write operations are audited.
 */
class ServiceEditorService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Create a new service with status='draft'.
     * Syncs tag_ids and audience_ids, generates a unique slug.
     */
    public function create(User $editor, array $data): Service
    {
        $slug = $this->generateSlug($data['title']);

        $service = Service::create([
            'uuid'                         => (string) Str::uuid(),
            'title'                        => $data['title'],
            'slug'                         => $slug,
            'description'                  => $data['description'] ?? null,
            'eligibility_notes'            => $data['eligibility_notes'] ?? null,
            'category_id'                  => $data['category_id'] ?? null,
            'service_type_id'              => $data['service_type_id'] ?? null,
            'is_free'                      => $data['is_free'] ?? true,
            'fee_amount'                   => $data['fee_amount'] ?? 0,
            'fee_currency'                 => $data['fee_currency'] ?? 'USD',
            'requires_manual_confirmation' => $data['requires_manual_confirmation'] ?? false,
            'status'                       => 'draft',
            'created_by'                   => $editor->id,
            'updated_by'                   => $editor->id,
        ]);

        if (!empty($data['tag_ids'])) {
            $service->tags()->sync($data['tag_ids']);
        }

        if (!empty($data['audience_ids'])) {
            $service->audiences()->sync($data['audience_ids']);
        }

        if (array_key_exists('research_project_ids', $data)) {
            $service->researchProjects()->sync($data['research_project_ids'] ?? []);
        }

        $this->auditLogger->log(
            action: 'service.created',
            actorId: $editor->id,
            entityType: 'service',
            entityId: $service->id,
            afterState: $service->toArray(),
        );

        return $service;
    }

    /**
     * Update an existing service. Partial updates allowed.
     * Regenerates slug only if title changed.
     * Re-syncs tags/audiences only if provided.
     */
    public function update(Service $service, User $editor, array $data): Service
    {
        $beforeState = $service->toArray();

        $fillable = [
            'description', 'eligibility_notes', 'category_id',
            'service_type_id', 'is_free', 'fee_amount', 'fee_currency',
            'requires_manual_confirmation', 'status',
        ];

        $updates = [];

        if (array_key_exists('title', $data)) {
            $updates['title'] = $data['title'];
            if ($data['title'] !== $service->title) {
                $updates['slug'] = $this->generateSlug($data['title'], $service->id);
            }
        }

        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        $updates['updated_by'] = $editor->id;

        $service->update($updates);

        if (array_key_exists('tag_ids', $data)) {
            $service->tags()->sync($data['tag_ids'] ?? []);
        }

        if (array_key_exists('audience_ids', $data)) {
            $service->audiences()->sync($data['audience_ids'] ?? []);
        }

        if (array_key_exists('research_project_ids', $data)) {
            $service->researchProjects()->sync($data['research_project_ids'] ?? []);
        }

        $service->refresh();

        $this->auditLogger->log(
            action: 'service.updated',
            actorId: $editor->id,
            entityType: 'service',
            entityId: $service->id,
            beforeState: $beforeState,
            afterState: $service->toArray(),
        );

        return $service;
    }

    /**
     * Transition a service from draft/inactive to active.
     * Throws InvalidStateTransitionException for archived services.
     * Idempotent if already active.
     */
    public function publish(Service $service, User $editor): Service
    {
        if ($service->status === 'archived') {
            throw new InvalidStateTransitionException(
                "Cannot publish an archived service (id={$service->id})."
            );
        }

        if ($service->status === 'active') {
            return $service;
        }

        $beforeState = $service->toArray();

        $service->update([
            'status'     => 'active',
            'updated_by' => $editor->id,
        ]);

        $service->refresh();

        $this->auditLogger->log(
            action: 'service.published',
            actorId: $editor->id,
            entityType: 'service',
            entityId: $service->id,
            beforeState: $beforeState,
            afterState: $service->toArray(),
        );

        return $service;
    }

    /**
     * Archive a service. Idempotent if already archived.
     */
    public function archive(Service $service, User $editor): Service
    {
        if ($service->status === 'archived') {
            return $service;
        }

        $beforeState = $service->toArray();

        $service->update([
            'status'     => 'archived',
            'updated_by' => $editor->id,
        ]);

        $service->refresh();

        $this->auditLogger->log(
            action: 'service.archived',
            actorId: $editor->id,
            entityType: 'service',
            entityId: $service->id,
            beforeState: $beforeState,
            afterState: $service->toArray(),
        );

        return $service;
    }

    /**
     * Generate a unique slug from a title.
     * Appends -2, -3, ... until unique.
     * Uses withTrashed() to avoid soft-deleted collisions.
     *
     * @param string   $title
     * @param int|null $excludeId  ID of the service to exclude (for updates)
     */
    public function generateSlug(string $title, ?int $excludeId = null): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $counter = 2;

        while (true) {
            $query = Service::withTrashed()->where('slug', $slug);

            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }

            if (!$query->exists()) {
                break;
            }

            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
