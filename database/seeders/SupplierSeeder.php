<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierImportScheduleService;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Supplier::query()->updateOrCreate(
            ['slug' => 'demo-distribution'],
            [
                'company_name' => 'Demo Distribution',
                'contact_person' => 'Partner Sales',
                'email' => 'sales@example-supplier.test',
                'phone' => '+359 2 000 0000',
                'website' => 'https://example-supplier.test',
                'notes' => 'Demo supplier used to shape XML and CSV import mapping.',
                'priority' => 10,
                'sync_strategy' => 'lowest_price',
                'status' => 'active',
            ],
        );

        $launchSuppliers = [
            ['ASBIS', 'asbis', 10, '06:00', '19:00'],
            ['ALSO', 'also', 20, '06:20', '19:20'],
            ['PolyComp', 'polycomp', 30, '06:40', '19:40'],
            ['APCOM', 'apcom', 40, '07:00', '20:00'],
            ['Most', 'most', 50, '07:20', '20:20'],
            ['Decada', 'decada', 60, '07:40', '20:40'],
        ];

        foreach ($launchSuppliers as [$name, $slug, $priority, $morning, $evening]) {
            $supplier = Supplier::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'company_name' => $name,
                    'priority' => $priority,
                    'sync_strategy' => $priority <= 30 ? 'preferred_supplier' : 'lowest_price',
                    'import_enabled' => true,
                    'schedule_enabled' => true,
                    'schedule_type' => 'twice_daily',
                    'morning_import_time' => $morning,
                    'evening_import_time' => $evening,
                    'timezone' => 'Europe/Sofia',
                    'stagger_minutes' => 20,
                    'maximum_product_drop_percent' => 40,
                    'minimum_product_count' => 1,
                    'allow_destructive_sync' => false,
                    'status' => 'active',
                ],
            );

            $supplier->update([
                'next_import_at' => app(SupplierImportScheduleService::class)->nextRunAt($supplier),
            ]);
        }
    }
}
