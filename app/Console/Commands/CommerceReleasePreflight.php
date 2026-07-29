<?php

namespace App\Console\Commands;

use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Commerce\PublicCommerceReleaseGate;
use App\Services\Payments\PaymentMethodAvailabilityService;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Throwable;

class CommerceReleasePreflight extends Command
{
    protected $signature = 'commerce:release-preflight {--json : Output the report as JSON}';

    protected $description = 'Run a read-only readiness check for controlled public commerce activation.';

    public function handle(
        PublicCommerceReleaseGate $releaseGate,
        PaymentMethodAvailabilityService $paymentMethods,
    ): int {
        $availablePaymentCodes = $this->readSafely(
            fn () => $paymentMethods->availableMethods()->pluck('code'),
        );
        $shippingAvailable = $this->readSafely(fn (): bool => $this->shippingAvailable());
        $activeSuperAdminPresent = $this->readSafely(
            fn (): bool => $this->activeSuperAdminPresent(),
        );
        $legalRoutes = $this->legalRoutes();

        $checks = [
            'configuration_valid' => $releaseGate->isValidConfiguration(),
            'checkout_route_present' => $this->routeExists('api/v1/checkout', 'POST'),
            'confirmation_route_present' => $this->routeExists('api/v1/checkout/confirmation', 'GET'),
            'guest_payment_retry_route_present' => $this->routeExists('api/v1/checkout/payment-attempts', 'POST'),
            'database_accessible' => $availablePaymentCodes !== null
                && $shippingAvailable !== null
                && $activeSuperAdminPresent !== null,
            'cash_on_delivery_available' => $availablePaymentCodes?->contains('cash_on_delivery') ?? false,
            'bank_transfer_available' => $availablePaymentCodes?->contains('bank_transfer') ?? false,
            'card_disabled' => $availablePaymentCodes !== null
                && ! $availablePaymentCodes->contains('card')
                && config('payments.methods.card.enabled') === false,
            'leasing_disabled' => $availablePaymentCodes !== null
                && ! $availablePaymentCodes->contains('leasing')
                && config('payments.methods.leasing.enabled') === false,
            'shipping_available' => $shippingAvailable ?? false,
            'active_super_admin_present' => $activeSuperAdminPresent ?? false,
            'catalog_sync_safe' => config('catalog_sync.update_enabled') === false
                && config('catalog_sync.sync_all_enabled') === false
                && config('catalog_sync.auto_enabled') === false,
            'abandoned_cart_recovery_disabled' => config('commerce.abandoned_cart_recovery.enabled') === false,
            'terms_route_present' => $legalRoutes['terms'] !== null,
            'privacy_route_present' => $legalRoutes['privacy'] !== null,
        ];

        $blockers = collect($checks)
            ->reject()
            ->keys()
            ->values()
            ->all();

        $payload = [
            'state' => $releaseGate->state(),
            'ready_for_activation' => $blockers === [],
            'checks' => $checks,
            'legal_routes' => $legalRoutes,
            'blockers' => $blockers,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->info('Public commerce state: '.$payload['state']);
            $this->table(
                ['Check', 'Result'],
                collect($checks)
                    ->map(fn (bool $passed, string $check): array => [
                        $check,
                        $passed ? 'PASS' : 'BLOCKED',
                    ])
                    ->values()
                    ->all(),
            );
        }

        return $payload['ready_for_activation'] ? self::SUCCESS : self::FAILURE;
    }

    private function readSafely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }

    private function routeExists(string $uri, string $method): bool
    {
        return collect(Route::getRoutes()->getRoutes())
            ->contains(fn (IlluminateRoute $route): bool => $route->uri() === $uri
                && in_array($method, $route->methods(), true));
    }

    private function shippingAvailable(): bool
    {
        return ShippingMethod::query()
            ->where('status', 'active')
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'))
            ->exists();
    }

    private function activeSuperAdminPresent(): bool
    {
        return User::query()
            ->where('is_active', true)
            ->get()
            ->contains(fn (User $user): bool => $user->isActiveAdminAccount() && $user->isSuperAdmin());
    }

    /**
     * @return array{terms: ?string, privacy: ?string}
     */
    private function legalRoutes(): array
    {
        $publicGetRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (IlluminateRoute $route): bool => in_array('GET', $route->methods(), true))
            ->map(fn (IlluminateRoute $route): string => '/'.ltrim($route->uri(), '/'))
            ->all();

        return [
            'terms' => $this->firstRoute($publicGetRoutes, [
                '/terms',
                '/terms-and-conditions',
                '/obshti-usloviya',
                '/obsti-usloviya',
            ]),
            'privacy' => $this->firstRoute($publicGetRoutes, [
                '/privacy',
                '/privacy-policy',
                '/politika-za-poveritelnost',
            ]),
        ];
    }

    /**
     * @param  array<int, string>  $routes
     * @param  array<int, string>  $candidates
     */
    private function firstRoute(array $routes, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $routes, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
