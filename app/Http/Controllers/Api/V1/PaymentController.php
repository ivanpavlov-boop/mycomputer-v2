<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InitiatePaymentRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Http\Resources\PaymentTransactionResource;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentProvider;
use App\Services\Payments\PaymentService;
use App\Services\Payments\Webhooks\WebhookSignatureValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentController extends Controller
{
    public function methods(): AnonymousResourceCollection
    {
        return PaymentMethodResource::collection(
            PaymentMethod::query()
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('payment_provider_id')->orWhereHas('provider', fn ($provider) => $provider->where('status', 'active')))
                ->orderBy('sort_order')
                ->get(),
        );
    }

    public function initiate(InitiatePaymentRequest $request, PaymentService $paymentService): PaymentTransactionResource
    {
        $order = Order::query()->findOrFail($request->integer('order_id'));

        return PaymentTransactionResource::make(
            $paymentService->initiate($order, $request->validated('payment_method_code')),
        );
    }

    public function webhook(string $provider, Request $request, WebhookSignatureValidatorFactory $validators): JsonResponse
    {
        abort_unless(PaymentProvider::query()->where('code', $provider)->where('status', 'active')->exists(), 404);
        abort_unless($validators->make($provider)->validate($provider, $request), 401, 'Invalid webhook signature.');

        return response()->json([
            'data' => [
                'status' => 'received',
                'provider' => $provider,
                'signature_validation' => 'valid',
            ],
        ]);
    }
}
