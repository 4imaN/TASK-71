<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemConfig extends Model
{
    protected $table = 'system_config';
    public $timestamps = false;
    const UPDATED_AT = 'updated_at';

    protected $fillable = ['key', 'value', 'type', 'description', 'is_sensitive', 'updated_by'];

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'updated_at'   => 'datetime',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function typedValue(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'decimal' => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($this->value, true),
            default   => $this->value,
        };
    }
}
