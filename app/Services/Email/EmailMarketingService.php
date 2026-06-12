<?php

namespace App\Services\Email;

use App\Jobs\SendEmailJob;
use App\Models\AbandonedCartRecord;
use App\Models\Cart;
use App\Models\EmailAutomation;
use App\Models\EmailLog;
use App\Models\EmailSubscriber;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPriceAlert;
use App\Models\ProductStockAlert;
use App\Models\User;
use App\Services\Email\Contracts\EmailProviderInterface;
use App\Services\Marketing\MarketingEventService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailMarketingService
{
    public function __construct(
        private readonly EmailProviderInterface $provider,
        private readonly MarketingEventService $events,
    ) {}

    public function subscribe(array $data, ?User $user = null): EmailSubscriber
    {
        $email = strtolower((string) $data['email']);

        $subscriber = EmailSubscriber::query()->updateOrCreate(
            ['email' => $email],
            [
                'user_id' => $user?->id ?? $data['user_id'] ?? null,
                'first_name' => $data['first_name'] ?? $user?->first_name,
                'last_name' => $data['last_name'] ?? $user?->last_name,
                'source' => $data['source'] ?? 'newsletter',
                'status' => 'subscribed',
                'gdpr_consent' => (bool) ($data['gdpr_consent'] ?? false),
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
            ],
        );

        $this->events->log('subscription', 'internal', ['email' => $email, 'source' => $subscriber->source], $user);

        return $subscriber;
    }

    public function unsubscribe(string $email): EmailSubscriber
    {
        $subscriber = EmailSubscriber::query()->firstOrCreate(
            ['email' => strtolower($email)],
            ['source' => 'newsletter'],
        );

        $subscriber->update([
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ]);

        $this->events->log('unsubscribe', 'internal', ['email' => $subscriber->email]);

        return $subscriber;
    }

    public function status(string $email): ?EmailSubscriber
    {
        return EmailSubscriber::query()->where('email', strtolower($email))->first();
    }

    public function queue(string $email, string $type, array $data = [], ?string $subject = null): void
    {
        SendEmailJob::dispatch(strtolower($email), $type, $data, $subject)->onQueue('emails');
    }

    public function send(string $email, string $type, array $data = [], ?string $subject = null): EmailLog
    {
        $template = config("email-marketing.templates.{$type}");
        $subject ??= $template['subject'] ?? str($type)->replace('_', ' ')->title()->toString();
        $view = $template['view'] ?? 'emails.marketing.generic';

        if ($this->isSuppressed($email, $type)) {
            return EmailLog::query()->create([
                'email' => strtolower($email),
                'provider' => $this->provider->name(),
                'type' => $type,
                'subject' => $subject,
                'status' => 'skipped',
                'payload' => ['reason' => 'subscriber is unsubscribed or suppressed'],
            ]);
        }

        $payload = [
            'view' => $view,
            'html' => View::exists($view) ? View::make($view, $data)->render() : null,
            'data' => Arr::except($data, ['password', 'token']),
        ];

        $result = $this->provider->send($email, $subject, $view, $data, ['type' => $type]);

        $log = EmailLog::query()->create([
            'email' => strtolower($email),
            'provider' => $this->provider->name(),
            'type' => $type,
            'subject' => $subject,
            'status' => $result['status'] === 'sent' ? 'sent' : ($result['status'] ?? 'failed'),
            'payload' => $payload + ['provider_result' => $result],
            'sent_at' => ($result['status'] ?? null) === 'sent' ? now() : null,
        ]);

        $this->events->log('email sent', 'internal', [
            'email' => strtolower($email),
            'type' => $type,
            'status' => $log->status,
        ]);

        return $log;
    }

    public function welcome(User $user): void
    {
        $this->subscribe([
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'source' => 'account',
            'gdpr_consent' => false,
        ], $user);

        $this->queue($user->email, 'welcome', ['user' => $user]);
    }

    public function order(Order $order, string $type = 'order_created'): void
    {
        $this->queue($order->customer_email, $type, ['order' => $order->loadMissing('items')]);
    }

    public function recordAbandonedCart(Cart $cart): ?AbandonedCartRecord
    {
        $cart->loadMissing(['items.product', 'user']);

        if ($cart->status !== 'active' || $cart->items->isEmpty()) {
            return null;
        }

        $email = $cart->customer_email ?: $cart->user?->email;
        $subtotal = (float) $cart->items->sum('total_price');
        $itemsCount = (int) $cart->items->sum('quantity');
        $record = AbandonedCartRecord::query()
            ->where('session_id', $cart->session_id)
            ->whereNull('recovered_at')
            ->whereNotIn('status', ['recovered', 'expired'])
            ->first();

        $record ??= new AbandonedCartRecord([
            'session_id' => $cart->session_id,
            'recovery_token' => $this->newRecoveryToken(),
            'recovery_token_expires_at' => now()->addDays((int) config('email-marketing.abandoned_cart.recovery_token_days', 14)),
            'status' => 'pending',
        ]);

        $record->fill([
            'user_id' => $cart->user_id,
            'email' => $email,
            'cart_snapshot' => [
                'items' => $cart->items->map(fn ($item): array => [
                    'product_id' => $item->product_id,
                    'name' => $item->product?->name,
                    'sku' => $item->product?->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                ])->values()->all(),
                'subtotal' => $subtotal,
            ],
            'cart_total' => $subtotal,
            'items_count' => $itemsCount,
            'last_cart_activity_at' => $cart->updated_at,
        ])->save();

        return $record;
    }

    public function processAbandonedCart(AbandonedCartRecord $record): ?EmailLog
    {
        if (! $this->canSendAbandonedCartEmail($record)) {
            return null;
        }

        $stage = min($record->emails_sent + 1, 3);
        $type = $this->sequence()[$stage]['template'] ?? "abandoned_cart_{$stage}";

        $log = $this->send($record->email, $type, [
            'record' => $record,
            'items' => $record->cart_snapshot['items'] ?? [],
            'cartTotal' => $record->cart_total,
            'recoveryUrl' => $record->recoveryUrl(),
            'supportContact' => config('email-marketing.abandoned_cart.support_contact'),
            'unsubscribeUrl' => url('/unsubscribe?email='.urlencode($record->email)),
        ]);

        $timestampColumn = match ($stage) {
            1 => 'first_email_sent_at',
            2 => 'second_email_sent_at',
            default => 'third_email_sent_at',
        };

        $record->update([
            'emails_sent' => $stage,
            'last_email_sent_at' => now(),
            $timestampColumn => now(),
            'status' => match ($stage) {
                1 => 'emailed_once',
                2 => 'emailed_twice',
                default => 'emailed_three_times',
            },
        ]);

        return $log;
    }

    public function detectAbandonedCarts(?int $thresholdMinutes = null): int
    {
        $thresholdMinutes ??= (int) config('email-marketing.abandoned_cart.threshold_minutes', 60);
        $cutoff = now()->subMinutes($thresholdMinutes);
        $detected = 0;

        Cart::query()
            ->with(['items.product', 'user'])
            ->where('status', 'active')
            ->where('updated_at', '<=', $cutoff)
            ->whereHas('items')
            ->chunkById(100, function ($carts) use (&$detected): void {
                foreach ($carts as $cart) {
                    if ($this->recordAbandonedCart($cart)) {
                        $detected++;
                    }
                }
            });

        return $detected;
    }

    public function processDueAbandonedCarts(): int
    {
        $processed = 0;
        $this->expireOldAbandonedCarts();

        AbandonedCartRecord::query()
            ->whereNotIn('status', ['recovered', 'expired', 'suppressed'])
            ->whereNotNull('email')
            ->whereNull('recovered_at')
            ->where('emails_sent', '<', 3)
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$processed): void {
                foreach ($records as $record) {
                    if (! $this->isAbandonedCartEmailDue($record)) {
                        continue;
                    }

                    if ($this->processAbandonedCart($record)) {
                        $processed++;
                    }
                }
            });

        return $processed;
    }

    public function attachEmailToCart(Cart $cart, string $email): Cart
    {
        $cart->update(['customer_email' => strtolower($email)]);

        AbandonedCartRecord::query()
            ->where('session_id', $cart->session_id)
            ->whereNull('recovered_at')
            ->whereNotIn('status', ['recovered', 'expired'])
            ->update(['email' => strtolower($email)]);

        return $cart->fresh(['items.product.brand', 'items.product.category', 'items.product.images']);
    }

    public function restoreCartFromToken(string $token, ?string $sessionId = null): Cart
    {
        $record = AbandonedCartRecord::query()
            ->where('recovery_token', $token)
            ->first();

        if (! $record || $record->status === 'expired' || $record->recovery_token_expires_at?->isPast()) {
            throw ValidationException::withMessages(['token' => 'Recovery link has expired or is invalid.']);
        }

        if (in_array($record->status, ['suppressed', 'recovered'], true)) {
            throw ValidationException::withMessages(['token' => 'Recovery link is no longer available.']);
        }

        return DB::transaction(function () use ($record, $sessionId): Cart {
            $cart = Cart::query()->firstOrCreate(
                ['session_id' => $sessionId ?: $record->session_id ?: (string) Str::uuid()],
                ['status' => 'active', 'expires_at' => now()->addDays(14)],
            );

            $cart->update([
                'user_id' => $record->user_id,
                'customer_email' => $record->email,
                'status' => 'active',
                'expires_at' => now()->addDays(14),
            ]);

            $cart->items()->delete();

            foreach (($record->cart_snapshot['items'] ?? []) as $item) {
                $product = Product::query()->find($item['product_id'] ?? null);
                if (! $product || ! $product->active || $product->published_at === null) {
                    continue;
                }

                $quantity = max(1, min((int) ($item['quantity'] ?? 1), 99));
                $unitPrice = (float) ($product->promo_price ?? $product->price);
                $cart->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $quantity,
                ]);
            }

            return $cart->fresh(['items.product.brand', 'items.product.category', 'items.product.images']);
        });
    }

    public function markCartRecovered(Cart $cart, Order $order): void
    {
        AbandonedCartRecord::query()
            ->where('session_id', $cart->session_id)
            ->whereNull('recovered_at')
            ->update([
                'status' => 'recovered',
                'recovered_at' => now(),
                'recovered_order_id' => $order->id,
                'recovered_revenue' => $order->grand_total,
            ]);

        $this->events->log('abandoned_cart_recovered', 'internal', [
            'order_id' => $order->id,
            'grand_total' => $order->grand_total,
        ]);
    }

    public function suppress(AbandonedCartRecord $record): void
    {
        $record->update(['status' => 'suppressed']);
    }

    public function markExpired(AbandonedCartRecord $record): void
    {
        $record->update(['status' => 'expired']);
    }

    public function triggerPriceDrop(Product $product): int
    {
        $alerts = ProductPriceAlert::query()
            ->where('product_id', $product->id)
            ->whereNull('triggered_at')
            ->where(fn ($query) => $query->whereNull('target_price')->orWhere('target_price', '>=', $product->price))
            ->get();

        foreach ($alerts as $alert) {
            $this->queue($alert->email, 'price_drop', ['product' => $product, 'alert' => $alert]);
            $alert->update(['triggered_at' => now()]);
        }

        return $alerts->count();
    }

    public function triggerBackInStock(Product $product): int
    {
        if (! in_array($product->stock_status, ['in_stock', 'limited_stock'], true)) {
            return 0;
        }

        $alerts = ProductStockAlert::query()
            ->where('product_id', $product->id)
            ->whereNull('triggered_at')
            ->get();

        foreach ($alerts as $alert) {
            $this->queue($alert->email, 'back_in_stock', ['product' => $product, 'alert' => $alert]);
            $alert->update(['triggered_at' => now()]);
        }

        return $alerts->count();
    }

    private function isSuppressed(string $email, string $type): bool
    {
        if (! str_starts_with($type, 'abandoned_cart') && ! in_array($type, ['welcome', 'wishlist_reminder', 'price_drop', 'back_in_stock'], true)) {
            return false;
        }

        $subscriber = $this->status($email);

        return $subscriber && in_array($subscriber->status, ['unsubscribed', 'bounced', 'suppressed'], true);
    }

    private function canSendAbandonedCartEmail(AbandonedCartRecord $record): bool
    {
        if ($record->recovered_at || ! $record->email || in_array($record->status, ['recovered', 'expired', 'suppressed'], true)) {
            return false;
        }

        if ($record->recovery_token_expires_at?->isPast()) {
            $record->update(['status' => 'expired']);

            return false;
        }

        if ($this->isSuppressed($record->email, 'abandoned_cart_1')) {
            $record->update(['status' => 'suppressed']);

            return false;
        }

        return true;
    }

    private function isAbandonedCartEmailDue(AbandonedCartRecord $record): bool
    {
        $stage = min($record->emails_sent + 1, 3);
        $delayHours = (int) ($this->sequence()[$stage]['delay_hours'] ?? match ($stage) {
            1 => 1,
            2 => 24,
            default => 72,
        });

        $base = $stage === 1
            ? $record->last_cart_activity_at
            : $record->last_email_sent_at;

        if (! $base) {
            return true;
        }

        return $base->copy()->addHours($delayHours)->isPast();
    }

    private function expireOldAbandonedCarts(): void
    {
        AbandonedCartRecord::query()
            ->whereNotIn('status', ['recovered', 'expired'])
            ->whereNotNull('recovery_token_expires_at')
            ->where('recovery_token_expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    private function sequence(): array
    {
        $defaults = config('email-marketing.abandoned_cart.sequence', []);
        $automations = EmailAutomation::query()
            ->where('trigger', 'abandoned_cart')
            ->where('enabled', true)
            ->get()
            ->mapWithKeys(function (EmailAutomation $automation, int $index) use ($defaults): array {
                $template = $automation->configuration['template'] ?? null;
                if (! $template) {
                    return [];
                }

                $stage = str_ends_with($template, '_1') ? 1 : (str_ends_with($template, '_2') ? 2 : (str_ends_with($template, '_3') ? 3 : $index + 1));

                return [$stage => [
                    'template' => $template,
                    'delay_hours' => (int) ($automation->configuration['delay_hours'] ?? ($defaults[$stage]['delay_hours'] ?? 1)),
                ]];
            })
            ->all();

        return array_replace($defaults, $automations);
    }

    private function newRecoveryToken(): string
    {
        do {
            $token = Str::random(64);
        } while (AbandonedCartRecord::query()->where('recovery_token', $token)->exists());

        return $token;
    }
}
