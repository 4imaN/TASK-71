<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupLog extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';
    protected $fillable = ['snapshot_filename','snapshot_path','file_size_bytes','type','status','error_message','created_at'];
    protected function casts(): array { return ['file_size_bytes' => 'integer', 'created_at' => 'datetime']; }
    public function restoreTests(): HasMany { return $this->hasMany(RestoreTestLog::class); }
}
