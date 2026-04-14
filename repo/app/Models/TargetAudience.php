<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TargetAudience extends Model
{
    use HasFactory;
    protected $fillable = ['code', 'label', 'is_active', 'sort_order'];
    protected function casts(): array { return ['is_active' => 'boolean', 'sort_order' => 'integer']; }
    public function services(): BelongsToMany { return $this->belongsToMany(Service::class, 'service_audiences', 'audience_id', 'service_id'); }
}
