<?php

namespace Database\Seeders;

use App\Models\SensitiveDataClassification;
use Illuminate\Database\Seeder;

class SensitiveDataClassificationSeeder extends Seeder
{
    public function run(): void
    {
        $classifications = [
            ['entity_type' => 'user',         'field_name' => 'password',       'classification' => 'confidential', 'mask_pattern' => 'full',   'encrypt_at_rest' => false],
            ['entity_type' => 'user_profile', 'field_name' => 'employee_id',    'classification' => 'pii',          'mask_pattern' => 'partial_last4',        'encrypt_at_rest' => true],
            ['entity_type' => 'user_profile', 'field_name' => 'cost_center',    'classification' => 'internal',     'mask_pattern' => 'full',   'encrypt_at_rest' => false],
            ['entity_type' => 'user',         'field_name' => 'booking_freeze_until', 'classification' => 'internal', 'mask_pattern' => 'full', 'encrypt_at_rest' => false],
        ];

        foreach ($classifications as $row) {
            SensitiveDataClassification::firstOrCreate(
                ['entity_type' => $row['entity_type'], 'field_name' => $row['field_name']],
                $row
            );
        }
    }
}
