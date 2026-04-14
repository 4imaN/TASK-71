<?php

namespace App\Http\Livewire\Editor;

use App\Models\Service;
use App\Services\Admin\DynamicValidationResolver;
use App\Services\Api\EditorApiGateway;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Editor form for creating and editing services.
 *
 * null $serviceId = create mode
 * int  $serviceId = edit mode
 *
 * Delegates to EditorApiGateway — the same API contract consumed by
 * POST/PUT /api/v1/editor/services and POST /api/v1/editor/services/{id}/publish|archive
 * — so this Livewire component is a client of the REST API layer rather
 * than calling domain services directly.
 */
#[Layout('layouts.app')]
class ServiceFormComponent extends Component
{
    // ── Route parameter ───────────────────────────────────────────────────────

    public ?int $serviceId = null;

    // ── Form fields ───────────────────────────────────────────────────────────

    public string  $title                    = '';
    public string  $description              = '';
    public string  $eligibilityNotes         = '';
    public string  $categoryId               = '';
    public string  $serviceTypeId            = '';
    public bool    $isFree                   = true;
    public string  $feeAmount                = '';
    public string  $feeCurrency              = 'USD';
    public bool    $requiresManualConfirmation = false;
    public string  $status                   = 'draft';
    public array   $selectedTagIds            = [];
    public array   $selectedAudienceIds       = [];
    public array   $selectedResearchProjectIds = [];

    // ── Reference data ────────────────────────────────────────────────────────

    public array $categories       = [];
    public array $tags             = [];
    public array $audiences        = [];
    public array $serviceTypes     = [];
    public array $researchProjects = [];

    // ── Flash ─────────────────────────────────────────────────────────────────

    public string $flashMessage = '';
    public string $flashType    = '';

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function mount(?int $serviceId = null, ?EditorApiGateway $gateway = null): void
    {
        $this->serviceId = $serviceId;

        $gateway = $gateway ?? app(EditorApiGateway::class);
        $refData = $gateway->getReferenceData();

        $this->categories       = $refData['categories'];
        $this->tags             = $refData['tags'];
        $this->audiences        = $refData['audiences'];
        $this->serviceTypes     = $refData['serviceTypes'];
        $this->researchProjects = $refData['researchProjects'];

        if ($serviceId !== null) {
            $service = Service::findOrFail($serviceId);
            $this->fillFromService($service);
        }
    }

    private function fillFromService(Service $service): void
    {
        $this->title                     = $service->title;
        $this->description               = $service->description ?? '';
        $this->eligibilityNotes          = $service->eligibility_notes ?? '';
        $this->categoryId                = (string) ($service->category_id ?? '');
        $this->serviceTypeId             = (string) ($service->service_type_id ?? '');
        $this->isFree                    = (bool) $service->is_free;
        $this->feeAmount                 = (string) ($service->fee_amount ?? '');
        $this->feeCurrency               = $service->fee_currency ?? 'USD';
        $this->requiresManualConfirmation = (bool) $service->requires_manual_confirmation;
        $this->status                    = $service->status;
        $this->selectedTagIds             = $service->tags()->pluck('tags.id')->map(fn ($id) => (string) $id)->toArray();
        $this->selectedAudienceIds        = $service->audiences()->pluck('target_audiences.id')->map(fn ($id) => (string) $id)->toArray();
        $this->selectedResearchProjectIds = $service->researchProjects()->pluck('research_projects.id')->map(fn ($id) => (string) $id)->toArray();
    }

    // ── Validation ────────────────────────────────────────────────────────────

    protected function validationRules(): array
    {
        // Merge static baseline rules with any admin-configured dynamic rules.
        // The resolver uses the canonical entity/field names (snake_case) that
        // match the FormRules table, while Livewire properties stay camelCase.
        $resolver = app(DynamicValidationResolver::class);

        return [
            'title'                     => $resolver->resolve('service', 'title', ['required', 'string', 'max:250']),
            'description'               => $resolver->resolve('service', 'description', ['nullable', 'string']),
            'eligibilityNotes'          => $resolver->resolve('service', 'eligibility_notes', ['nullable', 'string']),
            'categoryId'                => ['nullable', 'integer', 'exists:service_categories,id'],
            'serviceTypeId'             => ['nullable', 'integer', 'exists:data_dictionary_values,id'],
            'isFree'                    => ['boolean'],
            'feeAmount'                 => ['nullable', 'numeric', 'min:0'],
            'feeCurrency'               => ['string', 'in:USD,EUR,GBP'],
            'requiresManualConfirmation'=> ['boolean'],
            'status'                    => ['in:draft,active,inactive,archived'],
            'selectedTagIds'               => ['array'],
            'selectedTagIds.*'             => ['integer', 'exists:tags,id'],
            'selectedAudienceIds'          => ['array'],
            'selectedAudienceIds.*'        => ['integer', 'exists:target_audiences,id'],
            'selectedResearchProjectIds'   => ['array'],
            'selectedResearchProjectIds.*' => ['integer', 'exists:research_projects,id'],
        ];
    }

    private function toServiceData(): array
    {
        return [
            'title'                        => $this->title,
            'description'                  => $this->description ?: null,
            'eligibility_notes'            => $this->eligibilityNotes ?: null,
            'category_id'                  => $this->categoryId !== '' ? (int) $this->categoryId : null,
            'service_type_id'              => $this->serviceTypeId !== '' ? (int) $this->serviceTypeId : null,
            'is_free'                      => $this->isFree,
            'fee_amount'                   => $this->isFree ? 0 : (float) $this->feeAmount,
            'fee_currency'                 => $this->feeCurrency,
            'requires_manual_confirmation' => $this->requiresManualConfirmation,
            'status'                       => $this->status,
            'tag_ids'                      => array_map('intval', $this->selectedTagIds),
            'audience_ids'                 => array_map('intval', $this->selectedAudienceIds),
            'research_project_ids'         => array_map('intval', $this->selectedResearchProjectIds),
        ];
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Save (create or update) the service.
     *
     * Delegates to EditorApiGateway — the same API contract consumed by
     * POST /api/v1/editor/services (create) and
     * PUT /api/v1/editor/services/{id} (update).
     */
    public function save(EditorApiGateway $gateway): void
    {
        $this->validate($this->validationRules());

        $editor = Auth::user();
        $data   = $this->toServiceData();

        if ($this->serviceId === null) {
            $result = $gateway->createService($editor, $data);
        } else {
            $result = $gateway->updateService($this->serviceId, $editor, $data);
        }

        if (!$result->success) {
            $this->flashMessage = $result->error;
            $this->flashType    = 'error';
            return;
        }

        $isCreate = $result->httpStatus === 201;
        $service  = $result->data;

        $this->serviceId    = $service->id;
        $this->status       = $service->status;
        $this->flashMessage = $isCreate ? 'Service created successfully.' : 'Service updated successfully.';
        $this->flashType    = 'success';
    }

    /**
     * Publish the service.
     *
     * Delegates to EditorApiGateway — the same API contract consumed by
     * POST /api/v1/editor/services/{id}/publish.
     */
    public function publish(EditorApiGateway $gateway): void
    {
        if ($this->serviceId === null) {
            $this->flashMessage = 'Save the service before publishing.';
            $this->flashType    = 'error';
            return;
        }

        $result = $gateway->publishService($this->serviceId, Auth::user());

        if (!$result->success) {
            $this->flashMessage = $result->error;
            $this->flashType    = 'error';
            return;
        }

        $this->status       = $result->data->status;
        $this->flashMessage = 'Service published.';
        $this->flashType    = 'success';
    }

    /**
     * Archive the service.
     *
     * Delegates to EditorApiGateway — the same API contract consumed by
     * POST /api/v1/editor/services/{id}/archive.
     */
    public function archive(EditorApiGateway $gateway): void
    {
        if ($this->serviceId === null) {
            $this->flashMessage = 'Save the service before archiving.';
            $this->flashType    = 'error';
            return;
        }

        $result = $gateway->archiveService($this->serviceId, Auth::user());

        if (!$result->success) {
            $this->flashMessage = $result->error;
            $this->flashType    = 'error';
            return;
        }

        $this->status       = $result->data->status;
        $this->flashMessage = 'Service archived.';
        $this->flashType    = 'success';
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.editor.service-form');
    }
}
