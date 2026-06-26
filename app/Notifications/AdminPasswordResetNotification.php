<?php

namespace App\Notifications;

use Filament\Auth\Notifications\ResetPassword as FilamentResetPasswordNotification;
use Illuminate\Notifications\Messages\MailMessage;

class AdminPasswordResetNotification extends FilamentResetPasswordNotification
{
    public function toMail($notifiable): MailMessage
    {
        $url = $this->url ?? $this->resetUrl($notifiable);
        $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Смяна на парола за COMPUTER2U')
            ->markdown('emails.admin-password-reset', [
                'expire' => $expire,
                'url' => $url,
            ]);
    }
}
