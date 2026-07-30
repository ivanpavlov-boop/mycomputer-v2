<?php

namespace App\Console\Commands;

use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Commerce\PublicCommerceReleaseGate;
use App\Services\Legal\LegalContentRegistry;
use App\Services\Payments\PaymentMethodAvailabilityService;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CommerceReleasePreflight extends Command
{
    protected $signature = 'commerce:release-preflight {--json : Output the report as JSON}';

    protected $description = 'Run a read-only readiness check for controlled public commerce activation.';

    public function handle(
        PublicCommerceReleaseGate $releaseGate,
        LegalContentRegistry $legalContent,
        PaymentMethodAvailabilityService $paymentMethods,
    ): int {
        $availablePaymentCodes = $this->readSafely(
            fn () => $paymentMethods->availableMethods()->pluck('code'),
        );
        $shippingAvailable = $this->readSafely(fn (): bool => $this->shippingAvailable());
        $activeSuperAdminPresent = $this->readSafely(
            fn (): bool => $this->activeSuperAdminPresent(),
        );
        $legalAcceptanceSchemaPresent = $this->readSafely(
            fn (): bool => $this->legalAcceptanceSchemaPresent(),
        );
        $legalRoutes = [
            'terms' => $legalContent->termsRoute(),
            'privacy' => $legalContent->privacyRoute(),
        ];

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
            'legal_manifest_valid' => $legalContent->isManifestValid(),
            'terms_route_present' => $legalRoutes['terms'] === '/obshti-usloviya'
                && $this->legalSourcePresent('terms'),
            'privacy_route_present' => $legalRoutes['privacy'] === '/politika-za-poveritelnost'
                && $this->legalSourcePresent('privacy'),
            'terms_version_present' => $legalContent->termsVersion() !== '',
            'privacy_version_present' => $legalContent->privacyVersion() !== '',
            'legal_effective_dates_present' => $legalContent->effectiveDatesPresent(),
            'legal_content_approved' => $legalContent->isApproved(),
            'legal_acceptance_schema_present' => $legalAcceptanceSchemaPresent ?? false,
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

    private function legalSourcePresent(string $document): bool
    {
        $path = config("legal.source_pages.{$document}");

        return is_string($path) && $path !== '' && is_file($path);
    }

    private function legalAcceptanceSchemaPresent(): bool
    {
        return collect([
            'legal_accepted_at',
            'terms_version',
            'privacy_version',
            'legal_acceptance_locale',
        ])->every(fn (string $column): bool => Schema::hasColumn('orders', $column));
    }
}
