<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimeSlot extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = ['uuid','service_id','starts_at','ends_at','capacity','booked_count','status','created_by','updated_by'];
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'capacity' => 'integer', 'booked_count' => 'integer'];
    }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function reservations(): HasMany { return $this->hasMany(Reservation::class); }
    public function hasCapacity(): bool { return $this->booked_count < $this->capacity; }
    public function remainingCapacity(): int { return max(0, $this->capacity - $this->booked_count); }
}
