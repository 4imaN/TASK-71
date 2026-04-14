<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportConflict extends Model
{
    protected $fillable = ['import_job_id','record_identifier','local_record','incoming_record','field_diffs','resolution','resolved_record','resolved_by','resolved_at'];
    protected function casts(): array { return ['local_record' => 'array', 'incoming_record' => 'array', 'field_diffs' => 'array', 'resolved_record' => 'array', 'resolved_at' => 'datetime']; }
    public function importJob(): BelongsTo { return $this->belongsTo(ImportJob::class); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
}
