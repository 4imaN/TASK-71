<?php

namespace App\Http\Livewire\Admin;

use App\Services\Admin\AuditLogService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class AuditLogComponent extends Component
{
    use WithPagination;

    // ── Filters (URL-bound for shareable links) ───────────────────────────────
    #[Url]
    public string $filterAction = '';

    #[Url]
    public string $filterEntityType = '';

    #[Url]
    public string $filterActorUsername = '';

    #[Url]
    public string $filterDateFrom = '';

    #[Url]
    public string $filterDateTo = '';

    #[Url]
    public string $filterCorrelationId = '';

    // ── Detail expand ─────────────────────────────────────────────────────────
    public ?int $expandedEntryId = null;

    public function updatedFilterAction(): void      { $this->resetPage(); }
    public function updatedFilterEntityType(): void  { $this->resetPage(); }
    public function updatedFilterActorUsername(): void { $this->resetPage(); }
    public function updatedFilterDateFrom(): void    { $this->resetPage(); }
    public function updatedFilterDateTo(): void      { $this->resetPage(); }
    public function updatedFilterCorrelationId(): void { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->filterAction        = '';
        $this->filterEntityType    = '';
        $this->filterActorUsername = '';
        $this->filterDateFrom      = '';
        $this->filterDateTo        = '';
        $this->filterCorrelationId = '';
        $this->resetPage();
    }

    public function toggleExpand(int $entryId): void
    {
        $this->expandedEntryId = ($this->expandedEntryId === $entryId) ? null : $entryId;
    }

    public function filterByCorrelation(string $correlationId): void
    {
        $this->filterCorrelationId = $correlationId;
        $this->resetPage();
    }

    public function render(AuditLogService $service): \Illuminate\View\View
    {
        $filters = [
            'action'           => $this->filterAction,
            'entity_type'      => $this->filterEntityType,
            'actor_username'   => $this->filterActorUsername,
            'date_from'        => $this->filterDateFrom,
            'date_to'          => $this->filterDateTo,
            'correlation_id'   => $this->filterCorrelationId,
        ];

        return view('livewire.admin.audit-log', [
            'entries'      => $service->list(array_filter($filters), perPage: 30),
            'entityTypes'  => $service->entityTypes(),
            'expandedEntry'=> $this->expandedEntryId
                ? $service->find($this->expandedEntryId)
                : null,
        ]);
    }
}
