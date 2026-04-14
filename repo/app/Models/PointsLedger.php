<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointsLedger extends Model
{
    protected $table = 'points_ledger';

    public $timestamps = false;
    const CREATED_AT = 'created_at';
    protected $fillable = ['user_id','amount','reason','reference_type','reference_id','balance_after'];
    protected function casts(): array { return ['amount' => 'integer', 'balance_after' => 'integer']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
