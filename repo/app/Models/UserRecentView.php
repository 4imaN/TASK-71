<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRecentView extends Model
{
    public $timestamps = false;
    protected $fillable = ['user_id','service_id','viewed_at'];
    protected function casts(): array { return ['viewed_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
}
