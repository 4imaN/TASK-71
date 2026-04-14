<?php

namespace App\Http\Livewire\Admin;

use App\Models\DataDictionaryType;
use App\Models\DataDictionaryValue;
use App\Services\Admin\AdminDictionaryService;
use App\Services\Admin\StepUpService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class DataDictionaryComponent extends Component
{
    /** Active type (by code) */
    #[Url]
    public string $activeTypeCode = '';

    /** Add value form */
    public ?int   $activeTypeId   = null;
    public bool   $showAddForm    = false;
    public string $newKey         = '';
    public string $newLabel       = '';
    public string $newDescription = '';
    public string $newSortOrder   = '0';

    /** Edit value state */
    public ?int   $editingValueId  = null;
    public string $editLabel       = '';
    public string $editDescription = '';
    public string $editSortOrder   = '';

    /** Step-up */
    public bool   $requiresStepUp = false;
    public string $stepUpPassword = '';
    public string $stepUpError    = '';

    /** Flash */
    public string $flashMessage = '';
    public string $flashType    = 'success';

    public function setType(int $typeId, string $typeCode): void
    {
        $this->activeTypeId   = $typeId;
        $this->activeTypeCode = $typeCode;
        $this->showAddForm    = false;
        $this->editingValueId = null;
        $this->flashMessage   = '';
    }

    public function addValue(AdminDictionaryService $dictService, StepUpService $stepUp): void
    {
        if (!$stepUp->isGranted()) {
            $this->requiresStepUp = true;
            return;
        }

        $this->validate([
            'newKey'       => ['required', 'string', 'max:80', 'alpha_dash'],
            'newLabel'     => ['required', 'string', 'max:200'],
            'newSortOrder' => ['integer', 'min:0'],
        ]);

        $type = DataDictionaryType::findOrFail($this->activeTypeId);

        try {
            $dictService->createValue($type, [
                'key'         => $this->newKey,
                'label'       => $this->newLabel,
                'description' => $this->newDescription ?: null,
                'sort_order'  => (int) $this->newSortOrder,
            ], Auth::user());

            $this->newKey         = '';
            $this->newLabel       = '';
            $this->newDescription = '';
            $this->newSortOrder   = '0';
            $this->showAddForm    = false;
            $this->flashMessage   = 'Value added.';
            $this->flashType      = 'success';
        } catch (\Exception $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType    = 'error';
        }
    }

    public function editValue(int $valueId): void
    {
        $val = DataDictionaryValue::findOrFail($valueId);
        $this->editingValueId  = $valueId;
        $this->editLabel       = $val->label;
        $this->editDescription = $val->description ?? '';
        $this->editSortOrder   = (string) $val->sort_order;
    }

    public function saveValue(AdminDictionaryService $dictService, StepUpService $stepUp): void
    {
        if (!$stepUp->isGranted()) {
            $this->requiresStepUp = true;
            return;
        }

        $this->validate([
            'editLabel'     => ['required', 'string', 'max:200'],
            'editSortOrder' => ['integer', 'min:0'],
        ]);

        $val = DataDictionaryValue::findOrFail($this->editingValueId);
        $dictService->updateValue($val, [
            'label'       => $this->editLabel,
            'description' => $this->editDescription ?: null,
            'sort_order'  => (int) $this->editSortOrder,
        ], Auth::user());

        $this->editingValueId = null;
        $this->flashMessage   = 'Value updated.';
        $this->flashType      = 'success';
    }

    public function deactivateValue(int $valueId, AdminDictionaryService $dictService, StepUpService $stepUp): void
    {
        if (!$stepUp->isGranted()) {
            $this->requiresStepUp = true;
            return;
        }

        $val = DataDictionaryValue::findOrFail($valueId);
        $dictService->deactivateValue($val, Auth::user());
        $this->flashMessage = 'Value deactivated.';
        $this->flashType    = 'success';
    }

    public function confirmStepUp(StepUpService $stepUp): void
    {
        if ($stepUp->verify(Auth::user(), $this->stepUpPassword)) {
            $this->stepUpPassword = '';
            $this->stepUpError    = '';
            $this->requiresStepUp = false;
            $this->flashMessage   = 'Identity verified.';
            $this->flashType      = 'success';
        } else {
            $this->stepUpError = 'Incorrect password.';
        }
    }

    public function cancelStepUp(): void
    {
        $this->requiresStepUp = false;
        $this->stepUpPassword = '';
        $this->stepUpError    = '';
    }

    public function cancelEdit(): void
    {
        $this->editingValueId = null;
    }

    public function render(AdminDictionaryService $dictService): \Illuminate\View\View
    {
        $types = $dictService->allTypes();

        // Default to first type if none selected
        if ($this->activeTypeCode === '' && $types->isNotEmpty()) {
            $first                = $types->first();
            $this->activeTypeCode = $first->code;
            $this->activeTypeId   = $first->id;
        }

        $activeType = $types->firstWhere('code', $this->activeTypeCode);

        return view('livewire.admin.data-dictionary', compact('types', 'activeType'));
    }
}
