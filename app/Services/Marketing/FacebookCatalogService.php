<?php

namespace App\Services\Marketing;

use App\Models\FeedExport;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

class FacebookCatalogService extends MerchantFeedService
{
    public function xml(): string
    {
        $items = $this->products()
            ->map(fn (Product $product): string => $this->itemXml($product))
            ->implode('');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">'
            .'<channel><title>mycomputer.bg Facebook Catalog</title><link>'.e(url('/')).'</link><description>Facebook dynamic remarketing catalog</description>'
            .$items
            .'</channel></rss>';
    }

    public function cachedXml(): string
    {
        $latest = FeedExport::query()
            ->where('feed_type', 'facebook_catalog')
            ->where('status', 'generated')
            ->latest('generated_at')
            ->first();

        if ($latest?->file_path && Storage::disk('local')->exists($latest->file_path)) {
            return Storage::disk('local')->get($latest->file_path);
        }

        return Storage::disk('local')->get($this->generate()->file_path);
    }

    public function generate(): FeedExport
    {
        $xml = $this->xml();
        $timestamp = now()->format('YmdHis');
        $path = "feeds/facebook-catalog-{$timestamp}.xml";
        $currentPath = 'feeds/facebook-catalog.xml';

        Storage::disk('local')->put($path, $xml);
        Storage::disk('local')->put($currentPath, $xml);

        return FeedExport::query()->create([
            'feed_type' => 'facebook_catalog',
            'status' => 'generated',
            'file_path' => $currentPath,
            'products_count' => $this->products()->count(),
            'generated_at' => now(),
        ]);
    }

    private function itemXml(Product $product): string
    {
        $primaryImage = $product->images->firstWhere('is_primary', true) ?? $product->images->first();

        $fields = [
            'g:id' => $product->sku,
            'g:title' => $product->name,
            'g:description' => strip_tags((string) ($product->description ?: $product->short_description ?: $product->name)),
            'g:link' => url("/p/{$product->slug}"),
            'g:image_link' => $this->imageUrl($primaryImage?->path),
            'g:availability' => $this->availability($product),
            'g:price' => $this->money($product->promo_price ?: $product->price),
            'g:brand' => $product->brand?->name,
            'g:condition' => 'new',
            'g:product_type' => $this->categoryPath($product),
        ];

        return '<item>'.collect($fields)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value, string $key): string => "<{$key}>".$this->cdata((string) $value)."</{$key}>")
            ->implode('').'</item>';
    }
}
