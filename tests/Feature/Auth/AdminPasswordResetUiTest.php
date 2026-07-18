<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\RequestAdminPasswordReset;
use App\Filament\Pages\Auth\ResetAdminPassword;
use App\Models\User;
use App\Notifications\AdminPasswordResetNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPasswordResetUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_request_page_renders_bulgarian_admin_password_reset_copy(): void
    {
        $this->get(route('filament.admin.auth.password-reset.request'))
            ->assertOk()
            ->assertSee('Възстановяване на парола')
            ->assertSee('Въведете служебния си имейл адрес и ще ви изпратим линк за задаване на нова парола.')
            ->assertSee('Имейл адрес')
            ->assertSee('Изпрати линк за възстановяване')
            ->assertSee('Назад към вход');
    }

    public function test_reset_page_renders_bulgarian_admin_password_reset_copy(): void
    {
        $admin = $this->admin(User::ROLE_PRODUCT_EDITOR, 'reset-page@example.test');
        $token = Password::createToken($admin);
        $resetUrl = filament()->getPanel('admin')->getResetPasswordUrl($token, $admin);

        $this->get($resetUrl)
            ->assertOk()
            ->assertSee('Задаване на нова парола')
            ->assertSee('Нова парола')
            ->assertSee('Потвърдете новата парола')
            ->assertSee('Запази новата парола')
            ->assertSee('Назад към вход');
    }

    public function test_active_super_admin_receives_a_filament_reset_link_and_neutral_ui_response(): void
    {
        Notification::fake();

        $admin = $this->admin(User::ROLE_SUPER_ADMIN, 'super-admin-reset@example.test');

        Livewire::test(RequestAdminPasswordReset::class)
            ->fillForm(['email' => $admin->email])
            ->call('request')
            ->assertNotified($this->neutralRequestNotification());

        Notification::assertSentTo($admin, AdminPasswordResetNotification::class, function (AdminPasswordResetNotification $notification) use ($admin): bool {
            $query = [];
            parse_str((string) parse_url((string) $notification->url, PHP_URL_QUERY), $query);

            $this->assertSame(
                'filament.admin.auth.password-reset.reset',
                app('router')->getRoutes()->match(request()->create((string) $notification->url))->getName(),
            );
            $this->assertSame($admin->email, $query['email'] ?? null);
            $this->assertArrayHasKey('token', $query);

            return true;
        });
    }

    public function test_active_staff_user_receives_same_neutral_ui_response_and_reset_notification(): void
    {
        Notification::fake();

        $staff = $this->admin(User::ROLE_PRODUCT_EDITOR, 'staff-reset@example.test');

        Livewire::test(RequestAdminPasswordReset::class)
            ->fillForm(['email' => $staff->email])
            ->call('request')
            ->assertNotified($this->neutralRequestNotification());

        Notification::assertSentTo($staff, AdminPasswordResetNotification::class);
    }

    public function test_unknown_email_receives_neutral_ui_response_without_notification(): void
    {
        Notification::fake();

        Livewire::test(RequestAdminPasswordReset::class)
            ->fillForm(['email' => 'unknown-admin-reset@example.test'])
            ->call('request')
            ->assertNotified($this->neutralRequestNotification());

        Notification::assertNothingSent();
    }

    public function test_inactive_admin_receives_neutral_ui_response_without_notification(): void
    {
        Notification::fake();

        $inactive = $this->admin(User::ROLE_PRODUCT_EDITOR, 'inactive-admin-reset@example.test', ['is_active' => false]);

        Livewire::test(RequestAdminPasswordReset::class)
            ->fillForm(['email' => $inactive->email])
            ->call('request')
            ->assertNotified($this->neutralRequestNotification());

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $inactive->email]);
    }

    public function test_soft_deleted_admin_receives_neutral_ui_response_without_notification(): void
    {
        Notification::fake();

        $deleted = $this->admin(User::ROLE_PRODUCT_EDITOR, 'deleted-admin-reset@example.test');
        $deleted->delete();

        Livewire::test(RequestAdminPasswordReset::class)
            ->fillForm(['email' => $deleted->email])
            ->call('request')
            ->assertNotified($this->neutralRequestNotification());

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $deleted->email]);
    }

    public function test_non_admin_user_receives_neutral_ui_response_without_notification(): void
    {
        Notification::fake();

        $customer = User::factory()->create([
            'email' => 'non-admin-reset@example.test',
            'is_active' => true,
            'role' => null,
        ]);

        Livewire::test(RequestAdminPasswordReset::class)
            ->fillForm(['email' => $customer->email])
            ->call('request')
            ->assertNotified($this->neutralRequestNotification());

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $customer->email]);
    }

    public function test_invalid_email_uses_bulgarian_validation_message(): void
    {
        Livewire::test(RequestAdminPasswordReset::class)
            ->fillForm(['email' => 'not-an-email'])
            ->call('request')
            ->assertHasFormErrors(['email'])
            ->assertSee('Въведете валиден имейл адрес.');
    }

    public function test_required_admin_password_reset_fields_use_bulgarian_validation_messages(): void
    {
        Livewire::test(RequestAdminPasswordReset::class)
            ->fillForm(['email' => null])
            ->call('request')
            ->assertHasFormErrors(['email'])
            ->assertSee('Полето е задължително.');

        $admin = $this->admin(User::ROLE_PRODUCT_EDITOR, 'required-reset@example.test');
        $token = Password::createToken($admin);

        Livewire::test(ResetAdminPassword::class, [
            'email' => $admin->email,
            'token' => $token,
        ])
            ->fillForm([
                'password' => '',
                'passwordConfirmation' => '',
            ])
            ->call('resetPassword')
            ->assertHasFormErrors(['password', 'passwordConfirmation'])
            ->assertSee('Полето е задължително.');
    }

    #[DataProvider('invalidStaffPasswords')]
    public function test_reset_requires_a_strong_confirmed_staff_password_with_bulgarian_messages(string $password, string $confirmation, string $message): void
    {
        $admin = $this->admin(User::ROLE_PRODUCT_EDITOR, 'validation-reset@example.test', [
            'password' => Hash::make('Oldpass1!23'),
        ]);

        $token = Password::createToken($admin);

        Livewire::test(ResetAdminPassword::class, [
            'email' => $admin->email,
            'token' => $token,
        ])
            ->fillForm([
                'password' => $password,
                'passwordConfirmation' => $confirmation,
            ])
            ->call('resetPassword')
            ->assertHasFormErrors(['password'])
            ->assertSee($message);

        $this->assertTrue(Hash::check('Oldpass1!23', $admin->refresh()->password));
    }

    public function test_valid_reset_shows_bulgarian_success_message_redirects_to_admin_login_and_preserves_account_state(): void
    {
        $admin = $this->admin(User::ROLE_SUPER_ADMIN, 'successful-reset@example.test', [
            'password' => Hash::make('Oldpass1!23'),
        ]);
        $token = Password::createToken($admin);

        Livewire::test(ResetAdminPassword::class, [
            'email' => $admin->email,
            'token' => $token,
        ])
            ->fillForm([
                'password' => 'Newpass1!23',
                'passwordConfirmation' => 'Newpass1!23',
            ])
            ->call('resetPassword')
            ->assertNotified(
                FilamentNotification::make()
                    ->title('Паролата е променена')
                    ->body('Паролата ви беше променена успешно. Можете да влезете с новата парола.')
                    ->success(),
            )
            ->assertRedirect(route('filament.admin.auth.login'));

        $admin->refresh();

        $this->assertTrue(Hash::check('Newpass1!23', $admin->password));
        $this->assertFalse(Hash::check('Oldpass1!23', $admin->password));
        $this->assertSame(User::ROLE_SUPER_ADMIN, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertFalse($admin->trashed());
    }

    public function test_invalid_and_expired_tokens_show_bulgarian_error_without_changing_the_password(): void
    {
        $admin = $this->admin(User::ROLE_PRODUCT_EDITOR, 'invalid-token-reset@example.test', [
            'password' => Hash::make('Oldpass1!23'),
        ]);

        foreach (['invalid-token', $this->expiredTokenFor($admin)] as $token) {
            Livewire::test(ResetAdminPassword::class, [
                'email' => $admin->email,
                'token' => $token,
            ])
                ->fillForm([
                    'password' => 'Newpass1!23',
                    'passwordConfirmation' => 'Newpass1!23',
                ])
                ->call('resetPassword')
                ->assertNotified(
                    $this->invalidLinkNotification(),
                );

            $this->assertTrue(Hash::check('Oldpass1!23', $admin->refresh()->password));
        }
    }

    public function test_non_admin_user_cannot_reset_password_even_with_a_manually_created_token(): void
    {
        $customer = User::factory()->create([
            'email' => 'non-admin-token-reset@example.test',
            'is_active' => true,
            'role' => null,
            'password' => Hash::make('Oldpass1!23'),
        ]);
        $token = Password::createToken($customer);

        Livewire::test(ResetAdminPassword::class, [
            'email' => $customer->email,
            'token' => $token,
        ])
            ->fillForm([
                'password' => 'Newpass1!23',
                'passwordConfirmation' => 'Newpass1!23',
            ])
            ->call('resetPassword')
            ->assertNotified($this->invalidLinkNotification());

        $this->assertTrue(Hash::check('Oldpass1!23', $customer->refresh()->password));
    }

    private function neutralRequestNotification(): FilamentNotification
    {
        return FilamentNotification::make()
            ->title('Проверете имейла си')
            ->body('Ако съществува активен администраторски акаунт с този имейл, ще получите линк за възстановяване на паролата.')
            ->success();
    }

    private function invalidLinkNotification(): FilamentNotification
    {
        return FilamentNotification::make()
            ->title('Невалиден или изтекъл линк')
            ->body('Линкът за възстановяване е невалиден или е изтекъл. Заявете нов линк.')
            ->actions([
                Action::make('requestNewPasswordResetLink')
                    ->label('Заявете нов линк')
                    ->url(filament()->getRequestPasswordResetUrl()),
            ])
            ->danger();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidStaffPasswords(): array
    {
        return [
            'minimum length' => ['Short1!', 'Short1!', 'Паролата трябва да съдържа поне 10 знака.'],
            'uppercase' => ['newpassword1!', 'newpassword1!', 'Паролата трябва да съдържа поне една главна буква.'],
            'lowercase' => ['NEWPASSWORD1!', 'NEWPASSWORD1!', 'Паролата трябва да съдържа поне една малка буква.'],
            'number' => ['Newpassword!', 'Newpassword!', 'Паролата трябва да съдържа поне една цифра.'],
            'symbol' => ['Newpassword1', 'Newpassword1', 'Паролата трябва да съдържа поне един специален символ.'],
            'confirmation' => ['Newpassword1!', 'Differentpass1!', 'Потвърждението на паролата не съвпада.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function admin(string $role, string $email, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => $email,
            'role' => $role,
            'is_active' => true,
        ], $attributes));
        $user->assignRole($role);

        return $user;
    }

    private function expiredTokenFor(User $user): string
    {
        $token = Password::createToken($user);

        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        return $token;
    }
}
