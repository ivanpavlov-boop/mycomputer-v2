<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Rules\AdminStaffPassword;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class ResetAdminPassword extends ResetPassword
{
    #[Locked]
    public bool $hasValidResetLink = false;

    public function mount(?string $email = null, #[\SensitiveParameter] ?string $token = null): void
    {
        parent::mount($email, $token);

        $this->hasValidResetLink = $this->hasValidResetLink();
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        Validator::make(
            [
                'password' => $this->password,
                'passwordConfirmation' => $this->passwordConfirmation,
            ],
            [
                'password' => ['required', new AdminStaffPassword, 'same:passwordConfirmation'],
                'passwordConfirmation' => ['required'],
            ],
            [
                'password.required' => __('admin-password-reset.validation.password.required'),
                'password.same' => __('admin-password-reset.validation.password.same'),
                'passwordConfirmation.required' => __('admin-password-reset.validation.password_confirmation.required'),
            ],
        )->validate();

        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        if ($this->isResetPasswordRateLimited($this->email)) {
            return null;
        }

        $data = $this->form->getState();
        $data['email'] = $this->email;
        $data['token'] = $this->token;
        $hasPanelAccess = true;

        $status = Password::broker(filament()->getAuthPasswordBroker())->reset(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword|Model|Authenticatable $user) use ($data, &$hasPanelAccess): void {
                if (! $user instanceof User || ! $user->isActiveAdminAccount() || ! $user->canAccessPanel(filament()->getCurrentOrDefaultPanel())) {
                    $hasPanelAccess = false;

                    return;
                }

                $user->forceFill([
                    $user->getAuthPasswordName() => Hash::make($data['password']),
                    $user->getRememberTokenName() => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if (! $hasPanelAccess) {
            $status = Password::INVALID_USER;
        }

        if ($status === Password::PASSWORD_RESET) {
            Notification::make()
                ->title(__('admin-password-reset.notifications.reset_succeeded.title'))
                ->body(__('admin-password-reset.notifications.reset_succeeded.body'))
                ->success()
                ->send();

            return app(PasswordResetResponse::class);
        }

        $this->getInvalidLinkNotification()->send();

        return null;
    }

    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        return Notification::make()
            ->title(__('admin-password-reset.notifications.throttled.title'))
            ->body(__('admin-password-reset.notifications.throttled.body', [
                'seconds' => $exception->secondsUntilAvailable,
            ]))
            ->danger();
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('admin-password-reset.request.email'))
            ->disabled()
            ->autofocus();
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('admin-password-reset.reset.password'))
            ->password()
            ->autocomplete('new-password')
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule(new AdminStaffPassword)
            ->same('passwordConfirmation')
            ->validationMessages([
                'required' => __('admin-password-reset.validation.password.required'),
                'same' => __('admin-password-reset.validation.password.same'),
            ]);
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('passwordConfirmation')
            ->label(__('admin-password-reset.reset.password_confirmation'))
            ->password()
            ->autocomplete('new-password')
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->dehydrated(false)
            ->validationMessages([
                'required' => __('admin-password-reset.validation.password_confirmation.required'),
            ]);
    }

    public function loginAction(): Action
    {
        return Action::make('login')
            ->link()
            ->label(__('admin-password-reset.reset.back_to_login'))
            ->url(filament()->getLoginUrl());
    }

    public function getTitle(): string|Htmlable
    {
        return $this->hasValidResetLink
            ? __('admin-password-reset.reset.title')
            : __('admin-password-reset.invalid_link.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->hasValidResetLink
            ? __('admin-password-reset.reset.heading')
            : __('admin-password-reset.invalid_link.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->hasValidResetLink && filament()->hasLogin() ? $this->loginAction : null;
    }

    public function getResetPasswordFormAction(): Action
    {
        return parent::getResetPasswordFormAction()
            ->label(__('admin-password-reset.reset.submit'));
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::AUTH_PASSWORD_RESET_RESET_FORM_BEFORE),
                $this->hasValidResetLink
                    ? $this->getFormContentComponent()
                    : $this->getInvalidLinkContentComponent(),
                RenderHook::make(PanelsRenderHook::AUTH_PASSWORD_RESET_RESET_FORM_AFTER),
            ]);
    }

    private function getInvalidLinkContentComponent(): Component
    {
        return Actions::make([
            Action::make('requestNewPasswordResetLink')
                ->label(__('admin-password-reset.invalid_link.request_action'))
                ->url(filament()->getRequestPasswordResetUrl())
                ->button()
                ->color('primary'),
            $this->loginAction(),
        ])
            ->aboveContent(
                Text::make(__('admin-password-reset.invalid_link.body'))
                    ->color('gray')
                    ->size('sm'),
            )
            ->fullWidth()
            ->key('invalid-reset-link-actions');
    }

    private function hasValidResetLink(): bool
    {
        if (blank($this->token) || ! filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $user = User::query()->where('email', $this->email)->first();

        if (! $user?->isActiveAdminAccount() || ! $user->canAccessPanel(filament()->getCurrentOrDefaultPanel())) {
            return false;
        }

        return Password::broker(filament()->getAuthPasswordBroker())->tokenExists($user, $this->token);
    }

    private function getInvalidLinkNotification(): Notification
    {
        return Notification::make()
            ->title(__('admin-password-reset.notifications.invalid_link.title'))
            ->body(__('admin-password-reset.notifications.invalid_link.body'))
            ->actions([
                Action::make('requestNewPasswordResetLink')
                    ->label(__('admin-password-reset.notifications.invalid_link.action'))
                    ->url(filament()->getRequestPasswordResetUrl()),
            ])
            ->danger();
    }
}
