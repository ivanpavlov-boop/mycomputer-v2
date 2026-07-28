<?php

namespace App\Listeners;

use App\Events\LeasingApplicationSubmitted;
use App\Models\LeasingApplication;
use App\Models\User;
use App\Notifications\CustomerLeasingApplicationSubmittedNotification;
use App\Notifications\InternalLeasingApplicationSubmittedNotification;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class SendLeasingApplicationNotifications implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(LeasingApplicationSubmitted $event): void
    {
        $application = LeasingApplication::query()
            ->with(['order.items'])
            ->findOrFail($event->leasingApplicationId);

        Notification::route('mail', $application->order->customer_email)
            ->notify(new CustomerLeasingApplicationSubmittedNotification($application));

        Notification::route(
            'mail',
            config('payments.methods.leasing.notification_email', 'sales@mycomputer.bg'),
        )->notify(new InternalLeasingApplicationSubmittedNotification($application));

        User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $user->isActiveAdminAccount()
                && ($user->isSuperAdmin() || $user->can('manage orders')))
            ->each(function (User $user) use ($application): void {
                FilamentNotification::make()
                    ->title('Нова заявка за покупка на изплащане')
                    ->body("Поръчка {$application->order->order_number} · {$application->reference}")
                    ->icon('heroicon-o-document-text')
                    ->warning()
                    ->sendToDatabase($user);
            });
    }
}
