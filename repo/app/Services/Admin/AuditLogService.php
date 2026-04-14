<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read-only service for the audit log viewer.
 *
 * Before/after states in audit_logs are already written with sensitive fields
 * redacted by SensitiveDataRedactor at log time.  This service returns the
 * stored (already-masked) data without further transformation.
 *
 * ip_address is visible in the detail view; device_fingerprint is surfaced as
 * a boolean ("present / absent") only — the raw hash is an implementation
 * detail with no display value.
 */
class AuditLogService
{
    /**
     * Paginated, filterable list of audit entries, newest first.
     *
     * Supported filters:
     *   action         – partial LIKE match on the action string
     *   entity_type    – exact match
     *   actor_id       – exact match (integer)
     *   actor_username – partial match via join on users table
     *   date_from      – occurred_at >= (inclusive)
     *   date_to        – occurred_at <= (inclusive, end of day)
     *   correlation_id – exact UUID match
     */
    public function list(array $filters = [], int $perPage = 30): LengthAwarePaginator
    {
        $query = AuditLog::with('actor')
            ->orderByDesc('occurred_at');

        if (!empty($filters['action'])) {
            $query->where('action', 'LIKE', '%' . $filters['action'] . '%');
        }

        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (isset($filters['actor_id']) && $filters['actor_id'] !== '') {
            $query->where('actor_id', (int) $filters['actor_id']);
        }

        if (!empty($filters['actor_username'])) {
            $query->whereHas('actor', function ($q) use ($filters) {
                $q->where('username', 'LIKE', '%' . $filters['actor_username'] . '%');
            });
        }

        if (!empty($filters['date_from'])) {
            $query->where('occurred_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('occurred_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        if (!empty($filters['correlation_id'])) {
            $query->where('correlation_id', $filters['correlation_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Single audit entry with actor eager-loaded.
     */
    public function find(int $id): AuditLog
    {
        return AuditLog::with('actor')->findOrFail($id);
    }

    /**
     * All entries for a given correlation_id, ordered chronologically.
     * Useful for tracing a multi-step operation chain.
     */
    public function byCorrelation(string $correlationId): Collection
    {
        return AuditLog::with('actor')
            ->where('correlation_id', $correlationId)
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * Distinct entity_type values for the filter dropdown.
     */
    public function entityTypes(): array
    {
        return AuditLog::select('entity_type')
            ->whereNotNull('entity_type')
            ->distinct()
            ->orderBy('entity_type')
            ->pluck('entity_type')
            ->toArray();
    }
}
