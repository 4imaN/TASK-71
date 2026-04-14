<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoShowBreach extends Model
{
    public $timestamps = false;
    protected $fillable = ['user_id','reservation_id','breach_type','occurred_at'];
    protected function casts(): array { return ['occurred_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
}
