<?php

namespace App\Http\Livewire\Admin;

use App\Models\ImportFieldMappingTemplate;
use App\Models\ImportJob;
use App\Services\Import\ImportProcessorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ImportJobListComponent extends Component
{
    use WithFileUploads, WithPagination;

    // Filters
    public string $entityFilter = '';
    public string $statusFilter = '';
    public string $search = '';

    // New job form
    public string $newEntityType = '';
    public string $newSourceSystem = '';
    public string $newFormat = 'csv';
    public string $newConflictStrategy = 'prefer_newest';
    public $newFile = null;
    public bool $showCreateForm = false;
    public array $fieldMapping = [];
    public ?int $templateId = null;
    public string $newContent = ''; // inline content for testing

    // Flash
    public string $flashMessage = '';
    public string $flashType = 'success';

    protected function rules(): array
    {
        return [
            'newEntityType'       => ['required', 'string', 'in:departments,user_profiles,research_projects,services,users'],
            'newFormat'           => ['required', 'string', 'in:csv,json'],
            'newConflictStrategy' => ['required', 'string', 'in:prefer_newest,admin_override,pending'],
        ];
    }

    public function render(): \Illuminate\View\View
    {
        $query = ImportJob::query()->latest();

        if ($this->entityFilter !== '') {
            $query->where('entity_type', $this->entityFilter);
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('original_filename', 'like', '%' . $this->search . '%')
                  ->orWhere('source_system', 'like', '%' . $this->search . '%');
            });
        }

        $jobs      = $query->paginate(15);
        $templates = ImportFieldMappingTemplate::all();

        return view('livewire.admin.import-job-list', compact('jobs', 'templates'));
    }

    public function createJob(ImportProcessorService $processor): void
    {
        $this->validate();

        $rawContent = '';

        if ($this->newFile) {
            $rawContent = file_get_contents($this->newFile->getRealPath());
            $originalFilename = $this->newFile->getClientOriginalName();
        } elseif ($this->newContent !== '') {
            $rawContent = $this->newContent;
            $originalFilename = null;
        } else {
            $this->flashMessage = 'Please provide a file or paste content.';
            $this->flashType    = 'error';
            return;
        }

        $job = ImportJob::create([
            'uuid'                         => (string) Str::uuid(),
            'entity_type'                  => $this->newEntityType,
            'source_system'                => $this->newSourceSystem ?: null,
            'file_format'                  => $this->newFormat,
            'original_filename'            => $originalFilename ?? null,
            'field_mapping'                => $this->fieldMapping ?: null,
            'status'                       => 'pending',
            'conflict_resolution_strategy' => $this->newConflictStrategy,
            'created_by'                   => Auth::id(),
        ]);

        $processor->process($job, $rawContent, Auth::user());

        $this->flashMessage   = 'Import job created and processed.';
        $this->flashType      = 'success';
        $this->showCreateForm = false;
        $this->resetForm();
    }

    public function loadTemplate(int $templateId): void
    {
        $template = ImportFieldMappingTemplate::find($templateId);
        if (!$template) {
            return;
        }

        $this->templateId        = $templateId;
        $this->fieldMapping      = $template->field_mapping ?? [];
        $this->newEntityType     = $template->entity_type;
        $this->newSourceSystem   = $template->source_system ?? '';
    }

    public function saveTemplate(string $templateName): void
    {
        if (empty($this->fieldMapping)) {
            $this->flashMessage = 'Field mapping is empty — nothing to save.';
            $this->flashType    = 'error';
            return;
        }

        ImportFieldMappingTemplate::create([
            'name'          => $templateName,
            'entity_type'   => $this->newEntityType,
            'source_system' => $this->newSourceSystem ?: null,
            'field_mapping' => $this->fieldMapping,
            'created_by'    => Auth::id(),
        ]);

        $this->flashMessage = "Template '{$templateName}' saved.";
        $this->flashType    = 'success';
    }

    private function resetForm(): void
    {
        $this->newEntityType       = '';
        $this->newSourceSystem     = '';
        $this->newFormat           = 'csv';
        $this->newConflictStrategy = 'prefer_newest';
        $this->newFile             = null;
        $this->newContent          = '';
        $this->fieldMapping        = [];
        $this->templateId          = null;
    }
}
