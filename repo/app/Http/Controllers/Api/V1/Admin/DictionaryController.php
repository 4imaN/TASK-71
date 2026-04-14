<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DataDictionaryType;
use App\Models\DataDictionaryValue;
use App\Services\Admin\AdminDictionaryService;
use App\Services\Admin\StepUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DictionaryController extends Controller
{
    public function __construct(
        private readonly AdminDictionaryService $dictService,
        private readonly StepUpService $stepUpService,
    ) {}

    /**
     * GET /api/v1/admin/data-dictionary
     */
    public function index(): JsonResponse
    {
        return response()->json(['types' => $this->dictService->allTypes()]);
    }

    /**
     * POST /api/v1/admin/data-dictionary/{typeCode}/values
     */
    public function storeValue(Request $request, string $typeCode): JsonResponse
    {
        if (!$this->stepUpService->isGranted()) {
            return response()->json(['message' => 'Step-up required.'], 403);
        }

        $type = DataDictionaryType::where('code', $typeCode)->firstOrFail();
        $data = $request->validate([
            'key'         => ['required', 'string', 'max:80'],
            'label'       => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        $value = $this->dictService->createValue($type, $data, $request->user());

        return response()->json(['value' => $value], 201);
    }

    /**
     * PUT /api/v1/admin/data-dictionary/values/{id}
     */
    public function updateValue(Request $request, int $id): JsonResponse
    {
        if (!$this->stepUpService->isGranted()) {
            return response()->json(['message' => 'Step-up required.'], 403);
        }

        $value = DataDictionaryValue::findOrFail($id);
        $data  = $request->validate([
            'label'       => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_active'   => ['boolean'],
        ]);

        $value = $this->dictService->updateValue($value, $data, $request->user());

        return response()->json(['value' => $value]);
    }
}
