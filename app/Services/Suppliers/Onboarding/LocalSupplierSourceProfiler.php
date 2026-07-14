<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierFeedProfileDraft;
use App\Data\Suppliers\Onboarding\SupplierLocalSourceProfileReport;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use XMLReader;

final class LocalSupplierSourceProfiler
{
    private const MAX_PATHS = 5000;

    private const MAX_TEXT_LENGTH = 2048;

    private const MAX_DIAGNOSTIC_FIELDS = 64;

    private const MAX_DIAGNOSTIC_VALUES_PER_FIELD = 1000;

    /** @var array<string, bool> */
    private array $globalFlags;

    public function __construct()
    {
        $this->globalFlags = [
            'catalog_sync_create_enabled' => (bool) config('catalog_sync.create_enabled', true),
            'catalog_sync_update_enabled' => (bool) config('catalog_sync.update_enabled', false),
            'catalog_sync_sync_all_enabled' => (bool) config('catalog_sync.sync_all_enabled', false),
            'catalog_sync_auto_enabled' => (bool) config('catalog_sync.auto_enabled', false),
        ];
    }

    /** @param array<string, mixed> $options */
    public function profile(array $options): SupplierLocalSourceProfileReport
    {
        $startedAt = microtime(true);
        $supplier = $this->supplierKey($options['supplier'] ?? null);
        $flags = $this->globalFlags;

        if (! $this->safeConfiguration($flags)) {
            return $this->failureReport($supplier, 'unsafe_configuration', ['unsafe_configuration'], $startedAt);
        }

        $sourceFormat = strtolower(trim((string) ($options['source_format'] ?? 'xml')));

        if ($sourceFormat !== 'xml') {
            return $this->failureReport($supplier, 'invalid_local_source', ['source_format_unsupported'], $startedAt);
        }

        $source = $this->validateLocalSource($options['source'] ?? null);

        if ($source === null) {
            return $this->failureReport($supplier, 'invalid_local_source', ['invalid_local_source'], $startedAt);
        }

        $sourceSha256 = strtolower((string) hash_file('sha256', $source));
        $expectedSha256 = $this->optionalSha256($options['expected_sha256'] ?? null);
        $shaMatches = $expectedSha256 === null || hash_equals($expectedSha256, $sourceSha256);

        $sourceMetadata = [
            'supplier' => $supplier,
            'source_format' => $sourceFormat,
            'file_name' => basename($source),
            'file_size_bytes' => (int) filesize($source),
            'xml_declaration_present' => $this->xmlDeclaration($source)['present'],
            'detected_encoding' => $this->xmlDeclaration($source)['encoding'],
        ];

        if (! $shaMatches) {
            return $this->failureReport(
                $supplier,
                'source_fingerprint_mismatch',
                ['source_fingerprint_mismatch'],
                $startedAt,
                array_merge($sourceMetadata, ['source_format' => $sourceFormat]),
                [
                    'sha256' => $sourceSha256,
                    'expected_sha256' => $expectedSha256,
                    'matches' => false,
                ],
            );
        }

        try {
            $structure = $this->scanStructure($source);
            $recordPath = $this->selectRecordPath(
                $structure['root_path'],
                $structure['path_counts'],
                $options['record_path'] ?? null,
            );
            $recordScan = $this->scanRecords(
                $source,
                $recordPath,
                (bool) ($options['include_value_diagnostics'] ?? false),
            );
        } catch (RuntimeException) {
            return $this->failureReport(
                $supplier,
                'invalid_local_source',
                ['malformed_xml'],
                $startedAt,
                $sourceMetadata,
                [
                    'sha256' => $sourceSha256,
                    'expected_sha256' => $expectedSha256,
                    'matches' => true,
                ],
                ['parser_error' => 'malformed_xml'],
            );
        }

        $roles = $this->likelyRoles($recordScan['fields']);
        $draft = new SupplierFeedProfileDraft(
            supplierKey: $supplier,
            sourceFormat: $sourceFormat,
            sourceSha256: $sourceSha256,
            recordPath: $recordPath,
            proposedSkuPath: $roles['sku']['path'] ?? null,
            proposedEanPath: $roles['ean']['path'] ?? null,
            proposedMpnPath: $roles['mpn']['path'] ?? null,
            proposedNamePath: $roles['name']['path'] ?? null,
            proposedBrandPath: $roles['brand']['path'] ?? null,
            proposedCategoryPath: $roles['category']['path'] ?? null,
            proposedPricePath: $roles['price']['path'] ?? null,
            proposedCurrencyPath: $roles['currency']['path'] ?? null,
            proposedQuantityPath: $roles['quantity']['path'] ?? null,
            proposedAvailabilityPath: $roles['availability']['path'] ?? null,
            proposedImagePaths: $roles['image_paths'],
            confidenceScores: collect($roles)
                ->except(['image_paths'])
                ->mapWithKeys(fn (array $role, string $name): array => [$name => (float) ($role['confidence'] ?? 0)])
                ->all(),
            unresolvedFields: collect(['sku', 'ean', 'mpn', 'name', 'price', 'currency', 'quantity', 'availability', 'brand', 'category'])
                ->reject(fn (string $role): bool => filled($roles[$role]['path'] ?? null))
                ->values()
                ->all(),
            profileBlockers: $recordScan['record_count'] === 0 ? ['no_records_detected'] : [],
            profileWarnings: $recordScan['fields'] === [] ? ['no_scalar_fields_detected'] : [],
        );

        $blockers = $draft->profileBlockers;
        $warnings = $draft->profileWarnings;

        if ($expectedSha256 === null) {
            $warnings[] = 'source_fingerprint_not_pinned';
        }

        if ($draft->unresolvedFields !== []) {
            $warnings[] = 'fields_require_human_review';
        }

        $verdict = $blockers === [] ? 'source_profile_complete' : 'source_profile_incomplete';

        return new SupplierLocalSourceProfileReport(
            mode: 'local_source_profile',
            sourceFingerprint: [
                'sha256' => $sourceSha256,
                'expected_sha256' => $expectedSha256,
                'matches' => $shaMatches,
            ],
            sourceMetadata: $sourceMetadata,
            parserResult: [
                'xml_declaration_present' => $sourceMetadata['xml_declaration_present'],
                'detected_encoding' => $sourceMetadata['detected_encoding'],
                'root_element' => $structure['root_path'],
                'candidate_repeating_record_paths' => $this->candidatePaths($structure['root_path'], $structure['path_counts']),
                'selected_record_path' => $recordPath,
                'total_record_count' => $recordScan['record_count'],
                'full_file_parse_completed' => true,
                'malformed_xml' => false,
                'maximum_nesting_depth' => $structure['max_depth'],
            ],
            recordPathAnalysis: [
                'explicit_record_path' => $this->normalizePath($options['record_path'] ?? null),
                'selection_reason' => filled($options['record_path'] ?? null) ? 'explicit_option' : 'most_repeated_root_child',
                'candidate_paths' => $this->candidatePaths($structure['root_path'], $structure['path_counts']),
            ],
            fieldInventory: [
                'record_count' => $recordScan['record_count'],
                'fields' => $recordScan['fields'],
                'value_diagnostics' => $recordScan['value_diagnostics'],
                'value_diagnostics_meta' => $recordScan['value_diagnostics_meta'],
            ],
            likelyFieldRoles: $roles,
            feedProfileDraft: $draft->toArray(),
            verdict: $verdict,
            blockers: $blockers,
            warnings: array_values(array_unique($warnings)),
            issueCounts: $this->issueCounts($blockers, $warnings),
            issues: $this->issues($blockers, $warnings),
            recordsBefore: $this->zeroRecordsChanged(),
            recordsAfter: $this->zeroRecordsChanged(),
            recordsChanged: $this->zeroRecordsChanged(),
            elapsedSeconds: $this->elapsedSeconds($startedAt),
            peakMemoryBytes: memory_get_peak_usage(true),
        );
    }

    /** @param array<string, bool> $flags */
    private function safeConfiguration(array $flags): bool
    {
        return $flags === [
            'catalog_sync_create_enabled' => true,
            'catalog_sync_update_enabled' => false,
            'catalog_sync_sync_all_enabled' => false,
            'catalog_sync_auto_enabled' => false,
        ];
    }

    private function supplierKey(mixed $value): string
    {
        $supplier = trim((string) $value);

        if ($supplier === '') {
            throw new InvalidArgumentException('supplier_required');
        }

        return Str::lower($supplier);
    }

    private function optionalSha256(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));

        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('invalid_expected_sha256');
        }

        return $value;
    }

    private function validateLocalSource(mixed $value): ?string
    {
        $source = trim((string) $value);

        if ($source === '' || str_contains($source, "\0")) {
            return null;
        }

        if (preg_match('/^(https?|ftp|data|php|file|phar|zip):/i', $source) === 1) {
            return null;
        }

        $isWindowsPath = preg_match('/^[a-zA-Z]:/', $source) === 1;

        if (! $isWindowsPath && preg_match('/^[a-z][a-z0-9+.-]*:/i', $source) === 1) {
            return null;
        }

        if (is_link($source) || ! is_file($source)) {
            return null;
        }

        $realPath = realpath($source);

        return $realPath !== false && is_file($realPath) ? $realPath : null;
    }

    /** @return array{present: bool, encoding: ?string} */
    private function xmlDeclaration(string $source): array
    {
        $handle = fopen($source, 'rb');
        $prefix = $handle === false ? '' : (string) fread($handle, 2048);

        if (is_resource($handle)) {
            fclose($handle);
        }

        preg_match('/<\?xml\s+[^>]*encoding=["\']([^"\']+)["\']/i', $prefix, $matches);

        return [
            'present' => preg_match('/<\?xml\b/i', $prefix) === 1,
            'encoding' => $matches[1] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function scanStructure(string $source): array
    {
        $errors = [];
        libxml_use_internal_errors(true);
        $reader = new XMLReader;

        if (! $reader->open($source, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            libxml_clear_errors();
            throw new RuntimeException('xml_open_failed');
        }

        $stack = [];
        $pathCounts = [];
        $rootPath = null;
        $maxDepth = 0;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                while (count($stack) > $reader->depth) {
                    array_pop($stack);
                }

                if ($stack !== []) {
                    $stack[count($stack) - 1]['has_child'] = true;
                }

                $name = $reader->localName ?: $reader->name;
                $path = $this->appendPath($stack, $name);
                $stack[] = ['name' => $name, 'path' => $path, 'has_child' => false, 'text' => ''];
                $pathCounts[$path] = ($pathCounts[$path] ?? 0) + 1;
                $rootPath ??= $path;
                $maxDepth = max($maxDepth, count($stack));

                if ($reader->isEmptyElement) {
                    array_pop($stack);
                }
            } elseif (in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE], true) && $stack !== []) {
                $index = count($stack) - 1;
                $stack[$index]['text'] .= substr((string) $reader->value, 0, self::MAX_TEXT_LENGTH);
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $stack !== []) {
                $frame = array_pop($stack);
                unset($frame);
            }
        }

        foreach (libxml_get_errors() as $error) {
            $errors[] = $error->code;
        }

        libxml_clear_errors();
        $reader->close();

        if ($errors !== []) {
            throw new RuntimeException('malformed_xml');
        }

        return [
            'root_path' => $rootPath ?? '',
            'path_counts' => $pathCounts,
            'max_depth' => $maxDepth,
        ];
    }

    private function selectRecordPath(string $rootPath, array $pathCounts, mixed $explicit): ?string
    {
        $normalizedExplicit = $this->normalizePath($explicit);

        if ($normalizedExplicit !== null) {
            return $normalizedExplicit;
        }

        $candidates = $this->candidatePaths($rootPath, $pathCounts);

        return $candidates[0] ?? ($rootPath !== '' ? $rootPath : null);
    }

    /** @return array<int, string> */
    private function candidatePaths(string $rootPath, array $pathCounts): array
    {
        $rootDepth = $rootPath === '' ? 0 : substr_count($rootPath, '.') + 1;
        $direct = [];
        $repeated = [];

        foreach ($pathCounts as $path => $count) {
            if ($count < 2 || $path === $rootPath) {
                continue;
            }

            $depth = substr_count($path, '.') + 1;
            $candidate = ['path' => $path, 'count' => $count, 'depth' => $depth];
            $repeated[] = $candidate;

            if ($depth === $rootDepth + 1) {
                $direct[] = $candidate;
            }
        }

        $candidates = $direct !== [] ? $direct : $repeated;
        usort($candidates, fn (array $left, array $right): int => ($right['count'] <=> $left['count']) ?: strcmp($left['path'], $right['path']));

        return array_values(array_map(fn (array $candidate): string => $candidate['path'], array_slice($candidates, 0, 20)));
    }

    /** @return array{record_count: int, fields: array<string, array<string, mixed>>, value_diagnostics: array<string, array<string, mixed>>, value_diagnostics_meta: array<string, mixed>} */
    private function scanRecords(string $source, ?string $recordPath, bool $includeValueDiagnostics = false): array
    {
        if ($recordPath === null || $recordPath === '') {
            return [
                'record_count' => 0,
                'fields' => [],
                'value_diagnostics' => [],
                'value_diagnostics_meta' => $this->valueDiagnosticsMeta($includeValueDiagnostics),
            ];
        }

        $errors = [];
        libxml_use_internal_errors(true);
        $reader = new XMLReader;

        if (! $reader->open($source, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            libxml_clear_errors();
            throw new RuntimeException('xml_open_failed');
        }

        $stack = [];
        $recordCount = 0;
        $recordDepth = null;
        $fields = [];
        $recordFields = [];
        $valueDiagnostics = [];
        $valueDiagnosticsMeta = $this->valueDiagnosticsMeta($includeValueDiagnostics);
        $normalizedRecordPath = $this->normalizeComparablePath($recordPath);

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                while (count($stack) > $reader->depth) {
                    array_pop($stack);
                }

                if ($stack !== []) {
                    $stack[count($stack) - 1]['has_child'] = true;
                }

                $name = $reader->localName ?: $reader->name;
                $path = $this->appendPath($stack, $name);
                $stack[] = ['name' => $name, 'path' => $path, 'has_child' => false, 'text' => ''];
                $comparablePath = $this->normalizeComparablePath($path);

                if ($comparablePath === $normalizedRecordPath) {
                    $recordCount++;
                    $recordDepth = count($stack);
                    $recordFields = [];
                }

                if ($reader->isEmptyElement) {
                    $frame = array_pop($stack);
                    if ($recordDepth !== null && $this->isRecordField($frame['path'], $recordPath)) {
                        $this->recordField($fields, $recordFields, $valueDiagnostics, $valueDiagnosticsMeta, $includeValueDiagnostics, $recordPath, $frame['path'], '');
                    }
                }
            } elseif (in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE], true) && $stack !== []) {
                $index = count($stack) - 1;
                $stack[$index]['text'] .= substr((string) $reader->value, 0, self::MAX_TEXT_LENGTH);
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $stack !== []) {
                $frame = array_pop($stack);

                if ($frame['has_child'] === false && $recordDepth !== null && $this->isRecordField($frame['path'], $recordPath)) {
                    $this->recordField($fields, $recordFields, $valueDiagnostics, $valueDiagnosticsMeta, $includeValueDiagnostics, $recordPath, $frame['path'], trim((string) $frame['text']));
                }

                if ($recordDepth !== null && count($stack) < $recordDepth) {
                    $recordDepth = null;
                    $recordFields = [];
                }
            }
        }

        foreach (libxml_get_errors() as $error) {
            $errors[] = $error->code;
        }

        libxml_clear_errors();
        $reader->close();

        if ($errors !== []) {
            throw new RuntimeException('malformed_xml');
        }

        foreach ($fields as &$field) {
            $field['missing_count'] = max(0, $recordCount - (int) $field['presence_count']);
            unset($field['sample_values']);
        }
        unset($field);

        ksort($fields);

        return [
            'record_count' => $recordCount,
            'fields' => $fields,
            'value_diagnostics' => $includeValueDiagnostics ? $this->finalizeValueDiagnostics($valueDiagnostics) : [],
            'value_diagnostics_meta' => $valueDiagnosticsMeta,
        ];
    }

    /** @param array<int, array<string, mixed>> $stack */
    private function appendPath(array $stack, string $name): string
    {
        $path = array_map(fn (array $frame): string => (string) $frame['name'], $stack);
        $path[] = $name;

        return implode('.', $path);
    }

    private function isRecordField(string $path, string $recordPath): bool
    {
        $prefix = rtrim($recordPath, '.').'.';

        return str_starts_with($this->normalizeComparablePath($path), $this->normalizeComparablePath($prefix));
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function recordField(array &$fields, array &$recordFields, array &$valueDiagnostics, array &$valueDiagnosticsMeta, bool $includeValueDiagnostics, string $recordPath, string $path, string $value): void
    {
        $relative = str_ireplace(rtrim($recordPath, '.').'.', '', $path);
        $relative = trim((string) $relative, '.');

        if ($relative === '' || count($fields) >= self::MAX_PATHS && ! isset($fields[$relative])) {
            return;
        }

        $recordFields[$relative] = true;
        $field = $fields[$relative] ?? [
            'path' => $relative,
            'presence_count' => 0,
            'missing_count' => 0,
            'blank_count' => 0,
            'maximum_value_length' => 0,
            'likely_scalar_types' => [],
        ];
        $field['presence_count']++;
        if ($value === '') {
            $field['blank_count']++;
        }
        $field['maximum_value_length'] = max((int) $field['maximum_value_length'], strlen($value));
        $type = $this->scalarType($value);
        $field['likely_scalar_types'][$type] = ($field['likely_scalar_types'][$type] ?? 0) + 1;
        $fields[$relative] = $field;

        if ($includeValueDiagnostics) {
            $this->recordValueDiagnostic($valueDiagnostics, $valueDiagnosticsMeta, $relative, $value);
        }
    }

    /** @param array<string, array<string, mixed>> $diagnostics */
    private function recordValueDiagnostic(array &$diagnostics, array &$meta, string $path, string $value): void
    {
        if (! isset($diagnostics[$path]) && count($diagnostics) >= self::MAX_DIAGNOSTIC_FIELDS) {
            $meta['field_limit_reached'] = true;

            return;
        }

        $diagnostic = $diagnostics[$path] ?? [
            'non_blank_count' => 0,
            'numeric_count' => 0,
            'digits_only_count' => 0,
            'negative_numeric_count' => 0,
            'zero_numeric_count' => 0,
            'value_diagnostics_truncated' => false,
            'exact_hashes' => [],
            'case_normalized_hashes' => [],
            'whitespace_normalized_hashes' => [],
        ];

        if ($value === '') {
            $diagnostics[$path] = $diagnostic;

            return;
        }

        $diagnostic['non_blank_count']++;
        $diagnostic['digits_only_count'] += preg_match('/^\d+$/D', $value) === 1 ? 1 : 0;
        $numeric = str_replace(',', '.', trim($value));
        $diagnostic['numeric_count'] += is_numeric($numeric) ? 1 : 0;
        $diagnostic['negative_numeric_count'] += is_numeric($numeric) && (float) $numeric < 0 ? 1 : 0;
        $diagnostic['zero_numeric_count'] += is_numeric($numeric) && (float) $numeric === 0.0 ? 1 : 0;

        $exact = hash('sha256', $value);
        $caseNormalized = hash('sha256', $this->normalizeDiagnosticValue($value, true, false));
        $whitespaceNormalized = hash('sha256', $this->normalizeDiagnosticValue($value, true, true));

        if (! isset($diagnostic['exact_hashes'][$exact]) && count($diagnostic['exact_hashes']) >= self::MAX_DIAGNOSTIC_VALUES_PER_FIELD) {
            $diagnostic['value_diagnostics_truncated'] = true;
            $diagnostics[$path] = $diagnostic;

            return;
        }

        foreach ([
            'exact_hashes' => $exact,
            'case_normalized_hashes' => $caseNormalized,
            'whitespace_normalized_hashes' => $whitespaceNormalized,
        ] as $key => $hash) {
            $diagnostic[$key][$hash] = ($diagnostic[$key][$hash] ?? 0) + 1;
        }

        $diagnostics[$path] = $diagnostic;
    }

    /** @param array<string, array<string, mixed>> $diagnostics @return array<string, array<string, mixed>> */
    private function finalizeValueDiagnostics(array $diagnostics): array
    {
        ksort($diagnostics);

        foreach ($diagnostics as &$diagnostic) {
            foreach ([
                'exact_hashes' => 'exact_duplicate_groups',
                'case_normalized_hashes' => 'case_normalized_duplicate_groups',
                'whitespace_normalized_hashes' => 'whitespace_normalized_duplicate_groups',
            ] as $hashKey => $resultKey) {
                $duplicates = array_filter($diagnostic[$hashKey], fn (int $count): bool => $count > 1);
                $diagnostic[$resultKey] = [
                    'group_count' => count($duplicates),
                    'duplicate_row_count' => array_sum($duplicates),
                ];
                unset($diagnostic[$hashKey]);
            }
        }
        unset($diagnostic);

        return $diagnostics;
    }

    private function normalizeDiagnosticValue(string $value, bool $caseInsensitive, bool $collapseWhitespace): string
    {
        $value = trim((string) (preg_replace('/^\s+|\s+$/u', '', $value) ?? $value));

        if (class_exists('Normalizer')) {
            /** @var class-string $normalizer */
            $normalizer = 'Normalizer';
            $value = $normalizer::normalize($value, $normalizer::FORM_C) ?: $value;
        }

        if ($collapseWhitespace) {
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        }

        return $caseInsensitive ? Str::lower($value) : $value;
    }

    /** @return array<string, bool|int> */
    private function valueDiagnosticsMeta(bool $enabled): array
    {
        return [
            'enabled' => $enabled,
            'max_fields' => self::MAX_DIAGNOSTIC_FIELDS,
            'max_distinct_values_per_field' => self::MAX_DIAGNOSTIC_VALUES_PER_FIELD,
            'field_limit_reached' => false,
        ];
    }

    private function normalizePath(mixed $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $path = trim(str_replace('/', '.', (string) $path), '.');

        return $path === '' ? null : $path;
    }

    private function normalizeComparablePath(string $path): string
    {
        return Str::lower(trim(str_replace('/', '.', $path), '.'));
    }

    private function scalarType(string $value): string
    {
        if ($value === '') {
            return 'string';
        }

        if (preg_match('/^(true|false|yes|no)$/i', $value) === 1) {
            return 'boolean';
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return 'integer';
        }

        if (preg_match('/^-?\d+[.,]\d+$/', $value) === 1) {
            return 'decimal';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return 'date';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value) === 1) {
            return 'datetime';
        }

        if (preg_match('/^(https?|ftp):\/\//i', $value) === 1) {
            return 'url_like';
        }

        return 'string';
    }

    /** @param array<string, array<string, mixed>> $fields @return array<string, mixed> */
    private function likelyRoles(array $fields): array
    {
        $roles = [];
        $imagePaths = [];

        foreach ($fields as $path => $field) {
            $lower = Str::lower($path);
            foreach ([
                'sku' => ['sku', 'productcode', 'itemcode', 'partnumber', 'stockcode'],
                'ean' => ['ean', 'gtin', 'barcode'],
                'mpn' => ['mpn', 'manufacturerpart', 'partnumber'],
                'name' => ['name', 'title', 'description', 'productname'],
                'price' => ['price', 'cost', 'retail', 'msrp'],
                'currency' => ['currency', 'currencycode', 'curr'],
                'quantity' => ['quantity', 'qty', 'stock', 'inventory'],
                'availability' => ['availability', 'available', 'stockstatus', 'status'],
                'brand' => ['brand', 'manufacturer', 'vendor'],
                'category' => ['category', 'group', 'department', 'type'],
            ] as $role => $needles) {
                $score = $this->roleScore($lower, $needles);

                if ($score > (float) ($roles[$role]['confidence'] ?? 0)) {
                    $roles[$role] = ['path' => $path, 'confidence' => $score];
                }
            }

            if (preg_match('/image|picture|photo|thumbnail/i', $path) === 1) {
                $imagePaths[] = $path;
            }
        }

        $roles['image_paths'] = array_values(array_unique(array_slice($imagePaths, 0, 20)));

        return $roles;
    }

    /** @param array<int, string> $needles */
    private function roleScore(string $path, array $needles): float
    {
        $segments = preg_split('/[.\-_\\s]+/', $path) ?: [];
        $last = (string) end($segments);
        $best = 0.0;

        foreach ($needles as $needle) {
            if ($last === $needle) {
                $best = max($best, 1.0);
            } elseif (str_contains($last, $needle)) {
                $best = max($best, 0.8);
            } elseif (str_contains($path, $needle)) {
                $best = max($best, 0.5);
            }
        }

        return $best;
    }

    private function failureReport(
        string $supplier,
        string $verdict,
        array $blockers,
        float $startedAt,
        array $sourceMetadata = [],
        array $sourceFingerprint = [],
        array $parserResult = [],
    ): SupplierLocalSourceProfileReport {
        $warnings = [];

        return new SupplierLocalSourceProfileReport(
            mode: 'local_source_profile',
            sourceFingerprint: $sourceFingerprint,
            sourceMetadata: array_merge(['supplier' => $supplier], $sourceMetadata),
            parserResult: array_merge(['full_file_parse_completed' => false, 'malformed_xml' => in_array('malformed_xml', $blockers, true)], $parserResult),
            recordPathAnalysis: [],
            fieldInventory: ['record_count' => 0, 'fields' => []],
            likelyFieldRoles: [],
            feedProfileDraft: (new SupplierFeedProfileDraft(
                supplierKey: $supplier,
                sourceFormat: 'xml',
                sourceSha256: (string) ($sourceFingerprint['sha256'] ?? ''),
                recordPath: null,
                proposedSkuPath: null,
                proposedEanPath: null,
                proposedMpnPath: null,
                proposedNamePath: null,
                proposedBrandPath: null,
                proposedCategoryPath: null,
                proposedPricePath: null,
                proposedCurrencyPath: null,
                proposedQuantityPath: null,
                proposedAvailabilityPath: null,
                proposedImagePaths: [],
                confidenceScores: [],
                unresolvedFields: [],
                profileBlockers: $blockers,
                profileWarnings: [],
            ))->toArray(),
            verdict: $verdict,
            blockers: $blockers,
            warnings: $warnings,
            issueCounts: $this->issueCounts($blockers, $warnings),
            issues: $this->issues($blockers, $warnings),
            recordsBefore: $this->zeroRecordsChanged(),
            recordsAfter: $this->zeroRecordsChanged(),
            recordsChanged: $this->zeroRecordsChanged(),
            elapsedSeconds: $this->elapsedSeconds($startedAt),
            peakMemoryBytes: memory_get_peak_usage(true),
        );
    }

    /** @param array<int, string> $blockers @param array<int, string> $warnings @return array<string, int> */
    private function issueCounts(array $blockers, array $warnings): array
    {
        return ['blockers' => count($blockers), 'warnings' => count($warnings)];
    }

    /** @param array<int, string> $blockers @param array<int, string> $warnings @return array<int, array<string, mixed>> */
    private function issues(array $blockers, array $warnings): array
    {
        return array_merge(
            array_map(fn (string $code): array => ['code' => $code, 'severity' => 'blocker'], $blockers),
            array_map(fn (string $code): array => ['code' => $code, 'severity' => 'warning'], $warnings),
        );
    }

    /** @return array<string, int> */
    private function zeroRecordsChanged(): array
    {
        return [
            'suppliers' => 0,
            'supplier_products' => 0,
            'products' => 0,
            'categories' => 0,
            'supplier_category_mappings' => 0,
            'canonical_product_families' => 0,
            'category_product_attributes' => 0,
            'product_attributes' => 0,
            'attribute_values' => 0,
            'product_attribute_values' => 0,
            'catalog_sync_batches' => 0,
            'catalog_sync_logs' => 0,
            'catalog_sync' => 0,
        ];
    }

    private function elapsedSeconds(float $startedAt): float
    {
        return round(max(0.0001, microtime(true) - $startedAt), 6);
    }
}
