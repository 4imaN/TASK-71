<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataDictionaryValue extends Model
{
    protected $fillable = ['type_id', 'key', 'label', 'description', 'metadata', 'sort_order', 'is_active'];
    protected function casts(): array { return ['metadata' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer']; }
    public function type(): BelongsTo { return $this->belongsTo(DataDictionaryType::class, 'type_id'); }
}
