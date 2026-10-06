<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data and roles are needed everywhere. The provisional reference data (taxes, waste codes,
     * accreditation types, plans, legal placeholders) is seeded until Diva Software validates the real values.
     * Demonstration data is opt-in: `php artisan db:seed --class=DemoSeeder` (never in production).
     */
    public function run(): void
    {
        $this->call([ReferenceDataSeeder::class, AuthorizationSeeder::class, ProvisionalReferenceSeeder::class]);
    }
}
