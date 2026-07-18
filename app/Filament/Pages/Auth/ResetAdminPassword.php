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
use Filament\Schemas\Components\Component;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ResetAdminPassword extends ResetPassword
{
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
        return __('admin-password-reset.reset.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('admin-password-reset.reset.heading');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return filament()->hasLogin() ? $this->loginAction : null;
    }

    public function getResetPasswordFormAction(): Action
    {
        return parent::getResetPasswordFormAction()
            ->label(__('admin-password-reset.reset.submit'));
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
