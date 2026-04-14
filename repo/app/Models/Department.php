<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['code','name','parent_department_id','is_active','last_updated_at'];
    protected function casts(): array { return ['is_active' => 'boolean', 'last_updated_at' => 'datetime']; }
    public function parent(): BelongsTo { return $this->belongsTo(Department::class, 'parent_department_id'); }
    public function children(): HasMany { return $this->hasMany(Department::class, 'parent_department_id'); }
}
