<?php

namespace App\Services\Api;

use App\Models\User;
use App\Services\Admin\AdminConfigService;

/**
 * API gateway for the admin system-configuration surface.
 *
 * This class is the shared contract for reading and writing system
 * configuration. Both the REST API surface (Admin\ConfigController) and
 * the Livewire surface (PolicyConfigComponent) delegate through this
 * gateway so that grouped reads, validation, and audited bulk writes
 * are never duplicated.
 *
 * Step-up verification is a transport-level concern enforced by the
 * caller (controller or Livewire component) before invoking mutations.
 *
 * Mirrors the contract of:
 *   GET /api/v1/admin/system-config
 *   PUT /api/v1/admin/system-config/{key}
 */
class AdminConfigApiGateway
{
    public function __construct(
        private readonly AdminConfigService $configService,
    ) {}

    // ── Read ─────────────────────────────────────────────────────────────────

    /**
     * Return all config items keyed by group.
     *
     * Mirrors the contract of GET /api/v1/admin/system-config.
     */
    public function allGrouped(): array
    {
        return $this->configService->allGrouped();
    }

    // ── Write ────────────────────────────────────────────────────────────────

    /**
     * Bulk update a key→value map. All are validated before any are written.
     *
     * Mirrors the contract of PUT /api/v1/admin/system-config/{key}
     * applied to multiple keys in one call.
     *
     * Callers must ensure step-up is granted before invoking this method.
     */
    public function updateBulk(array $changes, User $admin): ApiResult
    {
        try {
            $this->configService->updateBulk($changes, $admin);
            return ApiResult::success();
        } catch (\Exception $e) {
            return ApiResult::failure($e->getMessage());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * All known keys (flat).
     */
    public function knownKeys(): array
    {
        return $this->configService->knownKeys();
    }

    /**
     * Expose group definitions for callers that need to filter
     * changes to a specific config group.
     */
    public function groups(): array
    {
        return AdminConfigService::GROUPS;
    }

    /**
     * Expose per-key validation rules.
     */
    public function validationRules(): array
    {
        return AdminConfigService::VALIDATION;
    }
}
