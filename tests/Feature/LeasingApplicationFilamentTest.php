<?php

namespace Tests\Feature;

use App\Filament\Resources\LeasingApplications\LeasingApplicationResource;
use App\Filament\Resources\LeasingApplications\Pages\ListLeasingApplications;
use App\Filament\Resources\LeasingApplications\Pages\ViewLeasingApplication;
use App\Models\LeasingApplication;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class LeasingApplicationFilamentTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_super_admin_can_list_and_view_without_create_edit_or_delete_actions(): void
    {
        $application = $this->application('leasing-filament-super');
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $this->actingAs($superAdmin);

        $this->assertTrue(LeasingApplicationResource::canViewAny());
        $this->assertTrue(LeasingApplicationResource::shouldRegisterNavigation());
        $this->assertFalse(LeasingApplicationResource::canCreate());
        $this->assertFalse(LeasingApplicationResource::canEdit($application));
        $this->assertFalse(LeasingApplicationResource::canDelete($application));
        $this->assertFalse(LeasingApplicationResource::canDeleteAny());

        Livewire::test(ListLeasingApplications::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$application])
            ->assertTableActionExists('view', null, $application)
            ->assertTableActionDoesNotExist('delete', null, $application);

        Livewire::test(ViewLeasingApplication::class, ['record' => $application->getRouteKey()])
            ->assertOk()
            ->assertSee($application->reference)
            ->assertSee('Вътрешна обработка')
            ->assertActionVisible('assign')
            ->assertActionVisible('changeStatus')
            ->assertActionVisible('addNote')
            ->assertActionDoesNotExist('delete');
    }

    public function test_viewer_auditor_can_view_but_cannot_mutate_application(): void
    {
        $application = $this->application('leasing-filament-viewer');
        $viewer = User::factory()->create([
            'role' => User::ROLE_VIEWER_AUDITOR,
            'is_active' => true,
        ]);
        $viewer->assignRole(User::ROLE_VIEWER_AUDITOR);
        $this->actingAs($viewer);

        $this->assertTrue(LeasingApplicationResource::canViewAny());
        $this->assertTrue(LeasingApplicationResource::canView($application));
        $this->assertFalse($viewer->can('update', $application));

        Livewire::test(ViewLeasingApplication::class, ['record' => $application->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('assign')
            ->assertActionHidden('changeStatus')
            ->assertActionHidden('addNote');
    }

    public function test_user_without_order_permissions_cannot_access_resource(): void
    {
        $application = $this->application('leasing-filament-no-access');
        $user = User::factory()->create([
            'role' => User::ROLE_PRODUCT_DATA_ENTRY,
            'is_active' => true,
        ]);
        $user->assignRole(User::ROLE_PRODUCT_DATA_ENTRY);
        $this->actingAs($user);

        $this->assertFalse(LeasingApplicationResource::canViewAny());
        $this->get(LeasingApplicationResource::getUrl('view', ['record' => $application]))
            ->assertForbidden();
    }

    private function application(string $name): LeasingApplication
    {
        config()->set('payments.methods.leasing.enabled', true);
        $cart = $this->prepareCheckoutCart($name);
        PaymentMethod::query()->where('code', 'leasing')->update(['status' => 'active']);
        $this->submitCheckout($cart, $name, [
            'payment_method' => 'leasing',
            'leasing_application' => [
                'term_months' => 18,
                'down_payment' => '0.00',
                'contact_method' => 'either',
                'contact_time' => 'anytime',
                'note' => 'Тестова бележка.',
                'consent' => true,
            ],
        ])->assertCreated();

        return LeasingApplication::query()->sole();
    }
}
