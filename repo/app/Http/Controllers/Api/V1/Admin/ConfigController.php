<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminConfigService;
use App\Services\Admin\StepUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    public function __construct(
        private readonly AdminConfigService $configService,
        private readonly StepUpService $stepUpService,
    ) {}

    /**
     * GET /api/v1/admin/system-config
     */
    public function index(): JsonResponse
    {
        return response()->json(['groups' => $this->configService->allGrouped()]);
    }

    /**
     * PUT /api/v1/admin/system-config/{key}
     */
    public function update(Request $request, string $key): JsonResponse
    {
        if (!$this->stepUpService->isGranted()) {
            return response()->json(['message' => 'Step-up verification required. POST /api/v1/admin/step-up first.'], 403);
        }

        if (!in_array($key, $this->configService->knownKeys())) {
            return response()->json(['message' => "Unknown configuration key: {$key}"], 422);
        }

        $keyRules = AdminConfigService::VALIDATION[$key] ?? ['required'];
        $data     = $request->validate(['value' => $keyRules]);

        $config = $this->configService->update($key, $data['value'], $request->user());

        return response()->json(['config' => $config]);
    }
}
