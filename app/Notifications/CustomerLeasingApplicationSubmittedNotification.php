<?php

namespace App\Notifications;

use App\Models\LeasingApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerLeasingApplicationSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(public LeasingApplication $application) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Получихме заявката Ви за покупка на изплащане')
            ->markdown('emails.leasing.customer-submitted', [
                'application' => $this->application,
                'order' => $this->application->order,
            ]);
    }
}
