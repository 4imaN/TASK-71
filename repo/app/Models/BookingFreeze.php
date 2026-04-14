<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingFreeze extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';
    protected $fillable = ['user_id','starts_at','ends_at','reason','trigger_breach_count','lifted_at'];
    protected function casts(): array { return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'lifted_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function isActive(): bool { return $this->lifted_at === null && $this->ends_at->isFuture(); }
}
