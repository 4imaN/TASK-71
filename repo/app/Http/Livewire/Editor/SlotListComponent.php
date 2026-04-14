<?php

namespace App\Http\Livewire\Editor;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Services\Editor\SlotEditorService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Editor slot management page for a specific service.
 *
 * Supports inline add and edit forms.
 */
#[Layout('layouts.app')]
class SlotListComponent extends Component
{
    public Service $service;

    // ── Add form ──────────────────────────────────────────────────────────────

    public string $newStartsAt  = '';
    public string $newEndsAt    = '';
    public string $newCapacity  = '10';
    public bool   $showAddForm  = false;

    // ── Edit form ─────────────────────────────────────────────────────────────

    public ?int  $editingSlotId = null;
    public string $editStartsAt = '';
    public string $editEndsAt   = '';
    public string $editCapacity = '';

    // ── Flash ─────────────────────────────────────────────────────────────────

    public string $flashMessage = '';
    public string $flashType    = '';

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function mount(int $serviceId): void
    {
        $this->service = Service::findOrFail($serviceId);
    }

    // ── Add slot ──────────────────────────────────────────────────────────────

    public function addSlot(SlotEditorService $slotEditorService): void
    {
        $this->validate([
            'newStartsAt' => ['required', 'date'],
            'newEndsAt'   => ['required', 'date', 'after:newStartsAt'],
            'newCapacity' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $slotEditorService->createSlot($this->service, Auth::user(), [
                'starts_at' => $this->newStartsAt,
                'ends_at'   => $this->newEndsAt,
                'capacity'  => (int) $this->newCapacity,
            ]);

            $this->newStartsAt  = '';
            $this->newEndsAt    = '';
            $this->newCapacity  = '10';
            $this->showAddForm  = false;

            $this->flashMessage = 'Slot added successfully.';
            $this->flashType    = 'success';
        } catch (\Throwable $e) {
            $this->flashMessage = 'Error adding slot: ' . $e->getMessage();
            $this->flashType    = 'error';
        }
    }

    // ── Edit slot ─────────────────────────────────────────────────────────────

    public function editSlot(int $slotId): void
    {
        $slot = TimeSlot::where('id', $slotId)
            ->where('service_id', $this->service->id)
            ->firstOrFail();

        $this->editingSlotId = $slotId;
        $this->editStartsAt  = $slot->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->editEndsAt    = $slot->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->editCapacity  = (string) $slot->capacity;
    }

    public function saveSlot(SlotEditorService $slotEditorService): void
    {
        $this->validate([
            'editStartsAt' => ['required', 'date'],
            'editEndsAt'   => ['required', 'date', 'after:editStartsAt'],
            'editCapacity' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $slot = TimeSlot::where('id', $this->editingSlotId)
                ->where('service_id', $this->service->id)
                ->firstOrFail();

            $slotEditorService->updateSlot($slot, Auth::user(), [
                'starts_at' => $this->editStartsAt,
                'ends_at'   => $this->editEndsAt,
                'capacity'  => (int) $this->editCapacity,
            ]);

            $this->cancelEdit();

            $this->flashMessage = 'Slot updated.';
            $this->flashType    = 'success';
        } catch (InvalidStateTransitionException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType    = 'error';
        } catch (\Throwable $e) {
            $this->flashMessage = 'Error updating slot: ' . $e->getMessage();
            $this->flashType    = 'error';
        }
    }

    public function cancelEdit(): void
    {
        $this->editingSlotId = null;
        $this->editStartsAt  = '';
        $this->editEndsAt    = '';
        $this->editCapacity  = '';
    }

    // ── Cancel slot ───────────────────────────────────────────────────────────

    public function cancelSlot(int $slotId, SlotEditorService $slotEditorService): void
    {
        try {
            $slot = TimeSlot::where('id', $slotId)
                ->where('service_id', $this->service->id)
                ->firstOrFail();

            $slotEditorService->cancelSlot($slot, Auth::user());

            $this->flashMessage = 'Slot cancelled.';
            $this->flashType    = 'success';
        } catch (InvalidStateTransitionException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType    = 'error';
        } catch (\Throwable $e) {
            $this->flashMessage = 'Error: ' . $e->getMessage();
            $this->flashType    = 'error';
        }
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): \Illuminate\View\View
    {
        $slots = TimeSlot::where('service_id', $this->service->id)
            ->withCount([
                'reservations as pending_count'   => fn ($q) => $q->where('status', 'pending'),
                'reservations as confirmed_count' => fn ($q) => $q->where('status', 'confirmed'),
            ])
            ->orderBy('starts_at')
            ->get();

        return view('livewire.editor.slot-list', compact('slots'));
    }
}
