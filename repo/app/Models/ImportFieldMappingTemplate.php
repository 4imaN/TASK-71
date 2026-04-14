<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportFieldMappingTemplate extends Model
{
    protected $fillable = ['name','source_system','entity_type','field_mapping','created_by'];
    protected function casts(): array { return ['field_mapping' => 'array']; }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
