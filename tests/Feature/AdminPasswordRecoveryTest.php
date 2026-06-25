<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Illuminate\Auth\Notifications\ResetPassword as LaravelResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_login_exposes_password_reset_request_page(): void
    {
        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSee(route('filament.admin.auth.password-reset.request'), false);

        $this->get(route('filament.admin.auth.password-reset.request'))
            ->assertOk();
    }

    public function test_active_admin_can_request_self_service_password_reset_link(): void
    {
        Notification::fake();

        $admin = $this->adminUser(User::ROLE_PRODUCT_EDITOR, [
            'email' => 'active-admin-reset@example.test',
        ]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $admin->email])
            ->call('request');

        Notification::assertSentTo($admin, FilamentResetPasswordNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $admin->email]);
    }

    public function test_inactive_admin_does_not_receive_or_use_password_reset_link(): void
    {
        Notification::fake();

        $admin = $this->adminUser(User::ROLE_PRODUCT_EDITOR, [
            'email' => 'inactive-admin-reset@example.test',
            'is_active' => false,
            'password' => Hash::make('Password1'),
        ]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $admin->email])
            ->call('request');

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $admin->email]);

        $token = Password::createToken($admin);

        Livewire::test(ResetPassword::class, [
            'email' => $admin->email,
            'token' => $token,
        ])
            ->fillForm([
                'password' => 'Newpass1',
                'passwordConfirmation' => 'Newpass1',
            ])
            ->call('resetPassword');

        $this->assertTrue(Hash::check('Password1', $admin->refresh()->password));
    }

    public function test_deleted_admin_does_not_receive_or_use_password_reset_link(): void
    {
        Notification::fake();

        $admin = $this->adminUser(User::ROLE_PRODUCT_EDITOR, [
            'email' => 'deleted-admin-reset@example.test',
            'password' => Hash::make('Password1'),
        ]);
        $token = Password::createToken($admin);
        $admin->delete();
        $admin = User::withTrashed()->findOrFail($admin->id);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $admin->email])
            ->call('request');

        Notification::assertNothingSent();

        Livewire::test(ResetPassword::class, [
            'email' => $admin->email,
            'token' => $token,
        ])
            ->fillForm([
                'password' => 'Newpass1',
                'passwordConfirmation' => 'Newpass1',
            ])
            ->call('resetPassword');

        $this->assertTrue(Hash::check('Password1', $admin->refresh()->password));
    }

    public function test_active_admin_can_complete_self_service_password_reset(): void
    {
        $admin = $this->adminUser(User::ROLE_PRODUCT_EDITOR, [
            'email' => 'complete-admin-reset@example.test',
            'password' => Hash::make('Password1'),
        ]);
        $token = Password::createToken($admin);

        Livewire::test(ResetPassword::class, [
            'email' => $admin->email,
            'token' => $token,
        ])
            ->fillForm([
                'password' => 'Newpass1',
                'passwordConfirmation' => 'Newpass1',
            ])
            ->call('resetPassword');

        $this->assertTrue(Hash::check('Newpass1', $admin->refresh()->password));
    }

    public function test_super_admin_can_send_reset_link_to_active_user_from_user_management(): void
    {
        Notification::fake();

        $superAdmin = $this->superAdmin();
        $target = $this->adminUser(User::ROLE_PRODUCT_DATA_ENTRY, [
            'email' => 'managed-user-reset@example.test',
        ]);

        $this->actingAs($superAdmin);

        Livewire::test(ListUsers::class)
            ->assertTableActionExists('sendPasswordResetLink', null, $target)
            ->assertTableActionDoesNotExist('resetPassword', null, $target)
            ->callTableAction('sendPasswordResetLink', $target)
            ->assertHasNoTableActionErrors();

        Notification::assertSentTo($target, LaravelResetPasswordNotification::class);
    }

    public function test_non_super_admin_and_inactive_users_cannot_send_admin_reset_links(): void
    {
        $catalogManager = $this->adminUser(User::ROLE_CATALOG_MANAGER);
        $target = $this->adminUser(User::ROLE_PRODUCT_EDITOR);
        $inactiveTarget = $this->adminUser(User::ROLE_PRODUCT_DATA_ENTRY, ['is_active' => false]);

        $this->actingAs($catalogManager);

        $this->assertFalse(UserResource::canSendPasswordResetLink($target));
        $this->assertFalse(UserResource::canSendPasswordResetLink($inactiveTarget));
    }

    public function test_api_password_reset_does_not_reset_inactive_or_deleted_users(): void
    {
        $inactive = User::factory()->create([
            'email' => 'inactive-api-reset@example.test',
            'is_active' => false,
            'password' => Hash::make('Password1'),
        ]);
        $deleted = User::factory()->create([
            'email' => 'deleted-api-reset@example.test',
            'is_active' => true,
            'password' => Hash::make('Password1'),
        ]);
        $inactiveToken = Password::createToken($inactive);
        $deletedToken = Password::createToken($deleted);
        $deleted->delete();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $inactive->email,
            'token' => $inactiveToken,
            'password' => 'Newpass1',
            'password_confirmation' => 'Newpass1',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $deleted->email,
            'token' => $deletedToken,
            'password' => 'Newpass1',
            'password_confirmation' => 'Newpass1',
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check('Password1', $inactive->refresh()->password));
        $this->assertTrue(Hash::check('Password1', User::withTrashed()->findOrFail($deleted->id)->password));
    }

    private function superAdmin(): User
    {
        return $this->adminUser(User::ROLE_SUPER_ADMIN);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function adminUser(string $role, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => $role,
            'is_active' => true,
        ], $overrides));
        $user->assignRole($role);

        return $user;
    }
}
