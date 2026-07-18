<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Password;

class RequestAdminPasswordReset extends RequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();
        $user = User::query()->where('email', $data['email'])->first();

        if ($user?->isActiveAdminAccount() && $user->canAccessPanel(filament()->getCurrentOrDefaultPanel())) {
            Password::broker(filament()->getAuthPasswordBroker())
                ->sendResetLink($this->getCredentialsFromFormData($data));
        }

        $this->getSentNotification(Password::RESET_LINK_SENT)?->send();
        $this->form->fill();
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

    protected function getSentNotification(string $status): ?Notification
    {
        return Notification::make()
            ->title(__('admin-password-reset.notifications.request_sent.title'))
            ->body(__('admin-password-reset.notifications.request_sent.body'))
            ->success();
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('admin-password-reset.request.email'))
            ->email()
            ->required()
            ->autocomplete()
            ->autofocus()
            ->validationMessages([
                'email' => __('admin-password-reset.validation.email.email'),
                'required' => __('admin-password-reset.validation.email.required'),
            ]);
    }

    public function loginAction(): Action
    {
        return parent::loginAction()
            ->label(__('admin-password-reset.request.back_to_login'));
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin-password-reset.request.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('admin-password-reset.request.heading');
    }

    protected function getRequestFormAction(): Action
    {
        return parent::getRequestFormAction()
            ->label(__('admin-password-reset.request.submit'));
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE),
                Text::make(__('admin-password-reset.request.description'))
                    ->color('gray')
                    ->size('sm'),
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('request')
                    ->footer([
                        Actions::make($this->getFormActions())
                            ->alignment($this->getFormActionsAlignment())
                            ->fullWidth($this->hasFullWidthFormActions())
                            ->key('form-actions'),
                    ]),
                RenderHook::make(PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER),
            ]);
    }
}
