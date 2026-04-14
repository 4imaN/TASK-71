<?php

namespace App\Services\Api;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\DataDictionaryType;
use App\Models\DataDictionaryValue;
use App\Models\ResearchProject;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tag;
use App\Models\TargetAudience;
use App\Models\User;
use App\Services\Editor\ServiceEditorService;

/**
 * API gateway for the editor service-management surface.
 *
 * This class is the shared contract for all service editor mutations.
 * Both the REST API surface (Editor\ServiceController) and the Livewire
 * surface (ServiceFormComponent) delegate through this gateway so that
 * create/update/publish/archive logic is never duplicated.
 *
 * Mirrors the contract of:
 *   POST /api/v1/editor/services
 *   PUT  /api/v1/editor/services/{id}
 *   POST /api/v1/editor/services/{id}/publish
 *   POST /api/v1/editor/services/{id}/archive
 */
class EditorApiGateway
{
    public function __construct(
        private readonly ServiceEditorService $editorService,
    ) {}

    // ── Create ───────────────────────────────────────────────────────────────

    /**
     * Create a new service with status='draft'.
     *
     * Mirrors the contract of POST /api/v1/editor/services.
     */
    public function createService(User $editor, array $data): ApiResult
    {
        try {
            $service = $this->editorService->create($editor, $data);
            return ApiResult::success($service, 201);
        } catch (\Throwable $e) {
            return ApiResult::failure('Error creating service: ' . $e->getMessage());
        }
    }

    // ── Update ───────────────────────────────────────────────────────────────

    /**
     * Update an existing service.
     *
     * Mirrors the contract of PUT /api/v1/editor/services/{id}.
     */
    public function updateService(int $serviceId, User $editor, array $data): ApiResult
    {
        $service = Service::find($serviceId);

        if (!$service) {
            return ApiResult::failure('Service not found.', 404);
        }

        try {
            $service = $this->editorService->update($service, $editor, $data);
            return ApiResult::success($service);
        } catch (\Throwable $e) {
            return ApiResult::failure('Error updating service: ' . $e->getMessage());
        }
    }

    // ── Publish ──────────────────────────────────────────────────────────────

    /**
     * Transition a service from draft/inactive to active.
     *
     * Mirrors the contract of POST /api/v1/editor/services/{id}/publish.
     */
    public function publishService(int $serviceId, User $editor): ApiResult
    {
        $service = Service::find($serviceId);

        if (!$service) {
            return ApiResult::failure('Service not found.', 404);
        }

        try {
            $service = $this->editorService->publish($service, $editor);
            return ApiResult::success($service);
        } catch (InvalidStateTransitionException $e) {
            return ApiResult::failure($e->getMessage(), 422);
        }
    }

    // ── Archive ──────────────────────────────────────────────────────────────

    /**
     * Archive a service.
     *
     * Mirrors the contract of POST /api/v1/editor/services/{id}/archive.
     */
    public function archiveService(int $serviceId, User $editor): ApiResult
    {
        $service = Service::find($serviceId);

        if (!$service) {
            return ApiResult::failure('Service not found.', 404);
        }

        try {
            $service = $this->editorService->archive($service, $editor);
            return ApiResult::success($service);
        } catch (\Throwable $e) {
            return ApiResult::failure($e->getMessage(), 422);
        }
    }

    // ── Reference data ───────────────────────────────────────────────────────

    /**
     * Load all reference data needed by the editor form.
     *
     * Returns a structured array of categories, tags, audiences,
     * service types (from data dictionary), and research projects.
     */
    public function getReferenceData(): array
    {
        $categories = ServiceCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();

        $tags = Tag::orderBy('name')
            ->get(['id', 'name'])
            ->toArray();

        $audiences = TargetAudience::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['id', 'label'])
            ->toArray();

        $serviceTypes = [];
        $type = DataDictionaryType::where('code', 'service_type')->first();
        if ($type) {
            $serviceTypes = DataDictionaryValue::where('type_id', $type->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'label'])
                ->toArray();
        }

        $researchProjects = ResearchProject::whereNull('deleted_at')
            ->orderBy('project_number')
            ->get(['id', 'project_number', 'title'])
            ->toArray();

        return compact('categories', 'tags', 'audiences', 'serviceTypes', 'researchProjects');
    }
}
