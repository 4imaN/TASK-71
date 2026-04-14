<?php

namespace App\Http\Livewire\Admin;

use App\Models\FormRule;
use App\Services\Admin\AdminFormRuleService;
use App\Services\Admin\StepUpService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class FormRulesComponent extends Component
{
    /** Form for create/edit */
    public ?int   $editingId     = null;
    public string $entityType    = '';
    public string $fieldName     = '';
    public bool   $ruleRequired  = false;
    public string $ruleMinLength = '';
    public string $ruleMaxLength = '';
    public string $ruleRegex     = '';
    public bool   $isActive      = true;

    /** Step-up */
    public bool   $requiresStepUp = false;
    public string $stepUpPassword = '';
    public string $stepUpError    = '';

    /** Flash */
    public string $flashMessage = '';
    public string $flashType    = 'success';

    public function editRule(int $id): void
    {
        $rule = FormRule::findOrFail($id);
        $this->editingId     = $id;
        $this->entityType    = $rule->entity_type;
        $this->fieldName     = $rule->field_name;
        $this->ruleRequired  = (bool) ($rule->rules['required'] ?? false);
        $this->ruleMinLength = (string) ($rule->rules['min_length'] ?? '');
        $this->ruleMaxLength = (string) ($rule->rules['max_length'] ?? '');
        $this->ruleRegex     = $rule->rules['regex'] ?? '';
        $this->isActive      = (bool) $rule->is_active;
    }

    public function clearForm(): void
    {
        $this->editingId    = null;
        $this->entityType   = '';
        $this->fieldName    = '';
        $this->ruleRegex    = '';
        $this->ruleMinLength = '';
        $this->ruleMaxLength = '';
        $this->ruleRequired = false;
        $this->isActive     = true;
    }

    public function saveRule(AdminFormRuleService $ruleService, StepUpService $stepUp): void
    {
        if (!$stepUp->isGranted()) {
            $this->requiresStepUp = true;
            return;
        }

        $this->validate([
            'entityType'    => ['required', 'string', 'max:80'],
            'fieldName'     => ['required', 'string', 'max:80'],
            'ruleMinLength' => ['nullable', 'integer', 'min:1'],
            'ruleMaxLength' => ['nullable', 'integer', 'min:1'],
            'ruleRegex'     => ['nullable', 'string'],
        ]);

        try {
            $ruleService->upsert([
                'entity_type' => $this->entityType,
                'field_name'  => $this->fieldName,
                'is_active'   => $this->isActive,
                'rules'       => [
                    'required'   => $this->ruleRequired ?: null,
                    'min_length' => $this->ruleMinLength !== '' ? (int) $this->ruleMinLength : null,
                    'max_length' => $this->ruleMaxLength !== '' ? (int) $this->ruleMaxLength : null,
                    'regex'      => $this->ruleRegex ?: null,
                ],
            ], Auth::user());

            $this->clearForm();
            $this->flashMessage = 'Rule saved.';
            $this->flashType    = 'success';
        } catch (\Exception $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType    = 'error';
        }
    }

    public function deactivateRule(int $id, AdminFormRuleService $ruleService, StepUpService $stepUp): void
    {
        if (!$stepUp->isGranted()) {
            $this->requiresStepUp = true;
            return;
        }

        $ruleService->deactivate(FormRule::findOrFail($id), Auth::user());
        $this->flashMessage = 'Rule deactivated.';
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

    public function render(AdminFormRuleService $ruleService): \Illuminate\View\View
    {
        $rules = $ruleService->all();

        return view('livewire.admin.form-rules', compact('rules'));
    }
}
