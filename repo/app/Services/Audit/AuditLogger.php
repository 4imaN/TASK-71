<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Str;

/**
 * Append-only audit logger.
 *
 * All writes are INSERT-only. PostgreSQL rules on the audit_logs table
 * prevent UPDATE and DELETE at the database layer.
 *
 * Sensitive fields in before/after state must be redacted via
 * SensitiveDataRedactor before calling log().
 */
class AuditLogger
{
    public function __construct(
        private readonly SensitiveDataRedactor $redactor
    ) {}

    public function log(
        string $action,
        ?int $actorId = null,
        string $actorType = 'user',
        ?string $entityType = null,
        ?int $entityId = null,
        array $beforeState = [],
        array $afterState = [],
        array $metadata = [],
        ?string $correlationId = null,
    ): AuditLog {
        $request = request();

        return AuditLog::create([
            'correlation_id'    => $correlationId ?? (string) Str::uuid(),
            'actor_id'          => $actorId,
            'actor_type'        => $actorType,
            'action'            => $action,
            'entity_type'       => $entityType,
            'entity_id'         => $entityId,
            'before_state'      => $beforeState ? $this->redactor->redact($entityType, $beforeState) : null,
            'after_state'       => $afterState  ? $this->redactor->redact($entityType, $afterState)  : null,
            'ip_address'        => $request?->ip(),
            'device_fingerprint'=> $request ? $this->fingerprint($request) : null,
            'metadata'          => $metadata ?: null,
            'occurred_at'       => now(),
        ]);
    }

    public function system(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $afterState = [],
        array $metadata = [],
    ): AuditLog {
        return $this->log(
            action: $action,
            actorType: 'system',
            entityType: $entityType,
            entityId: $entityId,
            afterState: $afterState,
            metadata: $metadata,
        );
    }

    private function fingerprint(\Illuminate\Http\Request $request): string
    {
        // Hash of User-Agent + Accept-Language + IP /24 subnet
        // Used for anomaly detection only — not an authentication factor.
        $subnet = implode('.', array_slice(explode('.', $request->ip() ?? ''), 0, 3));
        return hash('sha256', implode('|', [
            $request->userAgent() ?? '',
            $request->header('Accept-Language', ''),
            $subnet,
        ]));
    }
}
