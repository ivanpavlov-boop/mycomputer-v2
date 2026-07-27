<?php

namespace App\Services\Payments;

use App\Models\PaymentMethod;
use App\Services\Payments\Contracts\PaymentProviderInterface;
use App\Services\Payments\Providers\BankTransferProvider;
use App\Services\Payments\Providers\CardPaymentProvider;
use App\Services\Payments\Providers\CashOnDeliveryProvider;
use App\Services\Payments\Providers\LeasingPaymentProvider;
use Illuminate\Contracts\Container\Container;

class PaymentProviderResolver
{
    /**
     * @var array<string, class-string<PaymentProviderInterface>>
     */
    private const PROVIDERS = [
        'cash_on_delivery' => CashOnDeliveryProvider::class,
        'bank_transfer' => BankTransferProvider::class,
        'card' => CardPaymentProvider::class,
        'leasing' => LeasingPaymentProvider::class,
    ];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function resolve(PaymentMethod|string $method): ?PaymentProviderInterface
    {
        $code = $method instanceof PaymentMethod ? $method->code : $method;
        $provider = self::PROVIDERS[$code] ?? null;

        return $provider ? $this->container->make($provider) : null;
    }

    public function supports(PaymentMethod|string $method): bool
    {
        $code = $method instanceof PaymentMethod ? $method->code : $method;

        return array_key_exists($code, self::PROVIDERS);
    }
}
