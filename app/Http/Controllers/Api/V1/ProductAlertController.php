<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPriceAlert;
use App\Models\ProductStockAlert;
use App\Services\Marketing\MarketingEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductAlertController extends Controller
{
    public function __construct(private readonly MarketingEventService $events) {}

    public function price(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->active, 404);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'target_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $alert = ProductPriceAlert::query()->updateOrCreate(
            ['email' => strtolower($data['email']), 'product_id' => $product->id],
            [
                'user_id' => $request->user()?->id,
                'target_price' => $data['target_price'] ?? null,
                'triggered_at' => null,
            ],
        );

        return response()->json(['data' => ['id' => $alert->id, 'status' => 'created']], 201);
    }

    public function stock(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->active, 404);

        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $alert = ProductStockAlert::query()->updateOrCreate(
            ['email' => strtolower($data['email']), 'product_id' => $product->id],
            [
                'user_id' => $request->user()?->id,
                'triggered_at' => null,
            ],
        );

        $this->events->log('stock_alert_signup', 'internal', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'availability_status' => $product->availabilityStatus?->code ?? $product->stock_status,
        ], $request->user(), $request->header('X-Session-Id'));

        return response()->json(['data' => ['id' => $alert->id, 'status' => 'created']], 201);
    }
}
