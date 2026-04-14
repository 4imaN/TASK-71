<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResearchProject extends Model
{
    use SoftDeletes;
    protected $fillable = ['project_number','title','normalized_title','principal_investigator_name','department_id','grant_id','patent_number','project_status_id','start_date','end_date','last_updated_at'];
    protected function casts(): array { return ['start_date' => 'date', 'end_date' => 'date', 'last_updated_at' => 'datetime']; }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function projectStatus(): BelongsTo { return $this->belongsTo(DataDictionaryValue::class, 'project_status_id'); }
    public function services(): BelongsToMany { return $this->belongsToMany(Service::class, 'service_research_project_links'); }
}
