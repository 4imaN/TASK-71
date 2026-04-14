<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataDictionaryType extends Model
{
    protected $fillable = ['code', 'label', 'description', 'is_system'];
    protected function casts(): array { return ['is_system' => 'boolean']; }
    public function values(): HasMany { return $this->hasMany(DataDictionaryValue::class, 'type_id'); }
    public function activeValues(): HasMany { return $this->values()->where('is_active', true)->orderBy('sort_order'); }
}
