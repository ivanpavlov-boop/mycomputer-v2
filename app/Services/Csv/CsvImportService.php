<?php

namespace App\Services\Csv;

use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\AvailabilityStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CsvImportFailure;
use App\Models\CsvImportJob;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Services\Availability\AvailabilityStatusMapper;
use App\Services\Attributes\AttributeNormalizationService;
use App\Services\Attributes\CatalogAttributeWriter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CsvImportService
{
    public function __construct(
        private readonly CsvMappingService $mappingService,
        private readonly CsvValidationService $validationService,
        private readonly AvailabilityStatusMapper $availabilityMapper,
        private readonly AttributeNormalizationService $attributeNormalizer,
        private readonly CatalogAttributeWriter $catalogAttributeWriter,
    ) {}

    public function preview(CsvImportJob $job, int $limit = 20): array
    {
        $preview = collect($this->mappingService->readRows($job->file_path, $limit))
            ->map(function (array $row) use ($job): array {
                $mapped = $this->mappingService->mapRow($row['data'], $job->mapping, $job->type);

                return [
                    'row_number' => $row['row_number'],
                    'raw' => $row['data'],
                    'mapped' => $mapped,
                ];
            })
            ->values()
            ->all();

        $job->update([
            'status' => 'previewed',
            'preview_data' => $preview,
        ]);

        return $preview;
    }

    public function process(CsvImportJob $job): CsvImportJob
    {
        $job->failures()->delete();
        $job->update([
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'processed_rows' => 0,
            'failed_rows' => 0,
            'error_message' => null,
        ]);

        try {
            $rows = $this->mappingService->readRows($job->file_path);
            $job->update(['total_rows' => count($rows)]);

            foreach ($rows as $row) {
                $mapped = $this->mappingService->mapRow($row['data'], $job->mapping, $job->type);

                try {
                    $validated = $this->validationService->validate($job->type, $mapped, $job->mode);

                    if ($job->mode !== 'dry-run') {
                        DB::transaction(fn () => $this->applyRow($job, $validated));
                    }

                    $job->increment('processed_rows');
                } catch (ValidationException $exception) {
                    $this->recordFailure($job, $row['row_number'], 'validation', $exception->validator->errors()->toJson(), $row['data']);
                } catch (\Throwable $exception) {
                    $this->recordFailure($job, $row['row_number'], 'processing', $exception->getMessage(), $row['data']);
                }
            }

            $job->update([
                'status' => $job->fresh()->failed_rows > 0 ? 'completed_with_errors' : 'completed',
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

    public function exportFailures(CsvImportJob $job): string
    {
        $path = CsvMappingService::EXPORT_DIR.'/csv-import-'.$job->id.'-failures.csv';
        $absolutePath = storage_path('app/'.$path);
        File::ensureDirectoryExists(dirname($absolutePath));

        $handle = fopen($absolutePath, 'wb');
        fputcsv($handle, ['row_number', 'error_type', 'error_message', 'raw_data']);

        $job->failures()->orderBy('row_number')->each(function (CsvImportFailure $failure) use ($handle): void {
            fputcsv($handle, [
                $failure->row_number,
                $failure->error_type,
                $failure->error_message,
                json_encode($failure->raw_data, JSON_UNESCAPED_UNICODE),
            ]);
        });

        fclose($handle);

        return $path;
    }

    private function applyRow(CsvImportJob $job, array $row): void
    {
        match ($job->type) {
            'products' => $this->importProduct($job, $row),
            'prices' => $this->updatePrices($row),
            'stock' => $this->updateStock($row),
            'categories' => $this->importCategory($job, $row),
            'brands' => $this->importBrand($job, $row),
            'attributes' => $this->importAttribute($row),
            default => throw new \InvalidArgumentException('Unsupported CSV import type.'),
        };
    }

    private function importProduct(CsvImportJob $job, array $row): void
    {
        $product = $this->findProduct($row);

        if ($job->mode === 'update-only' && ! $product) {
            throw new \RuntimeException('Product not found for update-only import.');
        }

        if ($job->mode === 'create-only' && $product) {
            throw new \RuntimeException('Product already exists for create-only import.');
        }

        $brand = filled($row['brand'] ?? null) ? $this->resolveBrand((string) $row['brand']) : null;
        $category = filled($row['category'] ?? null) ? $this->resolveCategory((string) $row['category']) : null;
        $payload = $this->productPayload($row, $brand?->id, $category?->id);

        if ($product) {
            $product->update($payload);

            return;
        }

        Product::query()->create(array_merge([
            'sku' => $row['sku'] ?? $this->uniqueSku($row),
            'slug' => $row['slug'] ?? $this->uniqueSlug(Product::class, $row['name']),
            'name' => $row['name'],
            'price' => $row['price'] ?? 0,
            'active' => $this->toBool($row['active'] ?? false),
            'source_payload' => ['csv_import_job_id' => $job->id],
        ], $payload));
    }

    private function updatePrices(array $row): void
    {
        $product = $this->findProduct($row) ?? throw new \RuntimeException('Product not found for price import.');
        $product->update($this->onlyFilled($row, ['purchase_price', 'price', 'promo_price', 'promo_start', 'promo_end']));
    }

    private function updateStock(array $row): void
    {
        $product = $this->findProduct($row) ?? throw new \RuntimeException('Product not found for stock import.');
        $payload = $this->onlyFilled($row, ['quantity', 'stock_status', 'availability_status', 'external_availability_status', 'external_availability_label']);
        $external = $row['external_availability_status'] ?? $row['stock_status'] ?? null;
        $availability = $this->availabilityMapper->mapWithFallback('csv', null, $external, isset($row['quantity']) ? (int) $row['quantity'] : (int) $product->quantity);

        if (filled($row['availability_status'] ?? null)) {
            $availability = $this->resolveAvailabilityStatus((string) $row['availability_status']) ?? $availability;
            unset($payload['availability_status']);
        }

        if (! $product->manual_override) {
            $payload['availability_status_id'] = $availability?->id;
            $payload['stock_status'] = $availability?->code ?? ($payload['stock_status'] ?? $product->stock_status);
        }

        $product->update($payload);
    }

    private function importCategory(CsvImportJob $job, array $row): void
    {
        $parent = filled($row['parent'] ?? null) ? $this->resolveCategory((string) $row['parent']) : null;
        $slug = $row['slug'] ?? Str::slug($row['name']);
        $category = Category::query()->where('slug', $slug)->first();

        if ($job->mode === 'update-only' && ! $category) {
            throw new \RuntimeException('Category not found for update-only import.');
        }

        if ($job->mode === 'create-only' && $category) {
            throw new \RuntimeException('Category already exists for create-only import.');
        }

        Category::query()->updateOrCreate(['slug' => $slug], [
            'parent_id' => $parent?->id,
            'name' => $row['name'],
            'description' => $row['description'] ?? null,
            'meta_title' => $row['meta_title'] ?? null,
            'meta_description' => $row['meta_description'] ?? null,
            'is_active' => $this->toBool($row['is_active'] ?? true),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ]);
    }

    private function importBrand(CsvImportJob $job, array $row): void
    {
        $slug = $row['slug'] ?? Str::slug($row['name']);
        $brand = Brand::query()->where('slug', $slug)->first();

        if ($job->mode === 'update-only' && ! $brand) {
            throw new \RuntimeException('Brand not found for update-only import.');
        }

        if ($job->mode === 'create-only' && $brand) {
            throw new \RuntimeException('Brand already exists for create-only import.');
        }

        Brand::query()->updateOrCreate(['slug' => $slug], [
            'name' => $row['name'],
            'website' => $row['website'] ?? null,
            'description' => $row['description'] ?? null,
            'meta_title' => $row['meta_title'] ?? null,
            'meta_description' => $row['meta_description'] ?? null,
            'is_active' => $this->toBool($row['is_active'] ?? true),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ]);
    }

    private function importAttribute(array $row): void
    {
        $product = Product::query()->where('sku', $row['sku'])->firstOrFail();
        $raw = $this->attributeNormalizer->stageAndNormalize([
            'product_id' => $product->id,
            'source_type' => 'csv',
            'source_code' => 'csv_import',
            'raw_name' => $row['attribute_name'],
            'raw_value' => $row['attribute_value'],
            'raw_unit' => $row['unit'] ?? null,
        ]);

        if ($raw->status !== 'mapped') {
            throw new \RuntimeException('Attribute requires normalization review before catalog sync.');
        }

        $this->catalogAttributeWriter->writeMappedSupplierAttribute($raw, $product);
    }

    private function productPayload(array $row, ?int $brandId, ?int $categoryId): array
    {
        $payload = $this->onlyFilled($row, [
            'ean', 'mpn', 'name', 'slug', 'short_description', 'description', 'purchase_price',
            'price', 'promo_price', 'quantity', 'stock_status', 'availability_status_id',
            'availability_message', 'expected_date', 'supplier_lead_time_days',
            'external_availability_status', 'external_availability_label', 'warranty_months',
            'meta_title', 'meta_description', 'meta_keywords',
        ]);

        if (filled($row['external_availability_status'] ?? $row['stock_status'] ?? null) || array_key_exists('quantity', $row)) {
            $availability = $this->availabilityMapper->mapWithFallback(
                'csv',
                null,
                $row['external_availability_status'] ?? $row['stock_status'] ?? null,
                isset($row['quantity']) ? (int) $row['quantity'] : null,
            );
            $payload['availability_status_id'] = $availability?->id;
            $payload['stock_status'] = $availability?->code ?? ($payload['stock_status'] ?? null);
        }

        if (filled($row['availability_status'] ?? null)) {
            $availability = $this->resolveAvailabilityStatus((string) $row['availability_status']);

            if ($availability) {
                $payload['availability_status_id'] = $availability->id;
                $payload['stock_status'] = $availability->code;
            }
        }

        foreach (['active', 'featured', 'new_product', 'bestseller'] as $field) {
            if (array_key_exists($field, $row) && filled($row[$field])) {
                $payload[$field] = $this->toBool($row[$field]);
            }
        }

        if ($brandId) {
            $payload['brand_id'] = $brandId;
        }

        if ($categoryId) {
            $payload['category_id'] = $categoryId;
        }

        return $payload;
    }

    private function resolveAvailabilityStatus(string $value): ?AvailabilityStatus
    {
        return AvailabilityStatus::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('code', $value)
                ->orWhere('name', $value))
            ->first();
    }

    private function findProduct(array $row): ?Product
    {
        return Product::query()
            ->when(filled($row['sku'] ?? null), fn ($query) => $query->orWhere('sku', $row['sku']))
            ->when(filled($row['ean'] ?? null), fn ($query) => $query->orWhere('ean', $row['ean']))
            ->first();
    }

    private function resolveBrand(string $value): Brand
    {
        return Brand::query()->firstOrCreate(
            ['slug' => Str::slug($value)],
            ['name' => $value, 'is_active' => true],
        );
    }

    private function resolveCategory(string $value): Category
    {
        return Category::query()->firstOrCreate(
            ['slug' => Str::slug($value)],
            ['name' => $value, 'is_active' => true],
        );
    }

    private function recordFailure(CsvImportJob $job, int $rowNumber, string $type, string $message, array $rawData): void
    {
        $job->failures()->create([
            'row_number' => $rowNumber,
            'error_type' => $type,
            'error_message' => $message,
            'raw_data' => $rawData,
        ]);
        $job->increment('failed_rows');
    }

    private function onlyFilled(array $row, array $fields): array
    {
        return array_filter(Arr::only($row, $fields), fn ($value): bool => filled($value));
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? in_array($value, [1, '1', 'yes', 'да'], true);
    }

    private function uniqueSku(array $row): string
    {
        $base = Str::upper(Str::slug($row['ean'] ?? $row['mpn'] ?? $row['name'], '-'));
        $sku = $base;
        $counter = 1;

        while (Product::query()->where('sku', $sku)->exists()) {
            $sku = $base.'-'.$counter++;
        }

        return $sku;
    }

    private function uniqueSlug(string $modelClass, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $counter = 1;

        while ($modelClass::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
