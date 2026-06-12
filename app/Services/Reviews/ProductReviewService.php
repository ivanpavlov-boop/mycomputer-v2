<?php

namespace App\Services\Reviews;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class ProductReviewService
{
    public function submit(Product $product, array $data, ?User $user = null): ProductReview
    {
        $this->assertPublicProduct($product);

        $email = $user?->email ?? $data['customer_email'];
        $name = $user ? trim($user->first_name.' '.$user->last_name) : $data['customer_name'];
        $order = $this->verifiedOrder($product, $email, $user);

        try {
            return ProductReview::query()->create([
                'product_id' => $product->id,
                'user_id' => $user?->id,
                'order_id' => $order?->id,
                'customer_name' => filled($name) ? $name : $user?->name,
                'customer_email' => $email,
                'rating' => $data['rating'],
                'title' => $this->sanitize($data['title'] ?? null),
                'comment' => $this->sanitize($data['comment']),
                'pros' => $this->sanitize($data['pros'] ?? null),
                'cons' => $this->sanitize($data['cons'] ?? null),
                'is_verified_purchase' => $order !== null,
                'status' => 'pending',
            ]);
        } catch (QueryException) {
            abort(422, 'A review for this product already exists.');
        }
    }

    public function vote(ProductReview $review, string $voteType, ?User $user = null, ?string $sessionId = null)
    {
        abort_unless($review->status === 'approved', 404);

        $identity = $user
            ? ['user_id' => $user->id]
            : ['session_id' => filled($sessionId) ? $sessionId : (string) Str::uuid()];

        abort_if($review->votes()->where($identity)->exists(), 422, 'You already voted for this review.');

        return $review->votes()->create($identity + ['vote_type' => $voteType]);
    }

    public function report(ProductReview $review, array $data, ?User $user = null, ?string $sessionId = null)
    {
        abort_unless($review->status === 'approved', 404);

        return $review->reports()->create([
            'user_id' => $user?->id,
            'session_id' => $user ? null : (filled($sessionId) ? $sessionId : (string) Str::uuid()),
            'reason' => $this->sanitize($data['reason']),
            'message' => $this->sanitize($data['message'] ?? null),
            'status' => 'pending',
        ]);
    }

    private function verifiedOrder(Product $product, string $email, ?User $user): ?Order
    {
        return Order::query()
            ->whereIn('status', ['completed', 'shipped'])
            ->where(function ($query) use ($email, $user): void {
                if ($user) {
                    $query->where('user_id', $user->id)
                        ->orWhere(function ($query) use ($email): void {
                            $query->whereNull('user_id')->where('customer_email', $email);
                        });
                } else {
                    $query->where('customer_email', $email);
                }
            })
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
            ->latest()
            ->first();
    }

    private function assertPublicProduct(Product $product): void
    {
        abort_unless($product->active && $product->published_at !== null, 422, 'Product is not available.');
    }

    private function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim(strip_tags($value));
    }
}
