<?php

namespace App\Notifications;

use App\Models\LeasingApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InternalLeasingApplicationSubmittedNotification extends Notification
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
            ->subject("Нова заявка за покупка на изплащане — поръчка {$this->application->order->order_number}")
            ->markdown('emails.leasing.internal-submitted', [
                'application' => $this->application,
                'order' => $this->application->order,
            ]);
    }
}
