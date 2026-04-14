<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DataDictionarySeeder::class,
            SystemConfigSeeder::class,
            RolesPermissionsSeeder::class,
            TargetAudienceSeeder::class,
            SensitiveDataClassificationSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
