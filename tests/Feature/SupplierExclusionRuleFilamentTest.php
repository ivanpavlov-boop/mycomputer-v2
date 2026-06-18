<?php

namespace Tests\Feature;

use App\Filament\Resources\SupplierExclusionRules\Pages\CreateSupplierExclusionRule;
use App\Filament\Resources\SupplierExclusionRules\Pages\EditSupplierExclusionRule;
use App\Filament\Resources\SupplierExclusionRules\Pages\ListSupplierExclusionRules;
use App\Filament\Resources\SupplierExclusionRules\SupplierExclusionRuleResource;
use App\Models\SupplierExclusionRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierExclusionRuleFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_create_redirects_to_supplier_exclusion_rules_list(): void
    {
        $this->actingAsSupplierManager();

        Livewire::test(CreateSupplierExclusionRule::class)
            ->fillForm($this->ruleFormData('Exclude missing EAN'))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(SupplierExclusionRuleResource::getUrl('index'));

        $this->assertDatabaseHas('supplier_exclusion_rules', [
            'name' => 'Exclude missing EAN',
            'exclude_missing_ean' => true,
        ]);
    }

    public function test_create_and_add_another_stays_on_create_page(): void
    {
        $this->actingAsSupplierManager();

        Livewire::test(CreateSupplierExclusionRule::class)
            ->fillForm($this->ruleFormData('Exclude zero stock'))
            ->call('createAnother')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();

        $this->assertDatabaseHas('supplier_exclusion_rules', [
            'name' => 'Exclude zero stock',
            'exclude_zero_stock' => true,
        ]);
    }

    public function test_supplier_exclusion_rules_table_has_edit_delete_and_bulk_delete_actions(): void
    {
        $this->actingAsSupplierManager();

        $rule = SupplierExclusionRule::query()->create($this->ruleFormData('Table actions'));

        Livewire::test(ListSupplierExclusionRules::class)
            ->assertTableActionExists('edit', null, $rule)
            ->assertTableActionExists('delete', null, $rule)
            ->assertTableBulkActionExists('delete');
    }

    public function test_supplier_exclusion_rules_table_shows_active_status_after_name(): void
    {
        $this->actingAsSupplierManager();

        $columnNames = array_keys(Livewire::test(ListSupplierExclusionRules::class)->instance()->getTable()->getColumns());

        $this->assertSame(['name', 'is_active', 'supplier.company_name'], array_slice($columnNames, 0, 3));
    }

    public function test_supplier_exclusion_rule_can_be_deleted_from_table_row_action(): void
    {
        $this->actingAsSupplierManager();

        $rule = SupplierExclusionRule::query()->create($this->ruleFormData('Delete row action'));

        Livewire::test(ListSupplierExclusionRules::class)
            ->callTableAction('delete', $rule)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('supplier_exclusion_rules', [
            'id' => $rule->id,
        ]);
    }

    public function test_supplier_exclusion_rules_can_be_bulk_deleted(): void
    {
        $this->actingAsSupplierManager();

        $rules = collect([
            SupplierExclusionRule::query()->create($this->ruleFormData('Bulk delete one')),
            SupplierExclusionRule::query()->create($this->ruleFormData('Bulk delete two')),
        ]);

        Livewire::test(ListSupplierExclusionRules::class)
            ->callTableBulkAction('delete', $rules)
            ->assertHasNoTableBulkActionErrors();

        $this->assertDatabaseMissing('supplier_exclusion_rules', [
            'name' => 'Bulk delete one',
        ]);
        $this->assertDatabaseMissing('supplier_exclusion_rules', [
            'name' => 'Bulk delete two',
        ]);
    }

    public function test_editing_supplier_exclusion_rule_redirects_to_list_after_updating_record(): void
    {
        $this->actingAsSupplierManager();

        $rule = SupplierExclusionRule::query()->create($this->ruleFormData('Original exclusion'));

        Livewire::test(EditSupplierExclusionRule::class, ['record' => $rule->getKey()])
            ->fillForm(array_merge($this->ruleFormData('Updated exclusion'), [
                'priority' => 5,
                'exclude_zero_stock' => false,
            ]))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(SupplierExclusionRuleResource::getUrl('index'));

        $this->assertDatabaseHas('supplier_exclusion_rules', [
            'id' => $rule->id,
            'name' => 'Updated exclusion',
            'priority' => 5,
            'exclude_zero_stock' => false,
        ]);
    }

    private function actingAsSupplierManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('manager');

        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleFormData(string $name): array
    {
        return [
            'name' => $name,
            'is_active' => true,
            'priority' => 100,
            'reason' => 'Admin UX test rule',
            'exclude_zero_stock' => true,
            'exclude_eol' => false,
            'exclude_missing_ean' => true,
        ];
    }
}
