<?php

namespace Database\Seeders;

use App\Models\TargetAudience;
use Illuminate\Database\Seeder;

class TargetAudienceSeeder extends Seeder
{
    public function run(): void
    {
        $audiences = [
            ['code' => 'faculty',                 'label' => 'Faculty',                   'sort_order' => 1],
            ['code' => 'staff',                   'label' => 'Staff',                     'sort_order' => 2],
            ['code' => 'graduate_learner',        'label' => 'Graduate Learner',          'sort_order' => 3],
            ['code' => 'undergraduate_learner',   'label' => 'Undergraduate Learner',     'sort_order' => 4],
            ['code' => 'postdoc',                 'label' => 'Postdoctoral Researcher',   'sort_order' => 5],
        ];

        foreach ($audiences as $audience) {
            TargetAudience::firstOrCreate(
                ['code' => $audience['code']],
                array_merge($audience, ['is_active' => true])
            );
        }
    }
}
