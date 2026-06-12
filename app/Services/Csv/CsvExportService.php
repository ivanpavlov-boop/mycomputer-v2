<?php

namespace App\Services\Csv;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CsvExportJob;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

class CsvExportService
{
    public function process(CsvExportJob $job): CsvExportJob
    {
        $job->update([
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'processed_rows' => 0,
            'error_message' => null,
        ]);

        try {
            $path = CsvMappingService::EXPORT_DIR.'/csv-export-'.$job->id.'-'.$job->type.'.csv';
            $absolutePath = storage_path('app/'.$path);
            File::ensureDirectoryExists(dirname($absolutePath));

            $handle = fopen($absolutePath, 'wb');
            [$headers, $rows] = $this->rowsFor($job);

            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
                $job->increment('processed_rows');
            }
            fclose($handle);

            $job->update([
                'status' => 'completed',
                'file_path' => $path,
                'total_rows' => $job->fresh()->processed_rows,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);
        }

        return $job->fresh();
    }

    private function rowsFor(CsvExportJob $job): array
    {
        return match ($job->type) {
            'products', 'products_without_images', 'products_without_descriptions', 'active_products', 'inactive_products' => $this->productRows($job),
            'prices' => $this->priceRows($job),
            'stock' => $this->stockRows($job),
            'categories' => $this->categoryRows(),
            'brands' => $this->brandRows(),
            'attributes' => $this->attributeRows($job),
            default => throw new \InvalidArgumentException('Unsupported CSV export type.'),
        };
    }

    private function productRows(CsvExportJob $job): array
    {
        $headers = [
            'sku', 'ean', 'mpn', 'name', 'slug', 'brand', 'category', 'short_description',
            'description', 'purchase_price', 'price', 'promo_price', 'quantity', 'stock_status',
            'warranty_months', 'active', 'featured', 'new_product', 'bestseller',
            'meta_title', 'meta_description', 'meta_keywords',
        ];

        $rows = $this->filteredProducts($job)
            ->with(['brand', 'category'])
            ->get()
            ->map(fn (Product $product): array => [
                $product->sku,
                $product->ean,
                $product->mpn,
                $product->name,
                $product->slug,
                $product->brand?->name,
                $product->category?->name,
                $product->short_description,
                $product->description,
                $product->purchase_price,
                $product->price,
                $product->promo_price,
                $product->quantity,
                $product->stock_status,
                $product->warranty_months,
                (int) $product->active,
                (int) $product->featured,
                (int) $product->new_product,
                (int) $product->bestseller,
                $product->meta_title,
                $product->meta_description,
                $product->meta_keywords,
            ])
            ->all();

        return [$headers, $rows];
    }

    private function priceRows(CsvExportJob $job): array
    {
        return [
            ['sku', 'ean', 'purchase_price', 'price', 'promo_price', 'promo_start', 'promo_end'],
            $this->filteredProducts($job)->get()->map(fn (Product $product): array => [
                $product->sku,
                $product->ean,
                $product->purchase_price,
                $product->price,
                $product->promo_price,
                $product->promo_start?->toDateTimeString(),
                $product->promo_end?->toDateTimeString(),
            ])->all(),
        ];
    }

    private function stockRows(CsvExportJob $job): array
    {
        return [
            ['sku', 'ean', 'quantity', 'stock_status'],
            $this->filteredProducts($job)->get()->map(fn (Product $product): array => [
                $product->sku,
                $product->ean,
                $product->quantity,
                $product->stock_status,
            ])->all(),
        ];
    }

    private function categoryRows(): array
    {
        return [
            ['name', 'slug', 'parent', 'description', 'meta_title', 'meta_description', 'is_active', 'sort_order'],
            Category::query()->with('parent')->get()->map(fn (Category $category): array => [
                $category->name,
                $category->slug,
                $category->parent?->slug,
                $category->description,
                $category->meta_title,
                $category->meta_description,
                (int) $category->is_active,
                $category->sort_order,
            ])->all(),
        ];
    }

    private function brandRows(): array
    {
        return [
            ['name', 'slug', 'website', 'description', 'meta_title', 'meta_description', 'is_active', 'sort_order'],
            Brand::query()->get()->map(fn (Brand $brand): array => [
                $brand->name,
                $brand->slug,
                $brand->website,
                $brand->description,
                $brand->meta_title,
                $brand->meta_description,
                (int) $brand->is_active,
                $brand->sort_order,
            ])->all(),
        ];
    }

    private function attributeRows(CsvExportJob $job): array
    {
        return [
            ['sku', 'attribute_group', 'attribute_name', 'attribute_value', 'unit', 'is_filterable'],
            ProductAttributeValue::query()
                ->whereHas('product', fn (Builder $query) => $this->applyProductFilters($query, $job->filters ?? []))
                ->with(['product', 'attribute.group', 'value'])
                ->get()
                ->map(fn (ProductAttributeValue $assignment): array => [
                    $assignment->product?->sku,
                    $assignment->attribute?->group?->name,
                    $assignment->attribute?->name,
                    $assignment->value?->value ?? $assignment->custom_value,
                    $assignment->attribute?->unit,
                    (int) $assignment->is_filterable,
                ])
                ->all(),
        ];
    }

    private function filteredProducts(CsvExportJob $job): Builder
    {
        $query = Product::query();
        $filters = $job->filters ?? [];

        if ($job->type === 'products_without_images') {
            $query->doesntHave('images');
        }

        if ($job->type === 'products_without_descriptions') {
            $query->where(fn (Builder $query) => $query->whereNull('description')->orWhere('description', ''));
        }

        if ($job->type === 'active_products') {
            $query->where('active', true);
        }

        if ($job->type === 'inactive_products') {
            $query->where('active', false);
        }

        return $this->applyProductFilters($query, $filters);
    }

    private function applyProductFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(filled($filters['category_id'] ?? null), fn (Builder $query) => $query->where('category_id', $filters['category_id']))
            ->when(filled($filters['brand_id'] ?? null), fn (Builder $query) => $query->where('brand_id', $filters['brand_id']))
            ->when(filled($filters['stock_status'] ?? null), fn (Builder $query) => $query->where('stock_status', $filters['stock_status']))
            ->when(array_key_exists('active', $filters) && filled($filters['active']), fn (Builder $query) => $query->where('active', $this->toBool($filters['active'])))
            ->when(array_key_exists('featured', $filters) && filled($filters['featured']), fn (Builder $query) => $query->where('featured', $this->toBool($filters['featured'])))
            ->when(filled($filters['supplier_id'] ?? null), fn (Builder $query) => $query->where('supplier_id', $filters['supplier_id']))
            ->when(filled($filters['created_from'] ?? null), fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['created_from']))
            ->when(filled($filters['created_until'] ?? null), fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['created_until']))
            ->when(filled($filters['updated_from'] ?? null), fn (Builder $query) => $query->whereDate('updated_at', '>=', $filters['updated_from']))
            ->when(filled($filters['updated_until'] ?? null), fn (Builder $query) => $query->whereDate('updated_at', '<=', $filters['updated_until']));
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
