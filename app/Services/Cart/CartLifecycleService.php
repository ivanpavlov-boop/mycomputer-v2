<?php

namespace App\Services\Cart;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartLifecycleService
{
    public const LIFETIME_DAYS = 14;

    public const RENEWAL_THRESHOLD_DAYS = 7;

    public function __construct(
        private readonly CartMergeService $merger,
    ) {}

    public function resolveGuest(?string $sessionId): Cart
    {
        return DB::transaction(function () use ($sessionId): Cart {
            if ($sessionId === null) {
                return $this->createCart((string) Str::uuid());
            }

            $cart = Cart::query()
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                $cart = Cart::query()->firstOrCreate(
                    ['session_id' => $sessionId],
                    [
                        'status' => CartStatus::Active->value,
                        'expires_at' => now()->addDays(self::LIFETIME_DAYS),
                    ],
                );

                $cart = Cart::query()
                    ->whereKey($cart->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            abort_if($cart->user_id !== null, 403, 'Cart access is not allowed.');

            $now = now();

            if ($this->isEligibleActive($cart, $now)) {
                $this->renewIfNeeded($cart, $now);

                return $cart;
            }

            if ($cart->status === CartStatus::Active->value && $cart->expires_at?->lte($now)) {
                $cart->update(['status' => CartStatus::Expired->value]);
            }

            return $this->createCart((string) Str::uuid());
        });
    }

    public function resolveAuthenticated(User $user, ?string $sessionId): Cart
    {
        return DB::transaction(function () use ($user, $sessionId): Cart {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $now = now();

            $suppliedCartId = $sessionId === null
                ? null
                : Cart::query()->where('session_id', $sessionId)->value('id');

            $userCartIds = $this->eligibleActiveQuery($now)
                ->where('user_id', $lockedUser->getKey())
                ->pluck('id');

            $cartIds = $userCartIds
                ->when($suppliedCartId !== null, fn (Collection $ids): Collection => $ids->push($suppliedCartId))
                ->unique()
                ->sort()
                ->values();

            $lockedCarts = $cartIds->isEmpty()
                ? collect()
                : Cart::query()
                    ->whereIn('id', $cartIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            $suppliedCart = $suppliedCartId === null
                ? null
                : $lockedCarts->firstWhere('id', $suppliedCartId);

            if ($suppliedCart !== null) {
                abort_if(
                    $suppliedCart->user_id !== null
                        && (int) $suppliedCart->user_id !== (int) $lockedUser->getKey(),
                    403,
                    'Cart access is not allowed.',
                );

                if (
                    $suppliedCart->status === CartStatus::Active->value
                    && $suppliedCart->expires_at?->lte($now)
                ) {
                    $suppliedCart->update(['status' => CartStatus::Expired->value]);
                }
            }

            $eligibleCarts = $lockedCarts
                ->filter(fn (Cart $cart): bool => $this->isEligibleActive($cart, $now));

            $target = $suppliedCart !== null && $this->isEligibleActive($suppliedCart, $now)
                ? $suppliedCart
                : $eligibleCarts
                    ->where('user_id', $lockedUser->getKey())
                    ->sortBy('id')
                    ->first();

            if ($target === null) {
                $target = $this->createOwnedCart(
                    $lockedUser,
                    $suppliedCart === null && $sessionId !== null
                        ? $sessionId
                        : (string) Str::uuid(),
                );
            }

            $sources = $eligibleCarts
                ->where('user_id', $lockedUser->getKey())
                ->reject(fn (Cart $cart): bool => $cart->is($target))
                ->sortBy('id')
                ->values();

            if ($sources->isNotEmpty()) {
                $target = $this->merger->mergeInto($target, $sources, $lockedUser);
            } else {
                $updates = [];

                if ((int) $target->user_id !== (int) $lockedUser->getKey()) {
                    $updates['user_id'] = $lockedUser->getKey();
                }

                if (! filled($target->customer_email)) {
                    $updates['customer_email'] = $lockedUser->email;
                }

                if ($updates !== []) {
                    $target->update($updates);
                }
            }

            $this->renewIfNeeded($target, $now);

            return $target;
        });
    }

    private function eligibleActiveQuery(CarbonInterface $now): Builder
    {
        return Cart::query()
            ->where('status', CartStatus::Active->value)
            ->where(
                fn (Builder $query): Builder => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now),
            );
    }

    private function isEligibleActive(Cart $cart, CarbonInterface $now): bool
    {
        return $cart->status === CartStatus::Active->value
            && ($cart->expires_at === null || $cart->expires_at->gt($now));
    }

    private function renewIfNeeded(Cart $cart, CarbonInterface $now): void
    {
        if (
            $cart->expires_at !== null
            && $cart->expires_at->gt($now->copy()->addDays(self::RENEWAL_THRESHOLD_DAYS))
        ) {
            return;
        }

        $cart->update([
            'expires_at' => $now->copy()->addDays(self::LIFETIME_DAYS),
        ]);
    }

    private function createCart(string $sessionId): Cart
    {
        $cart = Cart::query()->create([
            'session_id' => $sessionId,
            'status' => CartStatus::Active->value,
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return Cart::query()->findOrFail($cart->getKey());
    }

    private function createOwnedCart(User $user, string $sessionId): Cart
    {
        $cart = Cart::query()->firstOrCreate(
            ['session_id' => $sessionId],
            [
                'user_id' => $user->getKey(),
                'customer_email' => $user->email,
                'status' => CartStatus::Active->value,
                'expires_at' => now()->addDays(self::LIFETIME_DAYS),
            ],
        );

        $cart = Cart::query()
            ->whereKey($cart->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        abort_if(
            $cart->user_id !== null && (int) $cart->user_id !== (int) $user->getKey(),
            403,
            'Cart access is not allowed.',
        );

        abort_unless($this->isEligibleActive($cart, now()), 409, 'Cart session is not available.');

        if ($cart->user_id === null) {
            $cart->update([
                'user_id' => $user->getKey(),
                'customer_email' => filled($cart->customer_email)
                    ? $cart->customer_email
                    : $user->email,
            ]);
        }

        return $cart;
    }
}
