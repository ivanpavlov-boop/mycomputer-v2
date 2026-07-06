<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Products\ProductSpecificationQualityResult;
use App\Services\Products\ProductSpecificationQualityService;
use Illuminate\Console\Command;

class AuditProductSpecificationQuality extends Command
{
    protected $signature = 'products:audit-specification-quality
        {--limit= : Optional maximum number of products to check}';

    protected $description = 'Read-only audit of product specification data quality.';

    public function handle(ProductSpecificationQualityService $quality): int
    {
        $limit = $this->option('limit');
        $query = Product::query()
            ->with('category.parent')
            ->orderBy('id');

        if (filled($limit)) {
            $query->limit(max(1, (int) $limit));
        }

        $products = $query->get();
        $missingProducts = 0;
        $noTemplateProducts = 0;
        $topMissing = [];

        foreach ($products as $product) {
            $result = $quality->evaluate($product);

            if ($result->status === ProductSpecificationQualityResult::STATUS_NO_CATEGORY_TEMPLATE) {
                $noTemplateProducts++;
            }

            if ($result->missingCount > 0) {
                $missingProducts++;
            }

            foreach ($result->missingAttributeLabels() as $label) {
                $topMissing[$label] = ($topMissing[$label] ?? 0) + 1;
            }
        }

        arsort($topMissing);

        $this->info('Product specification quality audit');
        $this->line('Total products checked: '.$products->count());
        $this->line('Products with missing important specs: '.$missingProducts);
        $this->line('Products without category templates: '.$noTemplateProducts);
        $this->line('Top missing attributes:');

        if ($topMissing === []) {
            $this->line('- none');
        } else {
            foreach (array_slice($topMissing, 0, 10, preserve_keys: true) as $label => $count) {
                $this->line(sprintf('- %s: %d', $label, $count));
            }
        }

        $this->line('products changed: 0');
        $this->line('supplier_products changed: 0');
        $this->line('product_attribute_values changed: 0');
        $this->line('category_product_attributes changed: 0');

        return self::SUCCESS;
    }
}
