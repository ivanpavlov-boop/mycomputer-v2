<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RewardVoucherResource;
use App\Models\RewardVoucher;
use App\Services\Loyalty\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RewardController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RewardVoucherResource::collection(
            RewardVoucher::query()->available()->orderBy('points_cost')->paginate(20),
        );
    }

    public function show(RewardVoucher $reward): RewardVoucherResource
    {
        abort_unless($reward->is_active, 404);

        return RewardVoucherResource::make($reward);
    }

    public function redeem(Request $request, VoucherService $vouchers): JsonResponse
    {
        $data = $request->validate([
            'reward_id' => ['required', 'integer', 'exists:reward_vouchers,id'],
        ]);

        $reward = RewardVoucher::query()->findOrFail($data['reward_id']);

        try {
            $redemption = $vouchers->redeem($request->user(), $reward);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['reward_id' => $exception->getMessage()]);
        }

        return response()->json([
            'data' => [
                'code' => $redemption->code,
                'redeemed_points' => $redemption->redeemed_points,
            ],
        ], 201);
    }
}
