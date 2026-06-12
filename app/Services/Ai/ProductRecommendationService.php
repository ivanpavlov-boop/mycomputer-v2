<?php

namespace App\Services\Ai;

use App\Http\Resources\ProductCardResource;
use App\Models\AiRecommendationLog;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Contracts\AiProviderInterface;
use Illuminate\Support\Collection;

class ProductRecommendationService
{
    public function __construct(private readonly AiProviderInterface $provider) {}

    public function recommend(string $query, ?User $user = null, ?string $sessionId = null): array
    {
        $intent = $this->parseIntent($query);
        $products = $this->searchProducts($intent);
        $productPayload = ProductCardResource::collection($products)->resolve();
        $ai = $this->provider->recommend($query, $productPayload, ['intent' => $intent]);

        $result = [
            'query' => $query,
            'intent' => $intent,
            'summary' => $ai['summary'],
            'reasoning' => $ai['reasoning'],
            'products' => $productPayload,
        ];

        AiRecommendationLog::query()->create([
            'user_id' => $user?->id,
            'session_id' => $sessionId,
            'query' => $query,
            'recommendation_type' => 'product_recommendation',
            'results' => $result,
        ]);

        return $result;
    }

    public function parseIntent(string $query): array
    {
        $text = mb_strtolower($query);
        preg_match('/(?:under|до)\s*(\d{3,5})/u', $text, $match);

        return [
            'category_keywords' => $this->categoryKeywords($text),
            'brand_keywords' => $this->brandKeywords($text),
            'price_max' => isset($match[1]) ? (int) $match[1] : null,
            'raw_query' => $query,
        ];
    }

    public function searchProducts(array $intent): Collection
    {
        $query = Product::query()->published()->with(['brand', 'category', 'images']);

        if ($intent['price_max']) {
            $query->where('price', '<=', $intent['price_max']);
        }

        if ($intent['category_keywords']) {
            $keywords = $intent['category_keywords'];
            $query->where(function ($query) use ($keywords): void {
                foreach ($keywords as $keyword) {
                    $query->orWhere('name', 'like', "%{$keyword}%")
                        ->orWhere('short_description', 'like', "%{$keyword}%")
                        ->orWhereHas('category', fn ($category) => $category->where('name', 'like', "%{$keyword}%"));
                }
            });
        }

        if ($intent['brand_keywords']) {
            $brands = $intent['brand_keywords'];
            $query->whereHas('brand', fn ($brand) => $brand->where(function ($brand) use ($brands): void {
                foreach ($brands as $keyword) {
                    $brand->orWhere('name', 'like', "%{$keyword}%");
                }
            }));
        }

        return $query->orderByDesc('featured')->orderBy('price')->limit(8)->get();
    }

    private function categoryKeywords(string $text): array
    {
        return collect([
            'laptop' => ['laptop', 'лаптоп'],
            'gaming' => ['gaming', 'гейм'],
            'printer' => ['printer', 'принтер'],
            'monitor' => ['monitor', 'монитор'],
            'autocad' => ['autocad', 'architecture', 'архитект'],
            'office' => ['office', 'офис'],
        ])->filter(fn (array $words) => collect($words)->contains(fn (string $word) => str_contains($text, $word)))
            ->keys()
            ->all();
    }

    private function brandKeywords(string $text): array
    {
        return collect(['lenovo', 'hp', 'asus', 'dell', 'acer', 'samsung'])
            ->filter(fn (string $brand) => str_contains($text, $brand))
            ->values()
            ->all();
    }
}
