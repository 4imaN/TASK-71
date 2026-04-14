<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\FormRule;
use App\Services\Admin\AdminFormRuleService;
use App\Services\Admin\StepUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormRuleController extends Controller
{
    public function __construct(
        private readonly AdminFormRuleService $ruleService,
        private readonly StepUpService $stepUpService,
    ) {}

    /**
     * GET /api/v1/admin/form-rules
     */
    public function index(): JsonResponse
    {
        return response()->json(['rules' => $this->ruleService->all()]);
    }

    /**
     * POST /api/v1/admin/form-rules
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->stepUpService->isGranted()) {
            return response()->json(['message' => 'Step-up required.'], 403);
        }

        $data = $request->validate([
            'entity_type'        => ['required', 'string', 'max:80'],
            'field_name'         => ['required', 'string', 'max:80'],
            'rules.required'     => ['nullable', 'boolean'],
            'rules.min_length'   => ['nullable', 'integer', 'min:1'],
            'rules.max_length'   => ['nullable', 'integer', 'min:1'],
            'rules.regex'        => ['nullable', 'string'],
            'is_active'          => ['boolean'],
        ]);

        $rule = $this->ruleService->upsert($data, $request->user());

        return response()->json(['rule' => $rule], 201);
    }

    /**
     * PUT /api/v1/admin/form-rules/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->stepUpService->isGranted()) {
            return response()->json(['message' => 'Step-up required.'], 403);
        }

        $formRule = FormRule::findOrFail($id);
        $data     = $request->validate([
            'rules.required'   => ['nullable', 'boolean'],
            'rules.min_length' => ['nullable', 'integer', 'min:1'],
            'rules.max_length' => ['nullable', 'integer', 'min:1'],
            'rules.regex'      => ['nullable', 'string'],
            'is_active'        => ['boolean'],
        ]);
        $data['entity_type'] = $formRule->entity_type;
        $data['field_name']  = $formRule->field_name;

        $rule = $this->ruleService->upsert($data, $request->user());

        return response()->json(['rule' => $rule]);
    }

    /**
     * DELETE /api/v1/admin/form-rules/{id} — deactivate (soft)
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (!$this->stepUpService->isGranted()) {
            return response()->json(['message' => 'Step-up required.'], 403);
        }

        $formRule = FormRule::findOrFail($id);
        $this->ruleService->deactivate($formRule, $request->user());

        return response()->json(null, 204);
    }
}
