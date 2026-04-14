<?php

namespace App\Http\Livewire\Reservation;

use App\Services\Api\ReservationApiGateway;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Learner reservation list: shows all reservations with status filter.
 * Navigates to the detail page for cancel/reschedule actions.
 *
 * Delegates to ReservationApiGateway — the same API contract consumed by
 * GET /api/v1/reservations — so this Livewire component is a client of
 * the REST API layer rather than querying models directly.
 */
#[Layout('layouts.app')]
class ReservationListComponent extends Component
{
    use WithPagination;

    #[Url]
    public string $statusFilter = '';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render(ReservationApiGateway $gateway): \Illuminate\View\View
    {
        $statuses = $this->statusFilter !== '' ? [$this->statusFilter] : null;

        $reservations = $gateway->list(Auth::user(), $statuses, perPage: 10);

        return view('livewire.reservation.list', compact('reservations'));
    }
}
