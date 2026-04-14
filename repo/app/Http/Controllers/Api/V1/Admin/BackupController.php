<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupLog;
use App\Services\Admin\BackupService;
use App\Services\Admin\StepUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackupController extends Controller
{
    public function __construct(
        private readonly BackupService $service,
        private readonly StepUpService $stepUp,
    ) {}

    /**
     * GET /api/v1/admin/backups
     * Paginated list of backup logs with restore-test counts.
     */
    public function index(): JsonResponse
    {
        $backups = $this->service->list(perPage: 20);

        return response()->json($backups);
    }

    /**
     * POST /api/v1/admin/backups
     * Trigger a manual backup. Requires step-up grant.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->stepUp->isGranted()) {
            return response()->json(['message' => 'Step-up verification required.'], 403);
        }

        $log = $this->service->run($request->user(), 'manual');

        $statusCode = $log->status === 'success' ? 201 : 422;

        return response()->json(['backup' => $log], $statusCode);
    }

    /**
     * GET /api/v1/admin/backups/{id}
     * Backup detail with all recorded restore tests.
     */
    public function show(int $id): JsonResponse
    {
        $backup = BackupLog::with(['restoreTests.testedBy'])->findOrFail($id);

        return response()->json(['backup' => $backup]);
    }

    /**
     * POST /api/v1/admin/backups/{id}/restore-tests
     * Record a restore-test result for a specific backup snapshot.
     */
    public function storeRestoreTest(Request $request, int $id): JsonResponse
    {
        $backup = BackupLog::findOrFail($id);

        $data = $request->validate([
            'test_result' => ['required', 'string', 'in:success,partial,failed'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);

        $restoreTest = $this->service->recordRestoreTest(
            backup: $backup,
            tester: $request->user(),
            result: $data['test_result'],
            notes:  $data['notes'] ?? null,
        );

        return response()->json(['restore_test' => $restoreTest], 201);
    }
}
