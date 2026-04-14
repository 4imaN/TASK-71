<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid','user_id','service_id','time_slot_id','status',
        'requested_at','confirmed_at','expires_at','cancelled_at',
        'cancellation_reason_id','cancellation_consequence','cancellation_consequence_amount',
        'checked_in_at','checked_out_at','rescheduled_from_id','notes',
    ];
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime', 'confirmed_at' => 'datetime',
            'expires_at' => 'datetime', 'cancelled_at' => 'datetime',
            'checked_in_at' => 'datetime', 'checked_out_at' => 'datetime',
            'cancellation_consequence_amount' => 'decimal:2',
        ];
    }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function timeSlot(): BelongsTo { return $this->belongsTo(TimeSlot::class); }
    public function cancellationReason(): BelongsTo { return $this->belongsTo(DataDictionaryValue::class, 'cancellation_reason_id'); }
    public function rescheduledFrom(): BelongsTo { return $this->belongsTo(Reservation::class, 'rescheduled_from_id'); }
    public function statusHistory(): HasMany { return $this->hasMany(ReservationStatusHistory::class); }
    public function isPending(): bool { return $this->status === 'pending'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isExpired(): bool { return $this->isPending() && $this->expires_at?->isPast(); }
}
