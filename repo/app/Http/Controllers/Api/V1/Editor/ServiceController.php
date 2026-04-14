<?php

namespace App\Http\Controllers\Api\V1\Editor;

use App\Exceptions\InvalidStateTransitionException;
use App\Http\Controllers\Controller;
use App\Models\ResearchProject;
use App\Models\Service;
use App\Services\Admin\DynamicValidationResolver;
use App\Services\Editor\ServiceEditorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * REST endpoints for editor service management.
 *
 * All business logic is delegated to ServiceEditorService.
 * Dynamic field validation rules (admin-configured via FormRules) are merged
 * with the static baseline rules through DynamicValidationResolver.
 * Routes require 'role:content_editor|administrator' middleware.
 */
class ServiceController extends Controller
{
    public function __construct(
        private readonly ServiceEditorService     $editorService,
        private readonly DynamicValidationResolver $resolver,
    ) {}

    // ── Index ─────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/editor/services
     *
     * Paginated list of services with optional ?status= and ?search= filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Service::with('category')
            ->withCount('timeSlots')
            ->orderBy('updated_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where(DB::raw('LOWER(title)'), 'like', '%' . strtolower($search) . '%')
                  ->orWhere(DB::raw('LOWER(slug)'), 'like', '%' . strtolower($search) . '%');
            });
        }

        return response()->json($query->paginate(15));
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/editor/services
     *
     * Create a new service.
     */
    public function store(Request $request): JsonResponse
    {
        // Static baseline rules are merged with any admin-configured dynamic rules
        // via DynamicValidationResolver (stricter-wins: dynamic rules can tighten
        // but never loosen the hard-coded constraints).
        $data = $request->validate([
            'title'                        => $this->resolver->resolve('service', 'title', ['required', 'string', 'max:250']),
            'description'                  => $this->resolver->resolve('service', 'description', ['nullable', 'string']),
            'eligibility_notes'            => $this->resolver->resolve('service', 'eligibility_notes', ['nullable', 'string']),
            'category_id'                  => ['nullable', 'integer', 'exists:service_categories,id'],
            'service_type_id'              => ['nullable', 'integer', 'exists:data_dictionary_values,id'],
            'is_free'                      => ['boolean'],
            'fee_amount'                   => ['nullable', 'numeric', 'min:0'],
            'fee_currency'                 => ['string', 'in:USD,EUR,GBP'],
            'requires_manual_confirmation' => ['boolean'],
            'tag_ids'                      => ['array'],
            'tag_ids.*'                    => ['integer', 'exists:tags,id'],
            'audience_ids'                 => ['array'],
            'audience_ids.*'               => ['integer', 'exists:target_audiences,id'],
            'research_project_ids'         => ['array'],
            'research_project_ids.*'       => ['integer', 'exists:research_projects,id'],
        ]);

        $service = $this->editorService->create(Auth::user(), $data);

        return response()->json(['service' => $service], 201);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/editor/services/{id}
     */
    public function show(int $id): JsonResponse
    {
        $service = Service::with(['category', 'tags', 'audiences'])
            ->withCount('timeSlots')
            ->findOrFail($id);

        return response()->json(['service' => $service]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * PUT /api/v1/editor/services/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        $data = $request->validate([
            'title'                        => $this->resolver->resolve('service', 'title', ['sometimes', 'string', 'max:250']),
            'description'                  => $this->resolver->resolve('service', 'description', ['nullable', 'string']),
            'eligibility_notes'            => $this->resolver->resolve('service', 'eligibility_notes', ['nullable', 'string']),
            'category_id'                  => ['nullable', 'integer', 'exists:service_categories,id'],
            'service_type_id'              => ['nullable', 'integer', 'exists:data_dictionary_values,id'],
            'is_free'                      => ['boolean'],
            'fee_amount'                   => ['nullable', 'numeric', 'min:0'],
            'fee_currency'                 => ['string', 'in:USD,EUR,GBP'],
            'requires_manual_confirmation' => ['boolean'],
            'status'                       => ['in:draft,active,inactive,archived'],
            'tag_ids'                      => ['array'],
            'tag_ids.*'                    => ['integer', 'exists:tags,id'],
            'audience_ids'                 => ['array'],
            'audience_ids.*'               => ['integer', 'exists:target_audiences,id'],
            'research_project_ids'         => ['array'],
            'research_project_ids.*'       => ['integer', 'exists:research_projects,id'],
        ]);

        $service = $this->editorService->update($service, Auth::user(), $data);

        return response()->json(['service' => $service]);
    }

    // ── Publish ───────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/editor/services/{id}/publish
     */
    public function publish(int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        try {
            $service = $this->editorService->publish($service, Auth::user());
        } catch (InvalidStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['service' => $service]);
    }

    // ── Research project links ────────────────────────────────────────────────

    /**
     * GET /api/v1/editor/services/{id}/research-projects
     *
     * List all research projects currently linked to a service.
     */
    public function listResearchProjects(int $id): JsonResponse
    {
        $service  = Service::findOrFail($id);
        $projects = $service->researchProjects()
            ->select(['research_projects.id', 'project_number', 'title', 'principal_investigator_name', 'department_id'])
            ->orderBy('project_number')
            ->get();

        return response()->json(['research_projects' => $projects]);
    }

    /**
     * POST /api/v1/editor/services/{id}/research-projects
     *
     * Link one or more research projects to a service.
     * Body: { "research_project_ids": [int, ...] }
     * Idempotent: attaches only the IDs not already linked.
     */
    public function attachResearchProjects(Request $request, int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        $data = $request->validate([
            'research_project_ids'   => ['required', 'array', 'min:1'],
            'research_project_ids.*' => ['integer', 'exists:research_projects,id'],
        ]);

        $service->researchProjects()->syncWithoutDetaching($data['research_project_ids']);

        $projects = $service->researchProjects()
            ->select(['research_projects.id', 'project_number', 'title'])
            ->orderBy('project_number')
            ->get();

        return response()->json(['research_projects' => $projects]);
    }

    /**
     * DELETE /api/v1/editor/services/{id}/research-projects/{projectId}
     *
     * Unlink a research project from a service.
     */
    public function detachResearchProject(int $id, int $projectId): JsonResponse
    {
        $service = Service::findOrFail($id);
        $service->researchProjects()->detach($projectId);

        return response()->json(null, 204);
    }

    // ── Archive ───────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/editor/services/{id}/archive
     */
    public function archive(int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        try {
            $service = $this->editorService->archive($service, Auth::user());
        } catch (InvalidStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['service' => $service]);
    }
}
