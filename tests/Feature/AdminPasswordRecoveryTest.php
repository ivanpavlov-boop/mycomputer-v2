<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\ResetAdminPassword as ResetPassword;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Notifications\AdminPasswordResetNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
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

        Notification::assertSentTo($admin, AdminPasswordResetNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $admin->email]);
    }

    public function test_admin_password_reset_email_is_bulgarian_and_branded(): void
    {
        Notification::fake();

        $admin = $this->adminUser(User::ROLE_PRODUCT_EDITOR, [
            'email' => 'bulgarian-admin-reset@example.test',
        ]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $admin->email])
            ->call('request');

        Notification::assertSentTo($admin, AdminPasswordResetNotification::class, function (AdminPasswordResetNotification $notification) use ($admin): bool {
            $mail = $notification->toMail($admin);
            $body = app(Markdown::class)->render($mail->markdown, $mail->viewData);

            $this->assertSame('Смяна на парола за COMPUTER2U', $mail->subject);
            $this->assertStringContainsString('Здравейте!', $body);
            $this->assertStringContainsString('администраторски акаунт в COMPUTER2U', $body);
            $this->assertStringContainsString('Смяна на парола', $body);
            $this->assertStringContainsString('Този линк за смяна на парола е валиден 60 минути.', $body);
            $this->assertStringContainsString('Ако не сте заявявали смяна на парола', $body);
            $this->assertStringContainsString('Екипът на COMPUTER2U', $body);
            $this->assertStringContainsString('/admin/password-reset/reset', $mail->viewData['url']);
            $this->assertStringContainsString('token=', $mail->viewData['url']);
            $this->assertStringContainsString('email='.urlencode($admin->email), $mail->viewData['url']);

            return true;
        });
    }

    public function test_password_broker_generates_filament_admin_reset_url_for_active_admin(): void
    {
        Notification::fake();

        $admin = $this->adminUser(User::ROLE_PRODUCT_EDITOR, [
            'email' => 'broker-admin-reset@example.test',
        ]);
        $resetUrl = null;

        $status = Password::broker('users')->sendResetLink(['email' => $admin->email]);

        $this->assertSame(Password::RESET_LINK_SENT, $status);
        Notification::assertSentTo($admin, AdminPasswordResetNotification::class, function (AdminPasswordResetNotification $notification) use (&$resetUrl, $admin): bool {
            $resetUrl = $notification->url;

            return str_starts_with((string) $resetUrl, config('app.url').'/admin/password-reset/reset')
                && str_contains((string) $resetUrl, 'token=')
                && str_contains((string) $resetUrl, 'email='.urlencode($admin->email));
        });

        $this->assertNotNull($resetUrl);
        $this->assertSame('filament.admin.auth.password-reset.reset', app('router')->getRoutes()->match(request()->create($resetUrl))->getName());
    }

    public function test_inactive_admin_does_not_receive_or_use_password_reset_link(): void
    {
        Notification::fake();

        $admin = $this->adminUser(User::ROLE_PRODUCT_EDITOR, [
            'email' => 'inactive-admin-reset@example.test',
            'password' => Hash::make('Password1'),
            'is_active' => false,
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
                'password' => 'Newpass1!23',
                'passwordConfirmation' => 'Newpass1!23',
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
                'password' => 'Newpass1!23',
                'passwordConfirmation' => 'Newpass1!23',
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
                'password' => 'Newpass1!23',
                'passwordConfirmation' => 'Newpass1!23',
            ])
            ->call('resetPassword');

        $this->assertTrue(Hash::check('Newpass1!23', $admin->refresh()->password));
    }

    public function test_admin_password_reset_enforces_staff_password_rules(): void
    {
        $admin = $this->adminUser(User::ROLE_PRODUCT_EDITOR, [
            'email' => 'strong-rules-reset@example.test',
            'password' => Hash::make('Password1'),
        ]);

        foreach ([
            'short' => ['Short1!', 'Short1!'],
            'missing uppercase' => ['newpass1!23', 'newpass1!23'],
            'missing lowercase' => ['NEWPASS1!23', 'NEWPASS1!23'],
            'missing number' => ['Newpassword!', 'Newpassword!'],
            'missing symbol' => ['Newpass1234', 'Newpass1234'],
            'mismatched confirmation' => ['Newpass1!23', 'Different1!'],
        ] as $case => [$password, $confirmation]) {
            $token = Password::createToken($admin);

            Livewire::test(ResetPassword::class, [
                'email' => $admin->email,
                'token' => $token,
            ])
                ->fillForm([
                    'password' => $password,
                    'passwordConfirmation' => $confirmation,
                ])
                ->call('resetPassword')
                ->assertHasFormErrors(['password']);

            $this->assertTrue(Hash::check('Password1', $admin->refresh()->password), "Password changed for case [{$case}].");
        }
    }

    public function test_super_admin_can_send_reset_link_to_active_user_from_user_management(): void
    {
        Notification::fake();

        $superAdmin = $this->superAdmin();
        $target = $this->adminUser(User::ROLE_PRODUCT_DATA_ENTRY, [
            'email' => 'managed-user-reset@example.test',
            'password' => Hash::make('Password1'),
        ]);
        $resetUrl = null;

        $this->actingAs($superAdmin);

        Livewire::test(ListUsers::class)
            ->assertTableActionExists('sendPasswordResetLink', null, $target)
            ->assertTableActionDoesNotExist('resetPassword', null, $target)
            ->callTableAction('sendPasswordResetLink', $target)
            ->assertHasNoTableActionErrors();

        Notification::assertSentTo($target, AdminPasswordResetNotification::class, function (AdminPasswordResetNotification $notification) use (&$resetUrl): bool {
            $resetUrl = $notification->url;

            return str_contains((string) $resetUrl, '/admin/password-reset/reset')
                && str_contains((string) $resetUrl, 'token=')
                && str_contains((string) $resetUrl, 'email=');
        });

        $this->assertNotNull($resetUrl);
        auth()->guard('web')->logout();
        $this->flushSession();

        $this->get($resetUrl)->assertOk();

        $query = [];
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);

        Livewire::test(ResetPassword::class, [
            'email' => $query['email'] ?? null,
            'token' => $query['token'] ?? null,
        ])
            ->fillForm([
                'password' => 'Newpass1!23',
                'passwordConfirmation' => 'Newpass1!23',
            ])
            ->call('resetPassword');

        $target->refresh();

        $this->assertTrue(Hash::check('Newpass1!23', $target->password));
        $this->assertFalse(Hash::check('Password1', $target->password));
        $this->assertSame(User::ROLE_PRODUCT_DATA_ENTRY, $target->role);
    }

    public function test_staff_user_resource_creation_and_edit_use_strong_admin_password_rules(): void
    {
        $superAdmin = $this->superAdmin();
        $target = $this->adminUser(User::ROLE_PRODUCT_DATA_ENTRY, [
            'email' => 'edit-staff-password@example.test',
            'password' => Hash::make('Password1'),
        ]);

        $this->actingAs($superAdmin);

        Livewire::test(CreateUser::class)
            ->fillForm($this->userFormData([
                'email' => 'weak-created-staff@example.test',
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ]))
            ->call('create')
            ->assertHasFormErrors(['password']);

        Livewire::test(CreateUser::class)
            ->fillForm($this->userFormData([
                'email' => 'strong-created-staff@example.test',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', ['email' => 'strong-created-staff@example.test']);

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm($this->userFormData([
                'email' => $target->email,
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ]))
            ->call('save')
            ->assertHasFormErrors(['password']);

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm($this->userFormData([
                'email' => $target->email,
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('Password123!', $target->refresh()->password));
    }

    public function test_existing_super_admin_is_not_forced_to_change_current_password(): void
    {
        $superAdmin = $this->adminUser(User::ROLE_SUPER_ADMIN, [
            'email' => 'existing-super-admin@example.test',
            'password' => Hash::make('Password1'),
        ]);

        $this->actingAs($superAdmin);

        $this->assertTrue(Hash::check('Password1', $superAdmin->password));
        $this->assertTrue($superAdmin->canAccessPanel(filament()->getPanel('admin')));
        $this->get(route('filament.admin.pages.dashboard'))->assertOk();
        $this->get(UserResource::getUrl('index'))->assertOk();
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userFormData(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Staff',
            'last_name' => 'User',
            'name' => 'Staff User',
            'email' => 'staff-user@example.test',
            'phone' => null,
            'company_name' => null,
            'vat_number' => null,
            'is_active' => true,
            'role' => User::ROLE_PRODUCT_DATA_ENTRY,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], $overrides);
    }
}
