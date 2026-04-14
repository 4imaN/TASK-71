<?php

namespace App\Http\Livewire\Catalog;

use App\Models\Service;
use App\Services\Api\CatalogApiGateway;
use App\Services\Api\ReservationApiGateway;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Service detail page: description, eligibility, tags, time slots,
 * favorite toggle, and slot booking.
 *
 * Records a recent view on every mount.
 *
 * Delegates to CatalogApiGateway for catalog reads (favorites, recent
 * views) and ReservationApiGateway for booking — the same API contracts
 * consumed by the REST surface.
 */
#[Layout('layouts.app')]
class ServiceDetailComponent extends Component
{
    public Service $service;
    public bool    $isFavorited = false;
    public string  $bookError   = '';
    public ?int    $bookingSlotId = null; // tracks which slot is being submitted

    public function mount(string $slug, CatalogApiGateway $gateway): void
    {
        $this->service = Service::with(['category', 'tags', 'audiences'])
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        // Track the view — upserts viewed_at so only one row per user+service
        $gateway->recordRecentView(Auth::id(), $this->service->id);

        // Prime the favorite state so the button renders correctly immediately
        $this->isFavorited = $gateway->isFavorited(Auth::id(), $this->service->id);
    }

    /**
     * Toggle this service's favorite state for the authenticated user.
     *
     * Delegates to CatalogApiGateway — the same contract consumed by
     * POST/DELETE /api/v1/user/favorites/{service_id}.
     */
    public function toggleFavorite(CatalogApiGateway $gateway): void
    {
        $this->isFavorited = $gateway->toggleFavorite(Auth::id(), $this->service->id);
    }

    /**
     * Request a reservation for the given time slot.
     *
     * Delegates to ReservationApiGateway — the same API contract consumed by
     * POST /api/v1/reservations — so this Livewire action is a client of the
     * REST API layer rather than calling domain services directly.
     *
     * On success redirects to the new reservation's detail page.
     */
    public function bookSlot(int $slotId, ReservationApiGateway $gateway): void
    {
        $this->bookError     = '';
        $this->bookingSlotId = $slotId;

        // Validate slot belongs to this service before dispatching to the gateway.
        // This is a UI guard; the gateway performs full capacity / eligibility checks.
        if (!\App\Models\TimeSlot::where('id', $slotId)
                ->where('service_id', $this->service->id)
                ->exists()) {
            $this->bookError     = 'Invalid slot selection.';
            $this->bookingSlotId = null;
            return;
        }

        $result = $gateway->book(Auth::user(), $slotId);

        if (!$result->success) {
            $this->bookError     = $result->error;
            $this->bookingSlotId = null;
            return;
        }

        $this->bookingSlotId = null;
        $this->redirectRoute('reservations.show', ['uuid' => $result->reservation->uuid]);
    }

    public function render(): \Illuminate\View\View
    {
        $timeSlots = $this->service->timeSlots()
            ->where('status', 'available')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->limit(20)
            ->get();

        return view('livewire.catalog.service-detail', compact('timeSlots'));
    }
}
