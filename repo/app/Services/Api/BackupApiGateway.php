<?php

namespace App\Services\Api;

use App\Models\BackupLog;
use App\Models\User;
use App\Services\Admin\BackupService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * API gateway for the admin backup/restore surface.
 *
 * This class is the shared contract for all backup operations. Both the
 * REST API surface (Admin\BackupController) and the Livewire surface
 * (BackupComponent) delegate through this gateway so that listing,
 * triggering, and restore-test recording are never duplicated.
 *
 * Step-up verification for write operations is a transport-level concern
 * enforced by the caller before invoking mutations.
 *
 * Mirrors the contract of:
 *   GET  /api/v1/admin/backups
 *   POST /api/v1/admin/backups
 *   POST /api/v1/admin/backups/{id}/restore-tests
 */
class BackupApiGateway
{
    public function __construct(
        private readonly BackupService $backupService,
    ) {}

    // ── List ─────────────────────────────────────────────────────────────────

    /**
     * Paginated list of backup logs, newest first, with restore-test counts.
     *
     * Mirrors the contract of GET /api/v1/admin/backups.
     */
    public function list(int $perPage = 20): LengthAwarePaginator
    {
        return $this->backupService->list($perPage);
    }

    // ── Trigger ──────────────────────────────────────────────────────────────

    /**
     * Execute a backup synchronously and return the result.
     *
     * Mirrors the contract of POST /api/v1/admin/backups.
     * Callers must ensure step-up is granted before invoking this method.
     */
    public function run(User $admin, string $type = 'manual'): ApiResult
    {
        try {
            $log = $this->backupService->run($admin, $type);

            if ($log->status === 'success') {
                return ApiResult::success($log, 201);
            }

            return ApiResult::failure('Backup failed: ' . $log->error_message);
        } catch (\Throwable $e) {
            return ApiResult::failure('Unexpected error: ' . $e->getMessage());
        }
    }

    // ── Restore-test ─────────────────────────────────────────────────────────

    /**
     * Record a restore-test drill result against a specific backup snapshot.
     *
     * Mirrors the contract of POST /api/v1/admin/backups/{id}/restore-tests.
     */
    public function recordRestoreTest(int $backupLogId, User $tester, string $result, ?string $notes = null): ApiResult
    {
        $backup = BackupLog::find($backupLogId);

        if (!$backup) {
            return ApiResult::failure('Backup record not found.', 404);
        }

        try {
            $restoreTest = $this->backupService->recordRestoreTest(
                backup: $backup,
                tester: $tester,
                result: $result,
                notes:  $notes,
            );

            return ApiResult::success($restoreTest, 201);
        } catch (\Throwable $e) {
            return ApiResult::failure('Error recording restore test: ' . $e->getMessage());
        }
    }

    // ── Constants ────────────────────────────────────────────────────────────

    /**
     * Expose retention days for UI display.
     */
    public function retentionDays(): int
    {
        return BackupService::RETENTION_DAYS;
    }
}
