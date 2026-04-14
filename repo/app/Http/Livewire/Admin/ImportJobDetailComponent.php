<?php

namespace App\Http\Livewire\Admin;

use App\Models\ImportConflict;
use App\Models\ImportJob;
use App\Services\Import\ConflictResolutionService;
use App\Services\Import\ImportProcessorService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ImportJobDetailComponent extends Component
{
    use WithPagination;

    public ImportJob $job;
    public string $conflictFilter = 'pending'; // pending|resolved|all
    public array $overrideValues = []; // conflictId => [field => value]

    // Flash
    public string $flashMessage = '';
    public string $flashType = 'success';

    public function mount(int $jobId): void
    {
        $this->job = ImportJob::findOrFail($jobId);
    }

    public function render(): \Illuminate\View\View
    {
        $query = ImportConflict::where('import_job_id', $this->job->id);

        if ($this->conflictFilter === 'pending') {
            $query->where('resolution', 'pending');
        } elseif ($this->conflictFilter === 'resolved') {
            $query->where('resolution', '!=', 'pending');
        }

        $conflicts = $query->paginate(10);

        return view('livewire.admin.import-job-detail', [
            'job'       => $this->job,
            'conflicts' => $conflicts,
        ]);
    }

    public function resolveConflict(
        int $conflictId,
        string $resolution,
        ConflictResolutionService $resolver
    ): void {
        $conflict = ImportConflict::where('import_job_id', $this->job->id)->findOrFail($conflictId);

        $resolvedRecord = [];

        if ($resolution === 'admin_override') {
            // Use the override values keyed by conflict ID if available
            $resolvedRecord = $this->overrideValues[$conflictId] ?? ($conflict->incoming_record ?? []);
        }

        $resolver->adminResolve($conflict, $resolution, $resolvedRecord, Auth::user());

        $this->flashMessage = 'Conflict resolved.';
        $this->flashType    = 'success';
    }

    public function reprocess(ImportProcessorService $processor): void
    {
        $processor->reprocessResolvedConflicts($this->job, Auth::user());

        $this->job = $this->job->refresh();

        $this->flashMessage = 'Resolved conflicts have been reprocessed.';
        $this->flashType    = 'success';
    }
}
