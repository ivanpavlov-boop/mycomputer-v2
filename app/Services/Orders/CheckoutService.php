<?php

namespace App\Services\Orders;

use App\Enums\CartStatus;
use App\Events\OrderCreated;
use App\Exceptions\CartPriceChangedException;
use App\Jobs\ConversionTrackingJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Bundles\BundleCartService;
use App\Services\Cart\CartPricingRefreshResult;
use App\Services\Cart\CartPricingRefreshService;
use App\Services\Cart\CartService;
use App\Services\Email\EmailMarketingService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Payments\PaymentService;
use App\Services\Promotions\PromotionEngineService;
use App\Services\Shipping\ShipmentService;
use App\Services\Shipping\ShippingPriceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CartPricingRefreshService $cartPricing,
        private readonly OrderNumberService $orderNumberService,
        private readonly StockReservationService $stockReservationService,
        private readonly ShippingPriceService $shippingPriceService,
        private readonly ShipmentService $shipmentService,
        private readonly PaymentService $paymentService,
        private readonly EmailMarketingService $emailMarketing,
        private readonly LoyaltyService $loyalty,
        private readonly PromotionEngineService $promotions,
        private readonly BundleCartService $bundleCart,
    ) {}

    public function checkout(Cart $cart, array $data): Order
    {
        $outcome = DB::transaction(function () use ($cart, $data): Order|CartPricingRefreshResult {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $pricing = $this->cartPricing->refresh($cart);

            if ($pricing->requiresReview) {
                return $pricing;
            }

            $cart = $pricing->cart;
            $this->stockReservationService->assertAvailable($cart);

            $customer = Customer::query()->updateOrCreate(
                ['email' => $data['email']],
                [
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'phone' => $data['phone'],
                    'company_name' => $data['company_name'] ?? null,
                    'vat_number' => $data['vat_number'] ?? null,
                    'billing_address' => $data['billing_address'],
                    'shipping_address' => $data['shipping_address'],
                ],
            );

            $subtotal = $this->cartService->subtotal($cart);
            $shipping = $this->shippingPriceService->calculate($this->shippingPayload($data), $subtotal);
            $shippingPrice = (float) $shipping['price'];
            $promotionResult = $this->promotions->evaluate($cart, $shippingPrice);
            $shippingDiscount = (float) $promotionResult['shipping_discount'];
            $shippingPrice = max(0, $shippingPrice - $shippingDiscount);
            $reward = null;
            $discountTotal = (float) $promotionResult['discount_total'];

            if (filled($data['reward_code'] ?? null)) {
                if (! $cart->user) {
                    throw ValidationException::withMessages([
                        'reward_code' => 'Reward vouchers require an authenticated customer.',
                    ]);
                }

                $reward = $this->loyalty->applyVoucherDiscount($cart->user, $data['reward_code'], $subtotal);
                $discountTotal += $reward['discount'];
            }

            $discountTotal = min($discountTotal, $subtotal + $shippingPrice);

            $order = Order::query()->create([
                'order_number' => $this->orderNumberService->generate(),
                'customer_id' => $customer->id,
                'user_id' => $cart->user_id,
                'customer_email' => $data['email'],
                'customer_phone' => $data['phone'],
                'customer_name' => $data['first_name'].' '.$data['last_name'],
                'company_name' => $data['company_name'] ?? null,
                'vat_number' => $data['vat_number'] ?? null,
                'billing_address' => $data['billing_address'],
                'shipping_address' => $data['shipping_address'],
                'subtotal' => $subtotal,
                'shipping_price' => $shippingPrice,
                'discount_total' => $discountTotal,
                'grand_total' => $subtotal + $shippingPrice - $discountTotal,
                'payment_method' => $data['payment_method'],
                'payment_status' => 'pending',
                'shipping_method' => $data['shipping_method'],
                'shipping_status' => 'pending',
                'status' => 'pending',
                'notes' => Str::limit(strip_tags((string) ($data['notes'] ?? '')), 1000, ''),
            ]);

            $cart->items->each(fn (CartItem $item) => $order->items()->create([
                'product_id' => $item->product_id,
                'product_name' => $item->product->name,
                'sku' => $item->product->sku,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'total_price' => $item->total_price,
            ]));
            $this->bundleCart->convertToOrder($cart, $order);

            $this->shipmentService->create($order, array_merge($this->shippingPayload($data), [
                'shipping_provider_id' => $shipping['shipping_provider_id'],
                'shipping_method_id' => $shipping['shipping_method_id'],
                'price' => $shippingPrice,
                'address' => $data['shipping_address'],
            ]));

            $this->paymentService->initiate($order, $data['payment_method']);

            $this->stockReservationService->reduce($cart);
            $this->cartService->clear($cart);
            $cart->update(['status' => CartStatus::Converted->value, 'customer_email' => $data['email']]);
            if ($reward && $cart->user) {
                $this->loyalty->vouchers->redeem($cart->user, $reward['voucher'], $order);
            }
            $this->promotions->recordRedemptions($cart, $order, $promotionResult);
            ConversionTrackingJob::dispatch($order->id);
            $this->emailMarketing->order($order, 'order_created');
            $this->emailMarketing->markCartRecovered($cart, $order);
            OrderCreated::dispatch($order->id);

            return $order->load(['items', 'bundleItems', 'shipments.provider', 'shipments.method', 'shipments.office', 'paymentTransactions.method']);
        });

        if ($outcome instanceof CartPricingRefreshResult) {
            throw new CartPriceChangedException;
        }

        return $outcome;
    }

    private function shippingPayload(array $data): array
    {
        $deliveryType = $data['delivery_type'] ?? match ($data['shipping_method']) {
            'office_delivery' => 'office',
            'address_delivery' => 'address',
            default => 'manual',
        };

        return [
            'provider' => $data['shipping_provider'] ?? 'manual',
            'shipping_method' => match ($data['shipping_method']) {
                'office_delivery' => 'office',
                'address_delivery' => 'address',
                default => $data['shipping_method'],
            },
            'delivery_type' => $deliveryType,
            'office_id' => $data['office_id'] ?? null,
            'city' => $data['city'],
            'postcode' => $data['postcode'] ?? null,
            'address' => $data['shipping_address'],
        ];
    }
}
