<?php

namespace App\Http\Livewire\Admin;

use App\Services\Admin\StepUpService;
use App\Services\Api\AdminConfigApiGateway;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Admin policy configuration page.
 *
 * Delegates to AdminConfigApiGateway — the same API contract consumed by
 * GET /api/v1/admin/system-config and PUT /api/v1/admin/system-config/{key}
 * — so this Livewire component is a client of the REST API layer rather
 * than calling domain services directly.
 *
 * Step-up verification is handled at the component level (same as the
 * REST controller checks step-up before invoking the gateway).
 */
#[Layout('layouts.app')]
class PolicyConfigComponent extends Component
{
    /** Active tab */
    #[Url]
    public string $group = 'reservation';

    /** Config values: flat map key → value */
    public array $values = [];

    /** Step-up state */
    public bool   $requiresStepUp = false;
    public string $stepUpPassword = '';
    public string $stepUpError    = '';

    /** Flash */
    public string $flashMessage = '';
    public string $flashType    = 'success';

    public function mount(AdminConfigApiGateway $gateway): void
    {
        foreach ($gateway->allGrouped() as $items) {
            foreach ($items as $item) {
                $this->values[$item['key']] = $item['value'] ?? '';
            }
        }
    }

    public function setGroup(string $group): void
    {
        $this->group        = $group;
        $this->flashMessage = '';
    }

    /**
     * Save the currently visible group's values. Requires active step-up grant.
     *
     * Delegates to AdminConfigApiGateway — the same API contract consumed by
     * PUT /api/v1/admin/system-config/{key}.
     */
    public function save(AdminConfigApiGateway $gateway, StepUpService $stepUp): void
    {
        if (!$stepUp->isGranted()) {
            $this->requiresStepUp = true;
            return;
        }

        $groups         = $gateway->groups();
        $validationMap  = $gateway->validationRules();
        $keys           = $groups[$this->group] ?? [];

        // Build per-key rules keyed by Livewire property path values.KEY
        $rules = [];
        foreach ($keys as $key) {
            $rules["values.{$key}"] = $validationMap[$key] ?? ['required'];
        }
        $this->validate($rules);

        $changes = array_intersect_key($this->values, array_flip($keys));

        $result = $gateway->updateBulk($changes, Auth::user());

        if (!$result->success) {
            $this->flashMessage = $result->error;
            $this->flashType    = 'error';
            return;
        }

        $this->flashMessage = 'Configuration saved.';
        $this->flashType    = 'success';
    }

    /**
     * Verify current password and grant step-up.
     */
    public function confirmStepUp(StepUpService $stepUp): void
    {
        $this->validate(['stepUpPassword' => ['required', 'string']]);

        if ($stepUp->verify(Auth::user(), $this->stepUpPassword)) {
            $this->stepUpPassword = '';
            $this->stepUpError    = '';
            $this->requiresStepUp = false;
            $this->flashMessage   = 'Identity verified. You may now save changes.';
            $this->flashType      = 'success';
        } else {
            $this->stepUpError = 'Incorrect password. Please try again.';
        }
    }

    public function cancelStepUp(): void
    {
        $this->requiresStepUp = false;
        $this->stepUpPassword = '';
        $this->stepUpError    = '';
    }

    public function render(AdminConfigApiGateway $gateway): \Illuminate\View\View
    {
        $grouped     = $gateway->allGrouped();
        $stepGranted = app(StepUpService::class)->isGranted();

        return view('livewire.admin.policy-config', compact('grouped', 'stepGranted'));
    }
}
