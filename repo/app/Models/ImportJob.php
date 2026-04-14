<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportJob extends Model
{
    protected $fillable = ['uuid','entity_type','source_system','file_format','original_filename','stored_path','field_mapping','status','last_sync_timestamp','conflict_resolution_strategy','total_records','processed_count','error_count','conflict_count','error_summary','created_by','completed_at'];
    protected function casts(): array { return ['field_mapping' => 'array', 'last_sync_timestamp' => 'datetime', 'completed_at' => 'datetime']; }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function conflicts(): HasMany { return $this->hasMany(ImportConflict::class); }
    public function mappingTemplates(): HasMany { return $this->hasMany(ImportFieldMappingTemplate::class); }
}
