<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\StepUpService;
use App\Services\Admin\UserGovernanceService;
use App\Services\Audit\SensitiveDataRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administrator API surface for user account governance.
 *
 * All write operations require a valid step-up grant.
 * Role: administrator enforced by route middleware.
 */
class AdminUserController extends Controller
{
    public function __construct(
        private readonly UserGovernanceService $service,
        private readonly StepUpService         $stepUp,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /** GET /api/v1/admin/users */
    public function index(Request $request): JsonResponse
    {
        $filters = array_filter($request->only(['search', 'status', 'role']));
        $users   = $this->service->list($filters, perPage: 25);

        // Mask classified profile fields (e.g. employee_id) before serialisation.
        // list() eager-loads 'profile', so items are User models; we transform to
        // plain arrays so the paginator still serialises correctly.
        $users->getCollection()->transform(function ($user) {
            $data = $user->toArray();
            if (isset($data['profile'])) {
                $data['profile'] = $this->redactor->maskForResponse('user_profile', $data['profile']);
            }
            return $data;
        });

        return response()->json($users);
    }

    /** GET /api/v1/admin/users/{id} */
    public function show(int $id): JsonResponse
    {
        $user = $this->service->show($id)->load(['roles', 'profile']);
        $data = $user->toArray();

        if (isset($data['profile'])) {
            $data['profile'] = $this->redactor->maskForResponse('user_profile', $data['profile']);
        }

        return response()->json(['user' => $data]);
    }

    /** POST /api/v1/admin/users/{id}/lock */
    public function lock(Request $request, int $id): JsonResponse
    {
        if (!$this->stepUp->isGranted()) {
            return $this->stepUpRequired();
        }

        $data   = $request->validate(['until' => ['nullable', 'date']]);
        $target = $this->resolveUser($id);
        $until  = !empty($data['until']) ? new \DateTime($data['until']) : null;

        $updated = $this->service->lockAccount($target, $request->user(), $until);

        return response()->json(['user' => $updated]);
    }

    /** POST /api/v1/admin/users/{id}/unlock */
    public function unlock(Request $request, int $id): JsonResponse
    {
        if (!$this->stepUp->isGranted()) {
            return $this->stepUpRequired();
        }

        $updated = $this->service->unlockAccount($this->resolveUser($id), $request->user());

        return response()->json(['user' => $updated]);
    }

    /** POST /api/v1/admin/users/{id}/suspend */
    public function suspend(Request $request, int $id): JsonResponse
    {
        if (!$this->stepUp->isGranted()) {
            return $this->stepUpRequired();
        }

        $data    = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $updated = $this->service->suspendAccount(
            $this->resolveUser($id),
            $request->user(),
            $data['reason'] ?? null,
        );

        return response()->json(['user' => $updated]);
    }

    /** POST /api/v1/admin/users/{id}/reactivate */
    public function reactivate(Request $request, int $id): JsonResponse
    {
        if (!$this->stepUp->isGranted()) {
            return $this->stepUpRequired();
        }

        $updated = $this->service->reactivateAccount($this->resolveUser($id), $request->user());

        return response()->json(['user' => $updated]);
    }

    /** POST /api/v1/admin/users/{id}/force-password-reset */
    public function forcePasswordReset(Request $request, int $id): JsonResponse
    {
        if (!$this->stepUp->isGranted()) {
            return $this->stepUpRequired();
        }

        $updated = $this->service->forcePasswordReset($this->resolveUser($id), $request->user());

        return response()->json(['user' => $updated]);
    }

    /**
     * POST /api/v1/admin/users/{id}/set-password
     * Administrator sets an initial password for an imported/provisioned user.
     * Requires step-up verification. The target user will be forced to change
     * the password on next login.
     */
    public function setPassword(Request $request, int $id): JsonResponse
    {
        if (!$this->stepUp->isGranted()) {
            return $this->stepUpRequired();
        }

        $data = $request->validate([
            'password'              => ['required', 'string', 'min:12'],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ]);

        $target = $this->resolveUser($id);
        $result = $this->service->setInitialPassword($target, $data['password'], $request->user());

        if (!$result['ok']) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $result['errors']], 422);
        }

        return response()->json(['user' => $target->refresh(), 'must_change_password' => true]);
    }

    /** POST /api/v1/admin/users/{id}/revoke-sessions */
    public function revokeSessions(Request $request, int $id): JsonResponse
    {
        if (!$this->stepUp->isGranted()) {
            return $this->stepUpRequired();
        }

        $count = $this->service->revokeSessions($this->resolveUser($id), $request->user());

        return response()->json(['sessions_revoked' => $count]);
    }

    /** POST /api/v1/admin/users/{id}/roles */
    public function assignRole(Request $request, int $id): JsonResponse
    {
        if (!$this->stepUp->isGranted()) {
            return $this->stepUpRequired();
        }

        $data = $request->validate(['role' => ['required', 'string']]);

        try {
            $updated = $this->service->assignRole($this->resolveUser($id), $data['role'], $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['user' => $updated->load('roles')]);
    }

    /** DELETE /api/v1/admin/users/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (!$this->stepUp->isGranted()) {
            return $this->stepUpRequired();
        }

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $this->service->deleteAccount(
                $this->resolveUser($id),
                $request->user(),
                $data['reason'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    /** DELETE /api/v1/admin/users/{id}/roles/{role} */
    public function revokeRole(Request $request, int $id, string $role): JsonResponse
    {
        if (!$this->stepUp->isGranted()) {
            return $this->stepUpRequired();
        }

        try {
            $updated = $this->service->revokeRole($this->resolveUser($id), $role, $request->user());
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['user' => $updated->load('roles')]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveUser(int $id): \App\Models\User
    {
        return \App\Models\User::withTrashed()->findOrFail($id);
    }

    private function stepUpRequired(): JsonResponse
    {
        return response()->json(['message' => 'Step-up verification required.'], 403);
    }
}
