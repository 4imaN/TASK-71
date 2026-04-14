<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'uuid',
        'username',
        'display_name',
        'password',
        'password_changed_at',
        'failed_attempts',
        'locked_until',
        'booking_freeze_until',
        'audience_type',
        'status',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password'             => 'hashed',
            'password_changed_at'  => 'datetime',
            'locked_until'         => 'datetime',
            'booking_freeze_until' => 'datetime',
            'must_change_password' => 'boolean',
            'failed_attempts'      => 'integer',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function passwordHistory(): HasMany
    {
        return $this->hasMany(PasswordHistory::class)->latest('created_at');
    }

    public function appSessions(): HasMany
    {
        return $this->hasMany(AppSession::class, 'user_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(UserFavorite::class);
    }

    public function recentViews(): HasMany
    {
        return $this->hasMany(UserRecentView::class)->latest('viewed_at');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function pointsLedger(): HasMany
    {
        return $this->hasMany(PointsLedger::class);
    }

    public function noShowBreaches(): HasMany
    {
        return $this->hasMany(NoShowBreach::class);
    }

    public function bookingFreezes(): HasMany
    {
        return $this->hasMany(BookingFreeze::class);
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    // ── Domain helpers ────────────────────────────────────────────────────────

    public function isAccountLocked(): bool
    {
        return $this->status === 'locked'
            && $this->locked_until
            && $this->locked_until->isFuture();
    }

    public function isBookingFrozen(): bool
    {
        return $this->booking_freeze_until
            && $this->booking_freeze_until->isFuture();
    }

    public function pointsBalance(): int
    {
        $latest = $this->pointsLedger()->latest('id')->first();
        return $latest ? $latest->balance_after : 0;
    }
}
