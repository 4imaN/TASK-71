<?php

namespace App\Http\Livewire\Admin;

use App\Models\EntityRelationshipInstance;
use App\Models\RelationshipDefinition;
use App\Services\Admin\RelationshipManagerService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin UI for configuring entity relationship definitions and managing
 * their runtime instances.
 *
 * Definitions panel: create / deactivate relationship types between entity kinds.
 * Instances panel: link and unlink concrete entity pairs under a definition.
 *
 * Route: GET /admin/relationships  (role:administrator)
 */
#[Layout('layouts.app')]
class RelationshipManagerComponent extends Component
{
    // ── Definition form ───────────────────────────────────────────────────────

    public string $defName               = '';
    public string $defSourceEntityType   = '';
    public string $defTargetEntityType   = '';
    public string $defCardinality        = 'many_to_many';
    public bool   $showDefinitionForm    = false;

    // ── Instance panel ────────────────────────────────────────────────────────

    public ?int   $selectedDefinitionId  = null;
    public string $instanceSourceId      = '';
    public string $instanceTargetId      = '';
    public bool   $showInstanceForm      = false;

    // ── Flash ─────────────────────────────────────────────────────────────────

    public string $flashMessage = '';
    public string $flashType    = 'success';

    // ── Computed data ─────────────────────────────────────────────────────────

    public function getDefinitionsProperty()
    {
        return RelationshipDefinition::withCount(['activeInstances as instance_count'])
            ->orderBy('source_entity_type')
            ->orderBy('target_entity_type')
            ->orderBy('name')
            ->get();
    }

    public function getSelectedDefinitionProperty(): ?RelationshipDefinition
    {
        if ($this->selectedDefinitionId === null) {
            return null;
        }
        return RelationshipDefinition::find($this->selectedDefinitionId);
    }

    public function getInstancesProperty()
    {
        if ($this->selectedDefinitionId === null) {
            return collect();
        }
        return EntityRelationshipInstance::where('definition_id', $this->selectedDefinitionId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getAllowedEntityTypesProperty(): array
    {
        return RelationshipDefinition::ALLOWED_ENTITY_TYPES;
    }

    // ── Definition actions ────────────────────────────────────────────────────

    public function saveDefinition(RelationshipManagerService $service): void
    {
        $this->validate([
            'defName'             => ['required', 'string', 'max:120'],
            'defSourceEntityType' => ['required', 'string', 'in:' . implode(',', RelationshipDefinition::ALLOWED_ENTITY_TYPES)],
            'defTargetEntityType' => ['required', 'string', 'in:' . implode(',', RelationshipDefinition::ALLOWED_ENTITY_TYPES)],
            'defCardinality'      => ['required', 'string', 'in:' . implode(',', RelationshipDefinition::ALLOWED_CARDINALITIES)],
        ]);

        try {
            $service->createDefinition([
                'name'               => $this->defName,
                'source_entity_type' => $this->defSourceEntityType,
                'target_entity_type' => $this->defTargetEntityType,
                'cardinality'        => $this->defCardinality,
            ], Auth::user());

            $this->resetDefinitionForm();
            $this->flash('Relationship definition created.', 'success');
        } catch (\Throwable $e) {
            $this->flash('Error: ' . $e->getMessage(), 'error');
        }
    }

    public function deactivateDefinition(int $id, RelationshipManagerService $service): void
    {
        $definition = RelationshipDefinition::findOrFail($id);

        try {
            $service->deactivateDefinition($definition, Auth::user());

            if ($this->selectedDefinitionId === $id) {
                $this->selectedDefinitionId = null;
            }

            $this->flash('Definition deactivated.', 'success');
        } catch (\Throwable $e) {
            $this->flash('Error: ' . $e->getMessage(), 'error');
        }
    }

    public function selectDefinition(int $id): void
    {
        $this->selectedDefinitionId = $id;
        $this->showInstanceForm     = false;
        $this->instanceSourceId     = '';
        $this->instanceTargetId     = '';
    }

    // ── Instance actions ──────────────────────────────────────────────────────

    public function linkEntities(RelationshipManagerService $service): void
    {
        $this->validate([
            'instanceSourceId' => ['required', 'integer', 'min:1'],
            'instanceTargetId' => ['required', 'integer', 'min:1'],
        ]);

        $definition = RelationshipDefinition::findOrFail($this->selectedDefinitionId);

        try {
            $service->createInstance(
                $definition,
                (int) $this->instanceSourceId,
                (int) $this->instanceTargetId,
                Auth::user(),
            );

            $this->instanceSourceId = '';
            $this->instanceTargetId = '';
            $this->showInstanceForm = false;
            $this->flash('Entities linked successfully.', 'success');
        } catch (\Throwable $e) {
            $this->flash('Error: ' . $e->getMessage(), 'error');
        }
    }

    public function unlinkInstance(int $instanceId, RelationshipManagerService $service): void
    {
        $instance = EntityRelationshipInstance::findOrFail($instanceId);

        try {
            $service->deleteInstance($instance, Auth::user());
            $this->flash('Link removed.', 'success');
        } catch (\Throwable $e) {
            $this->flash('Error: ' . $e->getMessage(), 'error');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function resetDefinitionForm(): void
    {
        $this->defName             = '';
        $this->defSourceEntityType = '';
        $this->defTargetEntityType = '';
        $this->defCardinality      = 'many_to_many';
        $this->showDefinitionForm  = false;
        $this->resetValidation();
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType    = $type;
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.admin.relationship-manager', [
            'definitions'        => $this->definitions,
            'selectedDefinition' => $this->selectedDefinition,
            'instances'          => $this->instances,
            'allowedEntityTypes' => $this->allowedEntityTypes,
        ]);
    }
}
