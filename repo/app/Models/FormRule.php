<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class FormRule extends Model
{
    protected $fillable = ['entity_type', 'field_name', 'rules', 'is_active'];
    protected function casts(): array { return ['rules' => 'array', 'is_active' => 'boolean']; }
}
