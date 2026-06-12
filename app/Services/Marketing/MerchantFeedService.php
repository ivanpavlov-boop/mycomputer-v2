<?php

namespace App\Services\Marketing;

use App\Models\FeedExport;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class MerchantFeedService
{
    public function xml(): string
    {
        $items = $this->products()
            ->map(fn (Product $product): string => $this->itemXml($product))
            ->implode('');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">'
            .'<channel><title>mycomputer.bg Product Feed</title><link>'.e(url('/')).'</link><description>Google Merchant Center feed</description>'
            .$items
            .'</channel></rss>';
    }

    public function cachedXml(): string
    {
        $latest = FeedExport::query()
            ->where('feed_type', 'google_merchant')
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
        $path = "feeds/google-merchant-{$timestamp}.xml";
        $currentPath = 'feeds/google-merchant.xml';

        Storage::disk('local')->put($path, $xml);
        Storage::disk('local')->put($currentPath, $xml);

        return FeedExport::query()->create([
            'feed_type' => 'google_merchant',
            'status' => 'generated',
            'file_path' => $currentPath,
            'products_count' => $this->products()->count(),
            'generated_at' => now(),
        ]);
    }

    public function products(): Collection
    {
        return Product::query()
            ->published()
            ->with(['brand', 'category.parent', 'images'])
            ->where('stock_status', '!=', 'out_of_stock')
            ->get();
    }

    private function itemXml(Product $product): string
    {
        $images = $product->images;
        $primaryImage = $images->firstWhere('is_primary', true) ?? $images->first();
        $additionalImages = $images->filter(fn ($image) => $primaryImage && $image->id !== $primaryImage->id)->take(10);

        $fields = [
            'g:id' => $product->sku,
            'g:title' => $product->name,
            'g:description' => strip_tags((string) ($product->description ?: $product->short_description ?: $product->name)),
            'g:link' => url("/p/{$product->slug}"),
            'g:image_link' => $this->imageUrl($primaryImage?->path),
            'g:availability' => $this->availability($product),
            'g:price' => $this->money($product->price),
            'g:sale_price' => $product->promo_price ? $this->money($product->promo_price) : null,
            'g:brand' => $product->brand?->name,
            'g:gtin' => $product->ean,
            'g:mpn' => $product->mpn,
            'g:condition' => 'new',
            'g:google_product_category' => 'Electronics > Computers',
            'g:product_type' => $this->categoryPath($product),
            'g:custom_label_0' => $product->featured ? 'featured' : null,
            'g:custom_label_1' => $product->bestseller ? 'bestseller' : null,
            'g:custom_label_2' => $product->new_product ? 'new' : null,
            'g:custom_label_3' => $product->promo_price ? 'promo' : null,
            'g:custom_label_4' => $product->stock_status,
        ];

        $xml = collect($fields)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value, string $key): string => "<{$key}>".$this->cdata((string) $value)."</{$key}>")
            ->implode('');

        foreach ($additionalImages as $image) {
            $xml .= '<g:additional_image_link>'.$this->cdata($this->imageUrl($image->path)).'</g:additional_image_link>';
        }

        return '<item>'.$xml.'</item>';
    }

    protected function imageUrl(?string $path): string
    {
        if (! $path) {
            return url('/images/placeholder-product.png');
        }

        return str_starts_with($path, 'http') ? $path : url('/storage/'.$path);
    }

    protected function availability(Product $product): string
    {
        return $product->stock_status === 'in_stock' ? 'in stock' : 'out of stock';
    }

    protected function money(string|float|int|null $value): string
    {
        return number_format((float) $value, 2, '.', '').' BGN';
    }

    protected function categoryPath(Product $product): string
    {
        $path = [];
        $category = $product->category;

        while ($category) {
            array_unshift($path, $category->name);
            $category = $category->parent;
        }

        return implode(' > ', $path);
    }

    protected function cdata(string $value): string
    {
        return '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $value).']]>';
    }
}
