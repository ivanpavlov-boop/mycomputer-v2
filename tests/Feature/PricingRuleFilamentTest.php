<?php

namespace Tests\Feature;

use App\Filament\Resources\PricingRules\Pages\CreatePricingRule;
use App\Filament\Resources\PricingRules\Pages\ListPricingRules;
use App\Filament\Resources\PricingRules\PricingRuleResource;
use App\Models\PricingRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PricingRuleFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_create_redirects_to_pricing_rules_list(): void
    {
        $this->actingAsPricingManager();

        Livewire::test(CreatePricingRule::class)
            ->fillForm($this->pricingRuleFormData('Global default margin'))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(PricingRuleResource::getUrl('index'));

        $this->assertDatabaseHas('pricing_rules', [
            'name' => 'Global default margin',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
        ]);
    }

    public function test_create_and_add_another_stays_on_create_page(): void
    {
        $this->actingAsPricingManager();

        Livewire::test(CreatePricingRule::class)
            ->fillForm($this->pricingRuleFormData('Create another margin'))
            ->call('createAnother')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();

        $this->assertDatabaseHas('pricing_rules', [
            'name' => 'Create another margin',
            'scope_type' => PricingRule::SCOPE_GLOBAL,
        ]);
    }

    public function test_pricing_rules_table_has_edit_delete_bulk_delete_and_formatted_margins(): void
    {
        $this->actingAsPricingManager();

        $percentageRule = PricingRule::query()->create($this->pricingRuleFormData('Percentage margin'));
        $fixedRule = PricingRule::query()->create(array_merge(
            $this->pricingRuleFormData('Fixed margin'),
            [
                'margin_type' => PricingRule::MARGIN_FIXED,
                'margin_value' => 15,
            ],
        ));

        Livewire::test(ListPricingRules::class)
            ->assertTableActionExists('edit', null, $percentageRule)
            ->assertTableActionExists('delete', null, $percentageRule)
            ->assertTableBulkActionExists('delete')
            ->assertTableColumnFormattedStateSet('margin_value', '20%', $percentageRule)
            ->assertTableColumnFormattedStateSet('margin_value', '15 EUR', $fixedRule);
    }

    public function test_pricing_rule_can_be_deleted_from_table_row_action(): void
    {
        $this->actingAsPricingManager();

        $rule = PricingRule::query()->create($this->pricingRuleFormData('Delete from table'));

        Livewire::test(ListPricingRules::class)
            ->callTableAction('delete', $rule)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('pricing_rules', [
            'id' => $rule->id,
        ]);
    }

    private function actingAsPricingManager(): User
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
    private function pricingRuleFormData(string $name): array
    {
        return [
            'name' => $name,
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'margin_type' => PricingRule::MARGIN_PERCENTAGE,
            'margin_value' => 20,
            'rounding_rule' => PricingRule::ROUND_NONE,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
