<?php

namespace App\Services\Promotions;

use App\Models\Cart;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;

class PromotionValidatorService
{
    public function isAvailable(Promotion $promotion, Cart $cart): bool
    {
        if ($promotion->status !== 'active') {
            return false;
        }

        if ($promotion->starts_at && $promotion->starts_at->isFuture()) {
            return false;
        }

        if ($promotion->ends_at && $promotion->ends_at->isPast()) {
            return false;
        }

        if ($promotion->usage_limit !== null && $promotion->usage_count >= $promotion->usage_limit) {
            return false;
        }

        if ($promotion->code && strcasecmp((string) $cart->coupon_code, $promotion->code) !== 0) {
            return false;
        }

        if (! $this->passesRedemptionLimits($promotion, $cart)) {
            return false;
        }

        return $promotion->rules->every(fn ($rule): bool => app(PromotionRuleService::class)->passes($rule, $cart));
    }

    public function passesRedemptionLimits(Promotion $promotion, Cart $cart): bool
    {
        $userLimit = $this->ruleValue($promotion, 'per_user_limit');
        if ($userLimit && $cart->user_id) {
            $count = PromotionRedemption::query()
                ->where('promotion_id', $promotion->id)
                ->where('user_id', $cart->user_id)
                ->count();

            if ($count >= (int) $userLimit) {
                return false;
            }
        }

        $sessionLimit = $this->ruleValue($promotion, 'per_session_limit');
        if ($sessionLimit) {
            $count = PromotionRedemption::query()
                ->where('promotion_id', $promotion->id)
                ->where('session_id', $cart->session_id)
                ->count();

            if ($count >= (int) $sessionLimit) {
                return false;
            }
        }

        return true;
    }

    public function loyaltyTier(Cart $cart): ?string
    {
        $user = $cart->relationLoaded('user') ? $cart->user : User::query()->with('loyaltyAccount')->find($cart->user_id);

        return $user?->loyaltyAccount?->tier;
    }

    private function ruleValue(Promotion $promotion, string $type): mixed
    {
        $rule = $promotion->rules->firstWhere('rule_type', $type);

        return is_array($rule?->value) ? ($rule->value['value'] ?? null) : $rule?->value;
    }
}
