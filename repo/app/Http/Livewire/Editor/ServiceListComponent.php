<?php

namespace App\Http\Livewire\Editor;

use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Editor service list page.
 *
 * Displays all services with search and status filter.
 * Provides entry points for creating, editing, and managing slots.
 */
#[Layout('layouts.app')]
class ServiceListComponent extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render(): \Illuminate\View\View
    {
        $query = Service::with('category')
            ->withCount('timeSlots')
            ->orderBy('updated_at', 'desc');

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where(DB::raw('LOWER(title)'), 'like', '%' . strtolower($search) . '%')
                  ->orWhere(DB::raw('LOWER(slug)'), 'like', '%' . strtolower($search) . '%');
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        $services = $query->paginate(15);

        return view('livewire.editor.service-list', compact('services'));
    }
}
