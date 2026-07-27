<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentMethodUnavailableException;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class PaymentMethodAvailabilityService
{
    public function __construct(
        private readonly PaymentProviderResolver $providers,
    ) {}

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function availableMethods(): Collection
    {
        return PaymentMethod::query()
            ->with('provider')
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (PaymentMethod $method): bool => $this->isAvailable($method))
            ->values();
    }

    public function requireAvailable(string $code): PaymentMethod
    {
        $method = PaymentMethod::query()
            ->with('provider')
            ->where('code', $code)
            ->first();

        if (! $method || ! $this->isAvailable($method)) {
            throw new PaymentMethodUnavailableException;
        }

        return $method;
    }

    public function isAvailable(PaymentMethod $method): bool
    {
        if ($method->status !== 'active') {
            return false;
        }

        if ($method->payment_provider_id === null) {
            return false;
        }

        if (! $method->relationLoaded('provider')) {
            $method->load('provider');
        }

        if (! $method->provider || $method->provider->status !== 'active') {
            return false;
        }

        if (! $this->providers->supports($method) || ! $this->launchPolicyAllows($method)) {
            return false;
        }

        try {
            return $this->providers->resolve($method)?->isOperational() === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function launchPolicyAllows(PaymentMethod $method): bool
    {
        return match ($method->code) {
            'card' => config('payments.methods.card.enabled') === true,
            'cash_on_delivery', 'bank_transfer', 'leasing' => true,
            default => false,
        };
    }
}
