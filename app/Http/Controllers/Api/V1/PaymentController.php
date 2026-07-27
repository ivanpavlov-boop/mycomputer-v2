<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentProvider;
use App\Services\Payments\PaymentMethodAvailabilityService;
use App\Services\Payments\Webhooks\WebhookSignatureValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentController extends Controller
{
    public function methods(PaymentMethodAvailabilityService $availability): AnonymousResourceCollection
    {
        return PaymentMethodResource::collection(
            $availability->availableMethods(),
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
