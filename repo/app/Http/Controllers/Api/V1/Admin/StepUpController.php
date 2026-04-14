<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\StepUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StepUpController extends Controller
{
    public function __construct(private readonly StepUpService $stepUpService) {}

    /**
     * POST /api/v1/admin/step-up
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if ($this->stepUpService->verify($request->user(), $request->input('password'))) {
            return response()->json(['message' => 'Step-up verified. Grant valid for 15 minutes.']);
        }

        return response()->json(['message' => 'Incorrect password.'], 422);
    }
}
