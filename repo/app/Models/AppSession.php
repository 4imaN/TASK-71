<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Custom session model tracking device fingerprint and revocation.
 * Backed by the 'sessions' table (extended from Laravel's default).
 */
class AppSession extends Model
{
    protected $table = 'sessions';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'ip_address', 'user_agent',
        'payload', 'last_activity', 'device_fingerprint',
        'last_active_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
            'revoked_at'     => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
