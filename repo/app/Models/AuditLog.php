<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log. Never UPDATE or DELETE rows.
 * PostgreSQL rules enforce this at the DB layer.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    /**
     * The raw fingerprint hash must never appear in API responses.
     * AuditLogController::show() already surfaces it as has_fingerprint (bool).
     */
    protected $hidden = ['device_fingerprint'];

    protected $fillable = [
        'correlation_id', 'actor_id', 'actor_type', 'action',
        'entity_type', 'entity_id', 'before_state', 'after_state',
        'ip_address', 'device_fingerprint', 'metadata', 'occurred_at',
    ];
    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state'  => 'array',
            'metadata'     => 'array',
            'occurred_at'  => 'datetime',
        ];
    }

    /** The user who performed this action (null for system events). */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
