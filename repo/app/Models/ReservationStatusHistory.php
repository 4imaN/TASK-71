<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationStatusHistory extends Model
{
    protected $table = 'reservation_status_history';

    public $timestamps = false;
    protected $fillable = ['reservation_id','from_status','to_status','actor_id','actor_type','reason','occurred_at'];
    protected function casts(): array { return ['occurred_at' => 'datetime']; }
    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
}
