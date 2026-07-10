<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

class AsbisDualFeedPreviewService
{
    private const MAX_ROWS = 5000;

    private const SUPPLIER_KEY = 'asbis';

    private const ASBIS_AVAILABILITY_MAP = [
        'да' => 'in_stock',
        'ограничено' => 'limited_stock',
        'по заявка' => 'on_request',
    ];

    private const JOIN_KEY_ALIASES = [
        'product_id',
        'productid',
        'product_code',
        'productcode',
        'wic',
        'id',
        'sku',
        'code',
        'vendor_code',
        'item_code',
        'part_number',
        'mpn',
        'manufacturer_sku',
        'manufacturer_part_number',
        'manufacturerpartnumber',
    ];

    private const PRODUCT_FIELD_ALIASES = [
        'supplier_sku' => ['supplier_sku', 'product_code', 'productcode', 'product_id', 'productid', 'sku', 'code', 'item_code', 'vendor_code', 'part_number', 'mpn', 'manufacturer_sku'],
        'ean_gtin' => ['ean', 'gtin', 'barcode', 'ean13', 'upc'],
        'mpn' => ['mpn', 'manufacturer_sku', 'manufacturer_part_number', 'manufacturerpartnumber', 'part_number', 'vendor_part_number'],
        'brand' => ['vendor', 'brand', 'brand_name', 'manufacturer'],
        'name' => ['product_description', 'productdescription', 'name', 'product_name', 'title'],
        'category' => ['product_category', 'productcategory', 'category', 'category_name', 'category_path', 'categories', 'path'],
        'image_url' => ['image', 'images_image', 'image_url', 'imageurl', 'picture', 'picture_url', 'pictureurl', 'thumbnail', 'image1'],
        'description' => ['product_description', 'productdescription', 'description', 'full_description', 'long_description', 'desc'],
    ];

    private const PRICE_FIELD_ALIASES = [
        'supplier_sku' => ['wic', 'price_wic', 'supplier_sku', 'product_code', 'productcode', 'product_id', 'productid', 'sku', 'item_code', 'vendor_code', 'part_number', 'mpn', 'manufacturer_sku'],
        'ean_gtin' => ['ean', 'price_ean', 'gtin', 'barcode', 'ean13', 'upc'],
        'mpn' => ['mpn', 'manufacturer_sku', 'manufacturer_part_number', 'manufacturerpartnumber', 'part_number', 'vendor_part_number'],
        'brand' => ['vendor_name', 'price_vendor_name', 'brand', 'brand_name', 'manufacturer', 'vendor'],
        'name' => ['description', 'price_description', 'name', 'product_name', 'title'],
        'category' => ['group_name', 'price_group_name', 'category', 'category_name', 'category_path', 'categories', 'path'],
        'image_url' => ['small_image', 'smallimage', 'price_small_image', 'image', 'image_url', 'imageurl', 'picture', 'picture_url', 'pictureurl', 'thumbnail', 'image1'],
        'description' => ['description', 'price_description', 'full_description', 'long_description', 'desc'],
        'price' => ['my_price', 'price_my_price', 'price', 'supplier_price', 'dealer_price', 'cost', 'net_price', 'regular_price'],
        'retail_price' => ['retail_price', 'price_retail_price'],
        'stock' => ['avail', 'price_avail', 'stock', 'qty', 'quantity', 'available_quantity', 'inventory'],
        'availability' => ['avail', 'price_avail', 'availability', 'availability_status', 'stock_status'],
        'currency' => ['currency_code', 'price_currency_code', 'currency', 'curr'],
        'vat' => ['vat', 'vat_rate', 'tax', 'tax_rate'],
    ];

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(array $options): array
    {
        $format = (string) ($options['format'] ?? 'table');
        $limit = max(1, min((int) ($options['limit'] ?? 50), self::MAX_ROWS));
        $maxRows = max(1, min((int) ($options['max_rows'] ?? self::MAX_ROWS), self::MAX_ROWS));

        if (! in_array($format, ['table', 'json'], true)) {
            return $this->failure('unsupported_format', 'Unsupported format. Use table or json.', $limit, $maxRows);
        }

        $supplierInput = trim((string) ($options['supplier'] ?? ''));

        if ($supplierInput === '') {
            return $this->failure('supplier_required', 'The --supplier option is required.', $limit, $maxRows);
        }

        $supplier = $this->resolveSupplier($supplierInput);

        if (! $supplier instanceof Supplier) {
            return $this->failure('supplier_not_found', 'Selected supplier was not found.', $limit, $maxRows);
        }

        if ($this->supplierKey($supplier) !== self::SUPPLIER_KEY) {
            return $this->failure('supplier_must_be_asbis', 'The --supplier option must resolve to ASBIS.', $limit, $maxRows, $supplier);
        }

        $productSource = $this->resolveSource(
            (string) ($options['product_list'] ?? ''),
            (string) ($options['product_list_fixture'] ?? ''),
            'ProductList'
        );
        $priceSource = $this->resolveSource(
            (string) ($options['price_avail'] ?? ''),
            (string) ($options['price_avail_fixture'] ?? ''),
            'PriceAvail'
        );

        if (! ($productSource['success'] ?? false)) {
            return $this->failure(
                (string) ($productSource['issue'] ?? 'product_list_source_error'),
                (string) ($productSource['message'] ?? 'ProductList source could not be read.'),
                $limit,
                $maxRows,
                $supplier,
                $productSource,
                $priceSource
            );
        }

        if (! ($priceSource['success'] ?? false)) {
            return $this->failure(
                (string) ($priceSource['issue'] ?? 'price_avail_source_error'),
                (string) ($priceSource['message'] ?? 'PriceAvail source could not be read.'),
                $limit,
                $maxRows,
                $supplier,
                $productSource,
                $priceSource
            );
        }

        try {
            $productRows = $this->parseXmlRows((string) $productSource['path'])->take($maxRows)->values();
            $priceRows = $this->parseXmlRows((string) $priceSource['path'])->take($maxRows)->values();
        } catch (Throwable $exception) {
            return $this->failure(
                'parse_error',
                'Unable to parse local ASBIS preview sources: '.$exception->getMessage(),
                $limit,
                $maxRows,
                $supplier,
                $productSource,
                $priceSource
            );
        }

        $productFieldMap = $this->detectedFieldMap($productRows, self::PRODUCT_FIELD_ALIASES);
        $priceFieldMap = $this->detectedFieldMap($priceRows, self::PRICE_FIELD_ALIASES);
        $join = $this->resolveJoin($productRows, $priceRows, $options);
        $analysis = $this->analyzeRows($supplier, $productRows, $priceRows, $productFieldMap, $priceFieldMap, $join);
        $joinedRows = $analysis['rows'];
        $issues = [
            ...$join['issues'],
            ...$analysis['issues'],
        ];

        return [
            'success' => $join['confidence'] !== 'missing_join_key' || $productRows->isNotEmpty() || $priceRows->isNotEmpty(),
            'mode' => 'preview_only',
            'supplier' => $this->supplierPayload($supplier),
            'sources' => [
                'product_list' => $this->sourcePayload($productSource),
                'price_avail' => $this->sourcePayload($priceSource),
            ],
            'join' => [
                'product_key' => $join['product_key'],
                'price_key' => $join['price_key'],
                'confidence' => $join['confidence'],
                'candidate_product_keys' => $join['candidate_product_keys'],
                'candidate_price_keys' => $join['candidate_price_keys'],
                'candidate_normalized_keys' => $join['candidate_normalized_keys'],
            ],
            'summary' => $this->summary($supplier, $productSource, $priceSource, $joinedRows, $analysis, $limit, $maxRows),
            'detected_product_fields' => [
                'raw_field_names' => $this->rawFieldNames($productRows),
                'normalized_field_map' => $productFieldMap,
            ],
            'detected_price_fields' => [
                'raw_field_names' => $this->rawFieldNames($priceRows),
                'normalized_field_map' => $priceFieldMap,
            ],
            'normalized_coverage' => $this->coverage($joinedRows, [
                'supplier_sku',
                'ean_gtin',
                'mpn',
                'brand',
                'name',
                'category',
                'price',
                'stock',
                'availability',
                'currency',
            ]),
            'identifier_summary' => $this->identifierSummary($joinedRows),
            'category_summary' => $this->categorySummary($joinedRows),
            'price_stock_summary' => $this->priceStockSummary($joinedRows),
            'joined_rows' => $this->displayRows($joinedRows, $limit, (bool) ($options['show_raw_fields'] ?? false), (bool) ($options['show_normalized'] ?? false)),
            'unmatched_product_rows' => (bool) ($options['show_unmatched'] ?? false) ? $this->displayRows($analysis['unmatched_product_rows'], $limit, false, false) : [],
            'unmatched_price_rows' => (bool) ($options['show_unmatched'] ?? false) ? $this->displayRows($analysis['unmatched_price_rows'], $limit, false, false) : [],
            'overlaps' => collect($analysis['overlaps'])->take($limit)->values()->all(),
            'issues' => $issues,
            'records_changed' => $this->recordsChanged(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function recordsChanged(): array
    {
        return [
            'products' => 0,
            'supplier_products' => 0,
            'categories' => 0,
            'suppliers' => 0,
            'supplier_category_mappings' => 0,
            'canonical_product_families' => 0,
            'category_product_attributes' => 0,
            'product_attributes' => 0,
            'attribute_values' => 0,
            'product_attribute_values' => 0,
            'catalog_sync' => 0,
        ];
    }

    private function resolveSupplier(string $supplier): ?Supplier
    {
        if (! Schema::hasTable('suppliers')) {
            return null;
        }

        if (is_numeric($supplier)) {
            return Supplier::query()->whereKey((int) $supplier)->first();
        }

        $normalized = Str::lower($supplier);

        return Supplier::query()
            ->where(function (Builder $query) use ($normalized): void {
                $query
                    ->whereRaw('LOWER(slug) = ?', [$normalized])
                    ->orWhereRaw('LOWER(company_name) = ?', [$normalized]);
            })
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSource(string $source, string $fixture, string $kind): array
    {
        $candidate = trim($fixture !== '' ? $fixture : $source);

        if ($candidate === '') {
            return [
                'success' => false,
                'issue' => Str::snake($kind).'_required',
                'message' => $kind.' local file is required.',
                'label' => '-',
                'source_type' => 'xml',
            ];
        }

        if ($this->isRemoteSource($candidate)) {
            return [
                'success' => false,
                'issue' => 'remote_source_disabled',
                'message' => 'Remote feed fetching is disabled for ASBIS dual-feed preview. Provide local files.',
                'label' => $this->safeSourceLabel($candidate),
                'source_type' => 'xml',
            ];
        }

        $path = $this->absolutePath($candidate);

        if (! is_file($path)) {
            return [
                'success' => false,
                'issue' => Str::snake($kind).'_file_missing',
                'message' => $kind.' source file was not found.',
                'label' => $this->safeSourceLabel($candidate),
                'source_type' => 'xml',
            ];
        }

        return [
            'success' => true,
            'path' => $path,
            'label' => $this->safeSourceLabel($path),
            'source_type' => 'xml',
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function parseXmlRows(string $path): Collection
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return collect();
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            return collect();
        }

        $nodes = collect($xml->xpath('//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "product" or translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "item" or translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "row" or translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "price"]') ?: []);

        if ($nodes->isEmpty()) {
            $nodes = collect($xml->children());
        }

        return $nodes
            ->map(fn (SimpleXMLElement $node): array => $this->flattenXml($node))
            ->filter(fn (array $row): bool => $row !== [])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenXml(SimpleXMLElement $node, string $prefix = ''): array
    {
        $row = [];

        foreach ($node->attributes() as $key => $value) {
            $row[trim($prefix.'attr_'.$key, '.')] = trim((string) $value);
        }

        foreach ($node->children() as $key => $child) {
            $field = trim($prefix.$key, '.');

            if ($child->children()->count() > 0) {
                $row = array_merge($row, $this->flattenXml($child, $field.'.'));

                continue;
            }

            $value = trim((string) $child);
            $row[$field] = array_key_exists($field, $row) && filled($row[$field])
                ? $row[$field].' | '.$value
                : $value;
        }

        return $row;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, array<int, string>>  $fieldAliases
     * @return array<string, string|null>
     */
    private function detectedFieldMap(Collection $rows, array $fieldAliases): array
    {
        $keys = $rows
            ->flatMap(fn (array $row): array => array_keys($row))
            ->unique()
            ->values();
        $normalizedKeys = $keys->mapWithKeys(fn (string $key): array => [$this->normalizeKey($key) => $key]);

        return collect($fieldAliases)
            ->mapWithKeys(function (array $aliases, string $field) use ($normalizedKeys): array {
                foreach ($aliases as $alias) {
                    $normalizedAlias = $this->normalizeKey($alias);

                    if ($normalizedKeys->has($normalizedAlias)) {
                        return [$field => $normalizedKeys->get($normalizedAlias)];
                    }
                }

                foreach ($normalizedKeys as $normalizedKey => $rawKey) {
                    foreach ($aliases as $alias) {
                        if ($this->normalizedKeyMatchesAlias($normalizedKey, $this->normalizeKey($alias))) {
                            return [$field => $rawKey];
                        }
                    }
                }

                return [$field => null];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $productRows
     * @param  Collection<int, array<string, mixed>>  $priceRows
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function resolveJoin(Collection $productRows, Collection $priceRows, array $options): array
    {
        $candidateProductKeys = $this->candidateJoinKeys($productRows);
        $candidatePriceKeys = $this->candidateJoinKeys($priceRows);
        $issues = [];
        $productKey = null;
        $priceKey = null;
        $confidence = 'missing_join_key';
        $normalizedCandidates = [];
        $explicitProductKey = trim((string) ($options['product_key'] ?? ''));
        $explicitPriceKey = trim((string) ($options['price_key'] ?? ''));
        $joinKey = trim((string) ($options['join_key'] ?? 'auto'));

        if ($explicitProductKey !== '' || $explicitPriceKey !== '') {
            $productKey = $this->resolveRawKey($productRows, $explicitProductKey);
            $priceKey = $this->resolveRawKey($priceRows, $explicitPriceKey);
            $confidence = $productKey !== null && $priceKey !== null ? 'explicit_key_match' : 'missing_join_key';
        } elseif ($joinKey !== '' && $joinKey !== 'auto') {
            $productKey = $this->resolveRawKey($productRows, $joinKey);
            $priceKey = $this->resolveRawKey($priceRows, $joinKey);
            $confidence = $productKey !== null && $priceKey !== null ? 'explicit_key_match' : 'missing_join_key';
        } else {
            $rawMatches = collect($candidateProductKeys)
                ->intersect($candidatePriceKeys)
                ->values();

            if ($rawMatches->count() === 1) {
                $productKey = $rawMatches->first();
                $priceKey = $rawMatches->first();
                $confidence = 'exact_key_match';
            } elseif ($rawMatches->count() > 1) {
                $confidence = 'ambiguous_join_key';
                $normalizedCandidates = $rawMatches->all();
            } else {
                $productByNormalized = $this->keysByNormalized($candidateProductKeys);
                $priceByNormalized = $this->keysByNormalized($candidatePriceKeys);
                $normalizedMatches = collect(array_keys($productByNormalized))
                    ->intersect(array_keys($priceByNormalized))
                    ->values();

                if ($normalizedMatches->count() === 1) {
                    $normalized = (string) $normalizedMatches->first();
                    $productKey = $productByNormalized[$normalized][0] ?? null;
                    $priceKey = $priceByNormalized[$normalized][0] ?? null;
                    $confidence = 'inferred_key_match';
                    $normalizedCandidates = [$normalized];
                } elseif ($normalizedMatches->count() > 1) {
                    $confidence = 'ambiguous_join_key';
                    $normalizedCandidates = $normalizedMatches->all();
                }
            }

            if ($confidence === 'missing_join_key') {
                $productCodeKey = $this->resolveRawKey($productRows, 'ProductCode');
                $wicKey = $this->resolveRawKey($priceRows, 'WIC');

                if ($productCodeKey !== null && $wicKey !== null) {
                    $productKey = $productCodeKey;
                    $priceKey = $wicKey;
                    $confidence = 'inferred_key_match';
                    $normalizedCandidates = ['productcode:wic'];
                }
            }
        }

        if ($confidence === 'missing_join_key') {
            $issues[] = [
                'type' => 'join',
                'row_index' => null,
                'reason' => 'missing_join_key',
                'message' => 'Unable to find a shared ProductList and PriceAvail join key. Provide --product-key and --price-key.',
            ];
        }

        if ($confidence === 'ambiguous_join_key') {
            $issues[] = [
                'type' => 'join',
                'row_index' => null,
                'reason' => 'ambiguous_join_key',
                'message' => 'Multiple possible join keys were detected. Provide --product-key and --price-key for real use.',
            ];
        }

        return [
            'product_key' => $productKey,
            'price_key' => $priceKey,
            'confidence' => $confidence,
            'candidate_product_keys' => $candidateProductKeys,
            'candidate_price_keys' => $candidatePriceKeys,
            'candidate_normalized_keys' => $normalizedCandidates,
            'issues' => $issues,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function candidateJoinKeys(Collection $rows): array
    {
        $keys = $rows
            ->flatMap(fn (array $row): array => array_keys($row))
            ->unique()
            ->values();

        return $keys
            ->filter(fn (string $key): bool => in_array($this->normalizeKey($key), array_map(fn (string $alias): string => $this->normalizeKey($alias), self::JOIN_KEY_ALIASES), true))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function resolveRawKey(Collection $rows, string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        $normalized = $this->normalizeKey($key);

        return $rows
            ->flatMap(fn (array $row): array => array_keys($row))
            ->unique()
            ->first(fn (string $rawKey): bool => $rawKey === $key || $this->normalizeKey($rawKey) === $normalized);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, array<int, string>>
     */
    private function keysByNormalized(array $keys): array
    {
        return collect($keys)
            ->groupBy(fn (string $key): string => $this->normalizeKey($key))
            ->map(fn (Collection $values): array => $values->values()->all())
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $productRows
     * @param  Collection<int, array<string, mixed>>  $priceRows
     * @param  array<string, string|null>  $productFieldMap
     * @param  array<string, string|null>  $priceFieldMap
     * @param  array<string, mixed>  $join
     * @return array{rows: Collection<int, array<string, mixed>>, unmatched_product_rows: Collection<int, array<string, mixed>>, unmatched_price_rows: Collection<int, array<string, mixed>>, overlaps: array<int, array<string, mixed>>, issues: array<int, array<string, mixed>>, product_only_rows: int, price_only_rows: int, product_list_rows: int, price_avail_rows: int, duplicate_keys: int}
     */
    private function analyzeRows(Supplier $supplier, Collection $productRows, Collection $priceRows, array $productFieldMap, array $priceFieldMap, array $join): array
    {
        $productKey = $join['product_key'];
        $priceKey = $join['price_key'];
        $canJoin = in_array($join['confidence'], ['exact_key_match', 'inferred_key_match', 'explicit_key_match'], true)
            && is_string($productKey)
            && is_string($priceKey);

        if (! $canJoin) {
            $rows = collect();
            $issues = [];

            foreach ($productRows as $index => $productRow) {
                $rows->push($this->buildPreviewRow(
                    $supplier,
                    $index + 1,
                    null,
                    $productRow,
                    null,
                    $productFieldMap,
                    $priceFieldMap,
                    ['missing_join_key']
                ));
            }

            foreach ($priceRows as $index => $priceRow) {
                $rows->push($this->buildPreviewRow(
                    $supplier,
                    $productRows->count() + $index + 1,
                    null,
                    null,
                    $priceRow,
                    $productFieldMap,
                    $priceFieldMap,
                    ['missing_join_key']
                ));
            }

            $rows = $rows->values();

            return [
                'rows' => $rows,
                'unmatched_product_rows' => $rows->where('product_list_present', true)->where('price_avail_present', false)->values(),
                'unmatched_price_rows' => $rows->where('product_list_present', false)->where('price_avail_present', true)->values(),
                'overlaps' => [],
                'issues' => $this->issues($rows),
                'product_only_rows' => $productRows->count(),
                'price_only_rows' => $priceRows->count(),
                'product_list_rows' => $productRows->count(),
                'price_avail_rows' => $priceRows->count(),
                'duplicate_keys' => 0,
            ];
        }

        $productGroups = $this->groupByJoinKey($productRows, $productKey);
        $priceGroups = $this->groupByJoinKey($priceRows, $priceKey);
        $duplicateProductKeys = collect($productGroups)->filter(fn (Collection $group, string $key): bool => $key !== '' && $group->count() > 1)->keys()->all();
        $duplicatePriceKeys = collect($priceGroups)->filter(fn (Collection $group, string $key): bool => $key !== '' && $group->count() > 1)->keys()->all();
        $allKeys = collect(array_keys($productGroups))
            ->merge(array_keys($priceGroups))
            ->unique()
            ->values();
        $rows = collect();
        $rowIndex = 1;

        foreach ($allKeys as $key) {
            $productGroup = $productGroups[(string) $key] ?? collect();
            $priceGroup = $priceGroups[(string) $key] ?? collect();
            $productRow = $productGroup->first();
            $priceRow = $priceGroup->first();
            $preIssues = [];

            if ((string) $key === '') {
                $preIssues[] = 'missing_join_key';
            }

            if (in_array((string) $key, $duplicateProductKeys, true)) {
                $preIssues[] = 'duplicate_product_join_key';
            }

            if (in_array((string) $key, $duplicatePriceKeys, true)) {
                $preIssues[] = 'duplicate_price_join_key';
            }

            $rows->push($this->buildPreviewRow(
                $supplier,
                $rowIndex,
                (string) $key,
                is_array($productRow) ? $productRow : null,
                is_array($priceRow) ? $priceRow : null,
                $productFieldMap,
                $priceFieldMap,
                $preIssues
            ));
            $rowIndex++;
        }

        $rows = $rows->values();
        $overlaps = $this->overlaps($rows, $supplier);
        $rows = $rows->map(function (array $row) use ($overlaps): array {
            $row['overlap_count'] = collect($overlaps)
                ->where('row_index', $row['row_index'])
                ->count();

            return $row;
        });

        return [
            'rows' => $rows,
            'unmatched_product_rows' => $rows->where('product_list_present', true)->where('price_avail_present', false)->values(),
            'unmatched_price_rows' => $rows->where('product_list_present', false)->where('price_avail_present', true)->values(),
            'overlaps' => $overlaps,
            'issues' => $this->issues($rows),
            'product_only_rows' => $rows->where('product_list_present', true)->where('price_avail_present', false)->count(),
            'price_only_rows' => $rows->where('product_list_present', false)->where('price_avail_present', true)->count(),
            'product_list_rows' => $productRows->count(),
            'price_avail_rows' => $priceRows->count(),
            'duplicate_keys' => count($duplicateProductKeys) + count($duplicatePriceKeys),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, Collection<int, array<string, mixed>>>
     */
    private function groupByJoinKey(Collection $rows, string $key): array
    {
        return $rows
            ->groupBy(fn (array $row): string => $this->normalizedJoinValue($row[$key] ?? null) ?? '')
            ->all();
    }

    /**
     * @param  array<string, string|null>  $productFieldMap
     * @param  array<string, string|null>  $priceFieldMap
     * @param  array<int, string>  $preIssues
     * @return array<string, mixed>
     */
    private function buildPreviewRow(Supplier $supplier, int $rowIndex, ?string $joinKey, ?array $productRow, ?array $priceRow, array $productFieldMap, array $priceFieldMap, array $preIssues = []): array
    {
        $productListPresent = $productRow !== null;
        $priceAvailPresent = $priceRow !== null;
        $supplierSku = $this->cleanValue($this->mappedValue($productRow ?? [], $productFieldMap['supplier_sku'] ?? null))
            ?: $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['supplier_sku'] ?? null))
            ?: $joinKey;
        $priceRaw = $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['price'] ?? null))
            ?: $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['retail_price'] ?? null));
        $stockRaw = $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['stock'] ?? null));
        $availabilityRaw = $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['availability'] ?? null));
        $stockUsesAvailabilitySource = $this->sameMappedSource($priceFieldMap['stock'] ?? null, $priceFieldMap['availability'] ?? null);
        $stock = $this->integerValue($stockRaw);
        $normalizedAvailability = $this->normalizeAsbisAvailability($availabilityRaw);
        $availability = $normalizedAvailability
            ?? ($stockUsesAvailabilitySource && $this->hasValue($availabilityRaw) && ! $this->hasValue($stock)
                ? null
                : $availabilityRaw);
        $productImageUrl = $this->cleanValue($this->mappedValue($productRow ?? [], $productFieldMap['image_url'] ?? null))
            ?: $this->cleanValue($this->mappedAliasValue($productRow ?? [], ['Images.Image', 'Image', 'ImageURL']));
        $priceImageUrl = $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['image_url'] ?? null))
            ?: $this->cleanValue($this->mappedAliasValue($priceRow ?? [], ['SMALL_IMAGE', 'PRICE.SMALL_IMAGE']));
        $imageUrl = $productImageUrl ?: $priceImageUrl;
        $description = $this->cleanValue($this->mappedValue($productRow ?? [], $productFieldMap['description'] ?? null))
            ?: $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['description'] ?? null));

        $row = [
            'row_index' => $rowIndex,
            'supplier_id' => $supplier->id,
            'supplier_key' => $this->supplierKey($supplier),
            'supplier_sku' => $supplierSku,
            'product_list_key' => $productListPresent ? $joinKey : null,
            'price_avail_key' => $priceAvailPresent ? $joinKey : null,
            'ean_gtin' => $this->cleanValue($this->mappedValue($productRow ?? [], $productFieldMap['ean_gtin'] ?? null))
                ?: $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['ean_gtin'] ?? null)),
            'mpn' => $this->cleanValue($this->mappedValue($productRow ?? [], $productFieldMap['mpn'] ?? null))
                ?: $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['mpn'] ?? null)),
            'brand' => $this->cleanValue($this->mappedValue($productRow ?? [], $productFieldMap['brand'] ?? null))
                ?: $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['brand'] ?? null)),
            'name' => $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['name'] ?? null))
                ?: $this->cleanValue($this->mappedValue($productRow ?? [], $productFieldMap['name'] ?? null)),
            'category' => $this->cleanValue($this->mappedValue($productRow ?? [], $productFieldMap['category'] ?? null))
                ?: $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['category'] ?? null)),
            'price' => $this->decimalValue($priceRaw),
            'currency' => $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['currency'] ?? null)),
            'stock' => $stock,
            'availability' => $availability,
            'raw_availability' => $availabilityRaw,
            'supplier_availability_label' => $availabilityRaw,
            'vat' => $this->cleanValue($this->mappedValue($priceRow ?? [], $priceFieldMap['vat'] ?? null)),
            'image_url_present' => $this->hasValue($imageUrl),
            'image_url_host' => $this->imageHost($imageUrl),
            'description_present' => $this->hasValue($description),
            'product_list_present' => $productListPresent,
            'price_avail_present' => $priceAvailPresent,
            'raw' => [
                'product_list' => $productRow,
                'price_avail' => $priceRow,
            ],
            'issues' => [],
        ];

        $row['issues'] = $this->rowIssues($row, $preIssues, $priceRaw, $stockRaw, $availabilityRaw, $stockUsesAvailabilitySource);
        $row['same_supplier_sku_match'] = $this->sameSupplierSkuMatch($supplier, $row['supplier_sku']);
        $row['future_staging_action'] = $this->futureAction($row);
        $row['needs_manual_review'] = $row['future_staging_action'] === 'would_need_manual_review';

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $preIssues
     * @return array<int, string>
     */
    private function rowIssues(array $row, array $preIssues, ?string $priceRaw, ?string $stockRaw, ?string $availabilityRaw, bool $stockUsesAvailabilitySource): array
    {
        $issues = $preIssues;

        if (! $row['product_list_present']) {
            $issues[] = 'missing_product_data';
        }

        if (! $row['price_avail_present']) {
            $issues[] = 'missing_commercial_data';
        }

        if (! $this->hasValue($row['supplier_sku'] ?? null)) {
            $issues[] = 'missing_supplier_sku';
        }

        if (! $this->hasValue($row['name'] ?? null)) {
            $issues[] = 'missing_name';
        }

        if (! $this->hasValue($row['ean_gtin'] ?? null)) {
            $issues[] = 'missing_ean_gtin';
        }

        if (! $this->hasValue($row['ean_gtin'] ?? null) && ! $this->hasValue($row['mpn'] ?? null)) {
            $issues[] = 'missing_ean_and_mpn';
        }

        if (! $this->hasValue($row['price'] ?? null)) {
            $issues[] = 'missing_price';
        }

        if ($this->hasValue($priceRaw) && ! $this->hasValue($row['price'] ?? null)) {
            $issues[] = 'invalid_price';
        }

        if (! $this->hasValue($row['stock'] ?? null) && ! $this->hasValue($row['availability'] ?? null)) {
            $issues[] = 'missing_stock_availability';
        }

        if ($stockUsesAvailabilitySource && $this->hasValue($availabilityRaw) && ! $this->hasValue($row['stock'] ?? null) && ! $this->hasValue($row['availability'] ?? null)) {
            $issues[] = 'unknown_availability';
        }

        if (! $stockUsesAvailabilitySource && $this->hasValue($stockRaw) && ! $this->hasValue($row['stock'] ?? null)) {
            $issues[] = 'invalid_stock';
        }

        if ($row['price_avail_present'] && ! $this->hasValue($row['currency'] ?? null)) {
            $issues[] = 'missing_currency';
        }

        return array_values(array_unique($issues));
    }

    private function futureAction(array $row): string
    {
        $issues = $row['issues'] ?? [];

        if (collect($issues)->contains(fn (string $issue): bool => in_array($issue, [
            'missing_join_key',
            'duplicate_product_join_key',
            'duplicate_price_join_key',
            'missing_supplier_sku',
        ], true))) {
            return 'would_skip_row';
        }

        if ($issues !== []) {
            return 'would_need_manual_review';
        }

        return is_array($row['same_supplier_sku_match'] ?? null)
            ? 'would_update_supplier_product'
            : 'would_create_supplier_product';
    }

    private function sameSupplierSkuMatch(Supplier $supplier, mixed $supplierSku): ?array
    {
        if (! $this->hasValue($supplierSku) || ! Schema::hasTable('supplier_products')) {
            return null;
        }

        $match = SupplierProduct::query()
            ->where('supplier_id', $supplier->id)
            ->where('supplier_sku', (string) $supplierSku)
            ->select(['id', 'supplier_id', 'supplier_sku'])
            ->first();

        if (! $match instanceof SupplierProduct) {
            return null;
        }

        return [
            'type' => 'same_supplier_sku',
            'scope' => 'same_supplier',
            'confidence' => 'high',
            'supplier_product_id' => (int) $match->id,
            'supplier_id' => (int) $match->supplier_id,
            'supplier_sku' => $match->supplier_sku,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function overlaps(Collection $rows, Supplier $supplier): array
    {
        if (! Schema::hasTable('supplier_products')) {
            return [];
        }

        $existingRows = SupplierProduct::query()
            ->with('supplier:id,company_name,slug')
            ->where('supplier_id', '!=', $supplier->id)
            ->select(['id', 'supplier_id', 'supplier_sku', 'ean', 'mpn', 'brand_name'])
            ->get();

        return $rows
            ->flatMap(function (array $row) use ($existingRows): array {
                $matches = [];

                foreach ($existingRows as $existing) {
                    if ($this->sameIdentifier($existing->ean, $row['ean_gtin'] ?? null)) {
                        $matches[] = $this->overlapPayload($row, $existing, 'ean_gtin', 'high');

                        continue;
                    }

                    if ($this->sameIdentifier($existing->mpn, $row['mpn'] ?? null)) {
                        $type = $this->sameIdentifier($existing->brand_name, $row['brand'] ?? null) ? 'brand_mpn' : 'mpn';
                        $matches[] = $this->overlapPayload($row, $existing, $type, $type === 'brand_mpn' ? 'high' : 'medium');
                    }
                }

                return $matches;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function overlapPayload(array $row, SupplierProduct $existing, string $type, string $confidence): array
    {
        return [
            'row_index' => $row['row_index'],
            'type' => $type,
            'scope' => 'cross_supplier',
            'confidence' => $confidence,
            'supplier_product_id' => (int) $existing->id,
            'supplier_id' => (int) $existing->supplier_id,
            'supplier_name' => $existing->supplier?->company_name,
            'supplier_sku' => $existing->supplier_sku,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function issues(Collection $rows): array
    {
        return $rows
            ->flatMap(fn (array $row): array => collect($row['issues'] ?? [])
                ->map(fn (string $issue): array => [
                    'type' => 'row',
                    'row_index' => $row['row_index'],
                    'reason' => $issue,
                ])
                ->all())
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function displayRows(Collection $rows, int $limit, bool $showRawFields, bool $showNormalized): array
    {
        return $rows
            ->take($limit)
            ->map(function (array $row) use ($showRawFields, $showNormalized): array {
                $display = [
                    'row_index' => $row['row_index'],
                    'supplier_id' => $row['supplier_id'],
                    'supplier_key' => $row['supplier_key'],
                    'supplier_sku' => $row['supplier_sku'],
                    'product_list_key' => $row['product_list_key'],
                    'price_avail_key' => $row['price_avail_key'],
                    'ean_gtin' => $row['ean_gtin'],
                    'mpn' => $row['mpn'],
                    'brand' => $row['brand'],
                    'name' => $row['name'],
                    'category' => $row['category'],
                    'price' => $row['price'],
                    'currency' => $row['currency'],
                    'stock' => $row['stock'],
                    'availability' => $row['availability'],
                    'raw_availability' => $row['raw_availability'],
                    'supplier_availability_label' => $row['supplier_availability_label'],
                    'vat' => $row['vat'],
                    'image_url_present' => $row['image_url_present'],
                    'image_url_host' => $row['image_url_host'],
                    'description_present' => $row['description_present'],
                    'product_list_present' => $row['product_list_present'],
                    'price_avail_present' => $row['price_avail_present'],
                    'future_staging_action' => $row['future_staging_action'],
                    'needs_manual_review' => $row['needs_manual_review'],
                    'same_supplier_sku_match' => $row['same_supplier_sku_match'],
                    'overlap_count' => $row['overlap_count'] ?? 0,
                    'issues' => $row['issues'],
                ];

                if ($showRawFields) {
                    $display['raw_field_names'] = [
                        'product_list' => is_array($row['raw']['product_list'] ?? null) ? array_keys($row['raw']['product_list']) : [],
                        'price_avail' => is_array($row['raw']['price_avail'] ?? null) ? array_keys($row['raw']['price_avail']) : [],
                    ];
                }

                if ($showNormalized) {
                    $display['normalized'] = collect($display)
                        ->only([
                            'supplier_sku',
                            'ean_gtin',
                            'mpn',
                            'brand',
                            'name',
                            'category',
                            'price',
                            'currency',
                            'stock',
                            'availability',
                            'raw_availability',
                            'supplier_availability_label',
                            'vat',
                        ])
                        ->all();
                }

                return $display;
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Supplier $supplier, array $productSource, array $priceSource, Collection $rows, array $analysis, int $limit, int $maxRows): array
    {
        return [
            'supplier_id' => $supplier->id,
            'supplier_key' => $this->supplierKey($supplier),
            'supplier_name' => $supplier->company_name,
            'product_list_source_label' => $productSource['label'] ?? '-',
            'price_avail_source_label' => $priceSource['label'] ?? '-',
            'mode' => 'preview_only',
            'product_list_rows' => $analysis['product_list_rows'],
            'price_avail_rows' => $analysis['price_avail_rows'],
            'joined_rows' => $rows->where('product_list_present', true)->where('price_avail_present', true)->count(),
            'rows_returned' => min($limit, $rows->count()),
            'display_limit' => $limit,
            'max_rows' => $maxRows,
            'would_create' => $rows->where('future_staging_action', 'would_create_supplier_product')->count(),
            'would_update' => $rows->where('future_staging_action', 'would_update_supplier_product')->count(),
            'manual_review' => $rows->where('future_staging_action', 'would_need_manual_review')->count(),
            'skipped' => $rows->where('future_staging_action', 'would_skip_row')->count(),
            'product_only_rows' => $analysis['product_only_rows'],
            'price_only_rows' => $analysis['price_only_rows'],
            'duplicate_keys' => $analysis['duplicate_keys'],
            'cross_supplier_matches' => collect($analysis['overlaps'])->where('scope', 'cross_supplier')->count(),
            'catalog_sync_changed' => 0,
            'records_changed' => $this->recordsChanged(),
            'safety_status' => 'preview_only_no_writes',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $fields
     * @return array<string, array{present: int, missing: int}>
     */
    private function coverage(Collection $rows, array $fields): array
    {
        return collect($fields)
            ->mapWithKeys(fn (string $field): array => [
                $field => [
                    'present' => $rows->filter(fn (array $row): bool => $this->hasValue($row[$field] ?? null))->count(),
                    'missing' => $rows->reject(fn (array $row): bool => $this->hasValue($row[$field] ?? null))->count(),
                ],
            ])
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function identifierSummary(Collection $rows): array
    {
        return [
            'supplier_sku_present' => $rows->filter(fn (array $row): bool => $this->hasValue($row['supplier_sku'] ?? null))->count(),
            'supplier_sku_missing' => $rows->reject(fn (array $row): bool => $this->hasValue($row['supplier_sku'] ?? null))->count(),
            'ean_gtin_present' => $rows->filter(fn (array $row): bool => $this->hasValue($row['ean_gtin'] ?? null))->count(),
            'ean_gtin_missing' => $rows->reject(fn (array $row): bool => $this->hasValue($row['ean_gtin'] ?? null))->count(),
            'mpn_present' => $rows->filter(fn (array $row): bool => $this->hasValue($row['mpn'] ?? null))->count(),
            'mpn_missing' => $rows->reject(fn (array $row): bool => $this->hasValue($row['mpn'] ?? null))->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function categorySummary(Collection $rows): array
    {
        $categories = $rows
            ->pluck('category')
            ->filter(fn (mixed $category): bool => $this->hasValue($category))
            ->map(fn (mixed $category): string => (string) $category);

        return [
            'category_present' => $categories->count(),
            'category_missing' => $rows->count() - $categories->count(),
            'distinct_categories_count' => $categories->unique()->count(),
            'top_categories' => $categories->countBy()->sortDesc()->take(10)->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function priceStockSummary(Collection $rows): array
    {
        return [
            'price_present' => $rows->filter(fn (array $row): bool => $this->hasValue($row['price'] ?? null))->count(),
            'price_missing' => $rows->reject(fn (array $row): bool => $this->hasValue($row['price'] ?? null))->count(),
            'stock_present' => $rows->filter(fn (array $row): bool => $this->hasValue($row['stock'] ?? null))->count(),
            'stock_missing' => $rows->reject(fn (array $row): bool => $this->hasValue($row['stock'] ?? null))->count(),
            'availability_present' => $rows->filter(fn (array $row): bool => $this->hasValue($row['availability'] ?? null))->count(),
            'availability_missing' => $rows->reject(fn (array $row): bool => $this->hasValue($row['availability'] ?? null))->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function rawFieldNames(Collection $rows): array
    {
        return $rows
            ->flatMap(fn (array $row): array => array_keys($row))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function sourcePayload(array $source): array
    {
        return [
            'label' => $source['label'] ?? '-',
            'source_type' => $source['source_type'] ?? 'xml',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierPayload(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'key' => $this->supplierKey($supplier),
            'name' => $supplier->company_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $reason, string $message, int $limit, int $maxRows, ?Supplier $supplier = null, ?array $productSource = null, ?array $priceSource = null): array
    {
        return [
            'success' => false,
            'mode' => 'preview_only',
            'supplier' => $supplier instanceof Supplier ? $this->supplierPayload($supplier) : null,
            'sources' => [
                'product_list' => $productSource ? $this->sourcePayload($productSource) : null,
                'price_avail' => $priceSource ? $this->sourcePayload($priceSource) : null,
            ],
            'join' => [
                'product_key' => null,
                'price_key' => null,
                'confidence' => 'missing_join_key',
                'candidate_product_keys' => [],
                'candidate_price_keys' => [],
                'candidate_normalized_keys' => [],
            ],
            'summary' => [
                'supplier_id' => $supplier?->id,
                'supplier_key' => $supplier instanceof Supplier ? $this->supplierKey($supplier) : null,
                'supplier_name' => $supplier?->company_name ?? '-',
                'product_list_source_label' => $productSource['label'] ?? '-',
                'price_avail_source_label' => $priceSource['label'] ?? '-',
                'mode' => 'preview_only',
                'product_list_rows' => 0,
                'price_avail_rows' => 0,
                'joined_rows' => 0,
                'rows_returned' => 0,
                'display_limit' => $limit,
                'max_rows' => $maxRows,
                'would_create' => 0,
                'would_update' => 0,
                'manual_review' => 0,
                'skipped' => 0,
                'product_only_rows' => 0,
                'price_only_rows' => 0,
                'duplicate_keys' => 0,
                'cross_supplier_matches' => 0,
                'catalog_sync_changed' => 0,
                'records_changed' => $this->recordsChanged(),
                'safety_status' => 'failed_no_writes',
            ],
            'detected_product_fields' => ['raw_field_names' => [], 'normalized_field_map' => []],
            'detected_price_fields' => ['raw_field_names' => [], 'normalized_field_map' => []],
            'normalized_coverage' => [],
            'identifier_summary' => [],
            'category_summary' => [],
            'price_stock_summary' => [],
            'joined_rows' => [],
            'unmatched_product_rows' => [],
            'unmatched_price_rows' => [],
            'overlaps' => [],
            'issues' => [
                [
                    'type' => 'command',
                    'row_index' => null,
                    'reason' => $reason,
                    'message' => $message,
                ],
            ],
            'records_changed' => $this->recordsChanged(),
        ];
    }

    private function mappedValue(array $row, ?string $key): mixed
    {
        return $key === null ? null : ($row[$key] ?? null);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $aliases
     */
    private function mappedAliasValue(array $row, array $aliases): mixed
    {
        $normalizedKeys = collect(array_keys($row))
            ->mapWithKeys(fn (string $key): array => [$this->normalizeKey($key) => $key]);

        foreach ($aliases as $alias) {
            $rawKey = $normalizedKeys->get($this->normalizeKey($alias));

            if (is_string($rawKey) && $this->hasValue($row[$rawKey] ?? null)) {
                return $row[$rawKey];
            }
        }

        return null;
    }

    private function cleanValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeAsbisAvailability(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));

        return self::ASBIS_AVAILABILITY_MAP[$normalized] ?? null;
    }

    private function decimalValue(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(',', '.', preg_replace('/[^0-9,\.\-]/', '', $value) ?? '');

        return is_numeric($normalized) ? round((float) $normalized, 2) : null;
    }

    private function integerValue(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/[^0-9\-]/', '', $value) ?? '';

        return is_numeric($normalized) ? (int) $normalized : null;
    }

    private function sameMappedSource(?string $left, ?string $right): bool
    {
        return $left !== null && $right !== null && $left === $right;
    }

    private function imageHost(?string $url): ?string
    {
        if (! $this->hasValue($url)) {
            return null;
        }

        $host = parse_url((string) $url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function hasValue(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null;
    }

    private function normalizedIdentifier(mixed $value): ?string
    {
        if (! $this->hasValue($value)) {
            return null;
        }

        return Str::of((string) $value)->ascii()->lower()->trim()->toString();
    }

    private function normalizedJoinValue(mixed $value): ?string
    {
        return $this->normalizedIdentifier($value);
    }

    private function sameIdentifier(mixed $left, mixed $right): bool
    {
        return $this->normalizedIdentifier($left) !== null
            && $this->normalizedIdentifier($left) === $this->normalizedIdentifier($right);
    }

    private function supplierKey(Supplier $supplier): string
    {
        return Str::lower($supplier->slug ?: $supplier->company_name);
    }

    private function normalizeKey(string $key): string
    {
        return Str::of($key)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }

    private function normalizedKeyMatchesAlias(string $normalizedKey, string $normalizedAlias): bool
    {
        if ($normalizedAlias === '' || strlen($normalizedAlias) < 4) {
            return false;
        }

        return str_ends_with($normalizedKey, '_'.$normalizedAlias);
    }

    private function isRemoteSource(string $source): bool
    {
        return Str::startsWith(Str::lower($source), ['http://', 'https://']);
    }

    private function absolutePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || Str::startsWith($path, ['/', '\\\\'])) {
            return $path;
        }

        return base_path($path);
    }

    private function safeSourceLabel(string $source): string
    {
        if ($this->isRemoteSource($source)) {
            $host = parse_url($source, PHP_URL_HOST);

            return 'remote:'.($host ?: 'redacted');
        }

        return basename($source);
    }
}
