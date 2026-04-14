<?php

namespace Database\Seeders;

use App\Models\DataDictionaryType;
use App\Models\DataDictionaryValue;
use Illuminate\Database\Seeder;

class DataDictionarySeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'service_type' => [
                'label' => 'Service Type',
                'values' => [
                    ['key' => 'consultation', 'label' => 'Consultation'],
                    ['key' => 'equipment_time', 'label' => 'Equipment Time'],
                    ['key' => 'editorial_review', 'label' => 'Editorial Review'],
                    ['key' => 'workshop', 'label' => 'Workshop'],
                    ['key' => 'data_support', 'label' => 'Data Support'],
                ],
            ],
            'cancellation_reason' => [
                'label' => 'Cancellation Reason',
                'values' => [
                    ['key' => 'user_request', 'label' => 'User Request'],
                    ['key' => 'scheduling_conflict', 'label' => 'Scheduling Conflict'],
                    ['key' => 'service_unavailable', 'label' => 'Service Unavailable'],
                    ['key' => 'policy_breach', 'label' => 'Policy Breach'],
                    ['key' => 'rescheduled', 'label' => 'Rescheduled'],
                    ['key' => 'other', 'label' => 'Other'],
                ],
            ],
            'breach_reason' => [
                'label' => 'Breach Reason',
                'values' => [
                    ['key' => 'no_show', 'label' => 'No Show'],
                    ['key' => 'late_cancel', 'label' => 'Late Cancellation'],
                ],
            ],
            'cancellation_consequence' => [
                'label' => 'Cancellation Consequence',
                'values' => [
                    ['key' => 'none', 'label' => 'None (free cancellation)'],
                    ['key' => 'fee', 'label' => 'Fee ($25.00)'],
                    ['key' => 'points', 'label' => 'Points Deduction (50 pts)'],
                ],
            ],
            'project_status' => [
                'label' => 'Research Project Status',
                'values' => [
                    ['key' => 'active', 'label' => 'Active'],
                    ['key' => 'completed', 'label' => 'Completed'],
                    ['key' => 'suspended', 'label' => 'Suspended'],
                    ['key' => 'pending', 'label' => 'Pending'],
                ],
            ],
            'audience_type' => [
                'label' => 'Audience Type',
                'values' => [
                    ['key' => 'faculty', 'label' => 'Faculty'],
                    ['key' => 'staff', 'label' => 'Staff'],
                    ['key' => 'graduate_learner', 'label' => 'Graduate Learner'],
                    ['key' => 'undergraduate_learner', 'label' => 'Undergraduate Learner'],
                    ['key' => 'postdoc', 'label' => 'Postdoctoral Researcher'],
                ],
            ],
        ];

        foreach ($types as $code => $config) {
            $type = DataDictionaryType::firstOrCreate(
                ['code' => $code],
                ['label' => $config['label'], 'is_system' => true]
            );

            foreach ($config['values'] as $i => $value) {
                DataDictionaryValue::firstOrCreate(
                    ['type_id' => $type->id, 'key' => $value['key']],
                    array_merge($value, ['sort_order' => $i, 'is_active' => true])
                );
            }
        }
    }
}
