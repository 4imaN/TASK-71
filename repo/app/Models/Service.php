<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid','slug','title','description','eligibility_notes',
        'category_id','service_type_id','is_free','fee_amount','fee_currency',
        'requires_manual_confirmation','status','created_by','updated_by',
    ];
    protected function casts(): array
    {
        return [
            'is_free' => 'boolean',
            'fee_amount' => 'decimal:2',
            'requires_manual_confirmation' => 'boolean',
        ];
    }
    public function category(): BelongsTo { return $this->belongsTo(ServiceCategory::class, 'category_id'); }
    public function serviceType(): BelongsTo { return $this->belongsTo(DataDictionaryValue::class, 'service_type_id'); }
    public function tags(): BelongsToMany { return $this->belongsToMany(Tag::class, 'service_tags'); }
    public function audiences(): BelongsToMany { return $this->belongsToMany(TargetAudience::class, 'service_audiences', 'service_id', 'audience_id'); }
    public function timeSlots(): HasMany { return $this->hasMany(TimeSlot::class); }
    public function reservations(): HasMany { return $this->hasMany(Reservation::class); }
    public function researchProjects(): BelongsToMany { return $this->belongsToMany(ResearchProject::class, 'service_research_project_links'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function feeDisplay(): string { return $this->is_free ? 'Free' : number_format($this->fee_amount, 2); }
}
