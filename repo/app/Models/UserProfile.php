<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $fillable = ['user_id','employee_id','employee_id_hash','department_id','cost_center','job_title','employment_classification_id','employment_status','last_updated_at'];
    protected function casts(): array
    {
        return [
            'last_updated_at' => 'datetime',
            // PII — encrypted at rest using APP_KEY via Laravel's Encrypter.
            // Declared encrypt_at_rest => true in sensitive_data_classifications.
            'employee_id'     => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // Maintain the blind-index hash whenever employee_id changes.
        static::saving(function (self $profile) {
            if ($profile->isDirty('employee_id')) {
                $plain = $profile->employee_id; // decrypted via cast
                $profile->employee_id_hash = $plain !== null ? self::hashEmployeeId($plain) : null;
            }
        });
    }

    /**
     * Compute the deterministic HMAC-SHA256 blind index for an employee_id.
     * Used for exact-match lookups against the encrypted column.
     */
    public static function hashEmployeeId(string $plaintext): string
    {
        $key = config('app.key');
        // Strip the base64: prefix if present (Laravel APP_KEY format)
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        return hash_hmac('sha256', $plaintext, $key);
    }

    /**
     * Find a profile by plaintext employee_id using the blind-index hash.
     */
    public static function findByEmployeeId(string $plaintext): ?self
    {
        return static::where('employee_id_hash', self::hashEmployeeId($plaintext))->first();
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function employmentClassification(): BelongsTo { return $this->belongsTo(DataDictionaryValue::class, 'employment_classification_id'); }
}
