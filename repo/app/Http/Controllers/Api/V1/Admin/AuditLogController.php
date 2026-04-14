<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogService $service) {}

    /**
     * GET /api/v1/admin/audit-logs
     * Paginated, filterable list. Supports:
     *   ?action=, ?entity_type=, ?actor_username=,
     *   ?date_from=, ?date_to=, ?correlation_id=
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'action', 'entity_type', 'actor_id', 'actor_username',
            'date_from', 'date_to', 'correlation_id',
        ]);

        $entries = $this->service->list(array_filter($filters), perPage: 30);

        return response()->json($entries);
    }

    /**
     * GET /api/v1/admin/audit-logs/{id}
     * Single entry with actor eager-loaded.
     */
    public function show(int $id): JsonResponse
    {
        $entry = $this->service->find($id);

        return response()->json([
            'entry'              => $entry,
            'has_fingerprint'    => !empty($entry->device_fingerprint),
        ]);
    }

    /**
     * GET /api/v1/admin/audit-logs/correlation/{correlationId}
     * All entries sharing a correlation_id, ordered chronologically.
     */
    public function byCorrelation(string $correlationId): JsonResponse
    {
        $entries = $this->service->byCorrelation($correlationId);

        return response()->json(['entries' => $entries]);
    }
}
