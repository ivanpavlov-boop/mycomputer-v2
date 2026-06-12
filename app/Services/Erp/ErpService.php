<?php

namespace App\Services\Erp;

use App\Models\Customer;
use App\Models\ErpProvider;
use App\Models\ErpSyncJob;
use App\Models\Order;
use App\Models\User;
use App\Services\Erp\Contracts\ErpProviderInterface;
use App\Services\Erp\ErpNet\ErpNetConfig;
use App\Services\Erp\Microinvest\MicroinvestConfig;
use App\Services\Erp\Providers\BusinessNavigatorProvider;
use App\Services\Erp\Providers\ErpNetProvider;
use App\Services\Erp\Providers\ManualErpProvider;
use App\Services\Erp\Providers\MicroinvestProvider;
use App\Services\Erp\Providers\MockErpProvider;

class ErpService
{
    public function activeProvider(): ?ErpProvider
    {
        return ErpProvider::query()->where('status', 'active')->orderBy('id')->first();
    }

    public function provider(?ErpProvider $provider = null): ErpProviderInterface
    {
        $code = $provider?->code ?? 'manual';

        return match ($code) {
            'mock' => new MockErpProvider,
            'microinvest' => new MicroinvestProvider(MicroinvestConfig::fromProvider($provider)),
            'erp_net' => new ErpNetProvider(ErpNetConfig::fromProvider($provider)),
            'business_navigator' => new BusinessNavigatorProvider,
            default => new ManualErpProvider,
        };
    }

    public function createSyncJob(string $syncType, string $entityType, int $entityId, array $payload = [], ?ErpProvider $provider = null, string $status = 'pending'): ErpSyncJob
    {
        $provider ??= $this->activeProvider();

        return ErpSyncJob::query()->create([
            'provider_id' => $provider?->id,
            'sync_type' => $syncType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'status' => $status,
            'payload' => $this->maskPayload($payload),
        ]);
    }

    public function orderPayload(Order $order): array
    {
        $order->loadMissing(['items', 'bundleItems', 'customer', 'user', 'paymentTransactions']);

        return [
            'order_number' => $order->order_number,
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'company_name' => $order->company_name,
                'vat_number' => $order->vat_number,
                'billing_address' => $order->billing_address,
                'shipping_address' => $order->shipping_address,
            ],
            'totals' => [
                'subtotal' => (float) $order->subtotal,
                'shipping_price' => (float) $order->shipping_price,
                'discount_total' => (float) $order->discount_total,
                'grand_total' => (float) $order->grand_total,
            ],
            'payment' => [
                'method' => $order->payment_method,
                'status' => $order->payment_status,
            ],
            'items' => $order->items->map(fn ($item): array => [
                'sku' => $item->sku,
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
            ])->all(),
            'bundles' => $order->bundleItems->map(fn ($bundle): array => [
                'name' => $bundle->bundle_name,
                'quantity' => $bundle->quantity,
                'total_price' => (float) $bundle->total_price,
                'selected_items' => $bundle->selected_items,
            ])->all(),
        ];
    }

    public function customerPayload(Customer|User $customer): array
    {
        return [
            'name' => trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')) ?: ($customer->name ?? null),
            'email' => $customer->email,
            'phone' => $customer->phone,
            'company_name' => $customer->company_name,
            'vat_number' => $customer->vat_number,
            'billing_address' => $customer instanceof Customer ? $customer->billing_address : null,
            'shipping_address' => $customer instanceof Customer ? $customer->shipping_address : null,
        ];
    }

    public function paymentPayload(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'paid_amount' => (float) $order->grand_total,
            'paid_date' => $order->paymentTransactions()->where('status', 'paid')->latest()->value('paid_at'),
        ];
    }

    public function maskPayload(array $payload): array
    {
        foreach (['password', 'token', 'api_key', 'secret', 'credentials'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '***';
            }
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->maskPayload($value);
            }
        }

        return $payload;
    }
}
