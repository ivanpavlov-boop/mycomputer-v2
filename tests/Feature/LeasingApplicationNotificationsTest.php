<?php

namespace Tests\Feature;

use App\Events\LeasingApplicationSubmitted;
use App\Listeners\SendLeasingApplicationNotifications;
use App\Models\LeasingApplication;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Notifications\CustomerLeasingApplicationSubmittedNotification;
use App\Notifications\InternalLeasingApplicationSubmittedNotification;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsCheckoutFixtures;
use Tests\TestCase;

class LeasingApplicationNotificationsTest extends TestCase
{
    use BuildsCheckoutFixtures;
    use RefreshDatabase;

    public function test_checkout_dispatches_one_after_commit_notification_event_even_on_replay(): void
    {
        Event::fake([LeasingApplicationSubmitted::class]);
        $cart = $this->prepareLeasingCart('leasing-notification-replay');

        $this->submitLeasing($cart, 'leasing-notification-replay')->assertCreated();
        $this->submitLeasing($cart, 'leasing-notification-replay')
            ->assertCreated()
            ->assertJsonPath('data.idempotent_replay', true);

        Event::assertDispatchedTimes(LeasingApplicationSubmitted::class, 1);
        $event = new LeasingApplicationSubmitted(LeasingApplication::query()->sole()->getKey());
        $listener = new SendLeasingApplicationNotifications;
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertInstanceOf(ShouldQueue::class, $listener);
        $this->assertTrue($listener->afterCommit);
    }

    public function test_listener_sends_customer_internal_and_authorised_admin_notifications_only(): void
    {
        Notification::fake();
        Event::fake([LeasingApplicationSubmitted::class]);
        $cart = $this->prepareLeasingCart('leasing-notification-recipients');
        $manager = User::factory()->create([
            'role' => User::ROLE_ORDER_MANAGER,
            'is_active' => true,
        ]);
        $manager->assignRole(User::ROLE_ORDER_MANAGER);
        $viewer = User::factory()->create([
            'role' => User::ROLE_VIEWER_AUDITOR,
            'is_active' => true,
        ]);
        $viewer->assignRole(User::ROLE_VIEWER_AUDITOR);
        $inactiveManager = User::factory()->create([
            'role' => User::ROLE_ORDER_MANAGER,
            'is_active' => false,
        ]);
        $inactiveManager->assignRole(User::ROLE_ORDER_MANAGER);

        $this->submitLeasing($cart, 'leasing-notification-recipients')->assertCreated();
        $application = LeasingApplication::query()->sole();
        (new SendLeasingApplicationNotifications)->handle(
            new LeasingApplicationSubmitted($application->getKey()),
        );

        Notification::assertSentOnDemand(
            CustomerLeasingApplicationSubmittedNotification::class,
            fn (
                CustomerLeasingApplicationSubmittedNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable,
            ): bool => $channels === ['mail']
                && $notifiable->routes['mail'] === 'checkout@example.test',
        );
        Notification::assertSentOnDemand(
            InternalLeasingApplicationSubmittedNotification::class,
            fn (
                InternalLeasingApplicationSubmittedNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable,
            ): bool => $channels === ['mail']
                && $notifiable->routes['mail'] === 'sales@mycomputer.bg',
        );
        Notification::assertSentTo($manager, DatabaseNotification::class);
        Notification::assertNotSentTo($viewer, DatabaseNotification::class);
        Notification::assertNotSentTo($inactiveManager, DatabaseNotification::class);

        $customerMail = new CustomerLeasingApplicationSubmittedNotification($application);
        $internalMail = new InternalLeasingApplicationSubmittedNotification($application);
        $this->assertStringContainsString('Получихме заявката Ви', $customerMail->toMail(new AnonymousNotifiable)->subject);
        $this->assertStringContainsString('Нова заявка за покупка на изплащане', $internalMail->toMail(new AnonymousNotifiable)->subject);
    }

    private function prepareLeasingCart(string $name)
    {
        config()->set('payments.methods.leasing.enabled', true);
        $cart = $this->prepareCheckoutCart($name);
        PaymentMethod::query()->where('code', 'leasing')->update(['status' => 'active']);

        return $cart;
    }

    private function submitLeasing($cart, string $key)
    {
        return $this->submitCheckout($cart, $key, [
            'payment_method' => 'leasing',
            'leasing_application' => [
                'term_months' => 24,
                'down_payment' => '0.00',
                'contact_method' => 'email',
                'contact_time' => 'morning',
                'note' => null,
                'consent' => true,
            ],
        ]);
    }
}
