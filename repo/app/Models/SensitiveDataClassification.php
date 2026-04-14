<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SensitiveDataClassification extends Model
{
    protected $fillable = ['entity_type','field_name','classification','mask_pattern','encrypt_at_rest'];
    protected function casts(): array { return ['encrypt_at_rest' => 'boolean']; }
}
