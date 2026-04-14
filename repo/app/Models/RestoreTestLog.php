<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestoreTestLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['backup_log_id','tested_by','test_result','notes','tested_at'];
    protected function casts(): array { return ['tested_at' => 'datetime']; }
    public function backupLog(): BelongsTo { return $this->belongsTo(BackupLog::class); }
    public function testedBy(): BelongsTo { return $this->belongsTo(User::class, 'tested_by'); }
}
