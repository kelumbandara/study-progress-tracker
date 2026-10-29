<?php

namespace Database\Seeders;

use App\Services\SampleData;
use Illuminate\Database\Seeder;

/**
 * Optional demo data (not run by default): php artisan db:seed --class=SampleDataSeeder
 * Everything it adds is flagged SAMPLE and can be removed from the Settings page.
 */
class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info(app(SampleData::class)->seed() ? 'Sample data loaded.' : 'Sample data already present; nothing changed.');
    }
}
