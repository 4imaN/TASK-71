<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST surface for authenticated user password changes.
 *
 * Covers voluntary change, forced change (must_change_password), and
 * rotation-expired change — the service layer handles all three.
 *
 * POST /api/v1/auth/password/change
 *
 * Request body:
 *   { "current_password": "…", "new_password": "…", "new_password_confirmation": "…" }
 *
 * Responses:
 *   200 — password changed
 *   422 — validation error (wrong current password, complexity violation, history match)
 *   401 — unauthenticated (handled by auth middleware)
 */
class PasswordChangeController extends Controller
{
    public function __construct(private readonly PasswordChangeService $service) {}

    public function change(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password'             => ['required', 'string'],
            'new_password'                 => ['required', 'string'],
            'new_password_confirmation'    => ['required', 'string', 'same:new_password'],
        ]);

        /** @var \App\Models\User $user */
        $user   = $request->user();
        $result = $this->service->change($user, $data['current_password'], $data['new_password']);

        if (!$result['ok']) {
            return response()->json([
                'message' => 'Password change failed.',
                'errors'  => $result['errors'],
            ], 422);
        }

        return response()->json(['message' => 'Password changed successfully.']);
    }
}
