<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerAddressResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\UserResource;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('profile', 'addresses');

        return response()->json([
            'data' => [
                'profile' => UserResource::make($user),
                'addresses' => CustomerAddressResource::collection($user->addresses),
                'orders_summary' => [
                    'total_orders' => $this->ownedOrders($request)->count(),
                    'latest_order' => OrderResource::make($this->ownedOrders($request)->latest()->first()),
                ],
                'wishlist_summary' => [
                    'items_count' => 0,
                    'ready' => false,
                ],
            ],
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        return response()->json([
            'data' => OrderResource::collection(
                Order::query()
                    ->where(fn (Builder $query) => $this->ownedOrdersConstraint($query, $request))
                    ->latest()
                    ->paginate(min((int) $request->query('per_page', 15), 50))
            )->response()->getData(true),
        ]);
    }

    public function order(Request $request, Order $order): JsonResponse
    {
        abort_unless(
            $order->user_id === $request->user()->id ||
            ($order->user_id === null && $order->customer_email === $request->user()->email),
            404
        );

        return response()->json([
            'data' => OrderResource::make($order->load('items', 'shipments', 'paymentTransactions')),
        ]);
    }

    private function ownedOrders(Request $request): Builder
    {
        return Order::query()
            ->where(fn (Builder $query) => $this->ownedOrdersConstraint($query, $request));
    }

    private function ownedOrdersConstraint(Builder $query, Request $request): void
    {
        $query
            ->where('user_id', $request->user()->id)
            ->orWhere(fn (Builder $fallback) => $fallback
                ->whereNull('user_id')
                ->where('customer_email', $request->user()->email));
    }
}
