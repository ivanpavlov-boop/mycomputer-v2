<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'first_name' => 'MYCOMPUTER',
            'last_name' => 'Admin',
            'name' => 'MYCOMPUTER Admin',
            'email' => 'admin@mycomputer.bg',
            'password' => Hash::make('password'),
        ]);

        $this->call([
            CategorySeeder::class,
            BrandSeeder::class,
            AttributeGroupSeeder::class,
            CanonicalAttributeSeeder::class,
            SupplierSeeder::class,
            AvailabilityStatusSeeder::class,
            XmlMappingTemplateSeeder::class,
            ShippingSeeder::class,
            PaymentSeeder::class,
            RolesAndPermissionsSeeder::class,
            SupplierFeedSeeder::class,
            ApcomSupplierIntegrationSeeder::class,
            ProductSeeder::class,
            ContentSeeder::class,
            PcBuilderSeeder::class,
            EmailAutomationSeeder::class,
        ]);
    }
}
