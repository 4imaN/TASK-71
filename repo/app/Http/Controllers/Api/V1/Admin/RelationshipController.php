<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\EntityRelationshipInstance;
use App\Models\RelationshipDefinition;
use App\Services\Admin\RelationshipManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Admin REST endpoints for configuring entity relationship definitions and
 * managing their runtime instances.
 *
 * Definition endpoints (require 'role:administrator'):
 *   GET    /api/v1/admin/relationship-definitions              → list all
 *   POST   /api/v1/admin/relationship-definitions              → create
 *   DELETE /api/v1/admin/relationship-definitions/{id}         → deactivate
 *
 * Instance endpoints (require 'role:administrator'):
 *   GET    /api/v1/admin/relationship-definitions/{id}/instances         → list active
 *   POST   /api/v1/admin/relationship-definitions/{id}/instances         → link entities
 *   DELETE /api/v1/admin/relationship-definitions/{id}/instances/{iid}   → unlink
 */
class RelationshipController extends Controller
{
    public function __construct(
        private readonly RelationshipManagerService $service,
    ) {}

    // ── Definitions ───────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/relationship-definitions
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'definitions'         => $this->service->allDefinitions(),
            'allowed_entity_types' => RelationshipDefinition::ALLOWED_ENTITY_TYPES,
            'allowed_cardinalities' => RelationshipDefinition::ALLOWED_CARDINALITIES,
        ]);
    }

    /**
     * POST /api/v1/admin/relationship-definitions
     */
    public function store(Request $request): JsonResponse
    {
        $allowed = RelationshipDefinition::ALLOWED_ENTITY_TYPES;

        $data = $request->validate([
            'name'               => ['required', 'string', 'max:120'],
            'source_entity_type' => ['required', 'string', Rule::in($allowed)],
            'target_entity_type' => ['required', 'string', Rule::in($allowed)],
            'cardinality'        => ['sometimes', 'string', Rule::in(RelationshipDefinition::ALLOWED_CARDINALITIES)],
        ]);

        try {
            $definition = $this->service->createDefinition($data, Auth::user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['definition' => $definition], 201);
    }

    /**
     * DELETE /api/v1/admin/relationship-definitions/{id}
     *
     * Deactivates the definition (does not remove existing instances).
     */
    public function destroy(int $id): JsonResponse
    {
        $definition = RelationshipDefinition::findOrFail($id);

        $definition = $this->service->deactivateDefinition($definition, Auth::user());

        return response()->json(['definition' => $definition]);
    }

    // ── Instances ─────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/relationship-definitions/{id}/instances
     */
    public function listInstances(int $id): JsonResponse
    {
        $definition = RelationshipDefinition::findOrFail($id);

        return response()->json([
            'definition' => $definition,
            'instances'  => $this->service->listInstances($definition),
        ]);
    }

    /**
     * POST /api/v1/admin/relationship-definitions/{id}/instances
     *
     * Body: { "source_id": int, "target_id": int }
     * Links two entity instances under this definition.
     * Idempotent — restores a soft-deleted instance rather than failing.
     */
    public function storeInstance(Request $request, int $id): JsonResponse
    {
        $definition = RelationshipDefinition::findOrFail($id);

        $data = $request->validate([
            'source_id' => ['required', 'integer', 'min:1'],
            'target_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $instance = $this->service->createInstance(
                $definition,
                (int) $data['source_id'],
                (int) $data['target_id'],
                Auth::user(),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['instance' => $instance], 201);
    }

    /**
     * DELETE /api/v1/admin/relationship-definitions/{id}/instances/{instanceId}
     *
     * Soft-deletes the relationship instance (unlinks the two entities).
     */
    public function destroyInstance(int $id, int $instanceId): JsonResponse
    {
        $instance = EntityRelationshipInstance::where('id', $instanceId)
            ->where('definition_id', $id)
            ->firstOrFail();

        $this->service->deleteInstance($instance, Auth::user());

        return response()->json(null, 204);
    }
}
