<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            {{ $this->form }}
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm font-medium text-gray-950 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
            Catalog Sync Preview UI OK
        </div>

        @php
            $queryOnly = $this->queryOnlySupplierProducts();
            $rows = $queryOnly['rows'];
            $queryError = $queryOnly['error'];
            $summary = $queryOnly['summary'];
            $discovery = $queryOnly['discovery'] ?? [
                'enabled' => false,
                'scan_limit' => 0,
                'result_limit' => 0,
                'scanned_rows' => 0,
                'create_candidates_found' => 0,
                'displayed_create_candidates' => 0,
                'skipped_rows' => 0,
                'matched_update_rows' => 0,
                'excluded_rows' => 0,
                'unmatched_not_create_reasons' => [],
                'skip_reason_summary' => [],
                'match_type_summary' => [],
                'sample_rows' => [],
            ];
            $unmatchedReasonLabels = [
                'excluded' => 'Excluded',
                'missing_required_data' => 'Missing required data',
                'missing_ean' => 'Missing EAN',
                'missing_name' => 'Missing name',
                'missing_supplier_sku' => 'Missing supplier SKU',
                'missing_price' => 'Missing price',
                'missing_stock_availability' => 'Missing stock / availability',
                'conflict' => 'Conflict',
                'not_eligible' => 'Not eligible',
                'other' => 'Other',
            ];
            $skipReasonLabels = [
                'excluded' => 'Excluded',
                'matched_existing_product' => 'Matched existing product',
                'no_meaningful_changes' => 'No meaningful changes',
                'missing_required_data' => 'Missing required data',
                'conflict' => 'Conflict',
                'other' => 'Other',
            ];
            $matchTypeLabels = [
                'exact_ean_match' => 'Exact EAN match',
                'supplier_sku_match' => 'Supplier SKU match',
                'mpn_brand_match' => 'MPN + Brand match',
                'manual_mapping' => 'Manual mapping',
                'existing_supplier_mapping' => 'Existing supplier mapping',
                'existing_product_offer' => 'Existing product offer',
                'already_linked_supplier_product' => 'Already linked supplier product',
                'fallback_internal_match' => 'Fallback / internal match',
                'name_similarity_only' => 'Name similarity only',
                'no_exact_match' => 'No exact match',
                'match_errors' => 'Match errors',
                'unknown_other' => 'Unknown / other',
            ];
            $money = fn ($value): string => $value !== null ? number_format((float) $value, 2).' EUR' : '-';
            $headerCell = 'sticky top-0 z-30 whitespace-nowrap bg-gray-50 px-3 py-2 shadow-sm dark:bg-gray-950';
            $cell = 'whitespace-nowrap px-3 py-2';
            $truncateCell = 'max-w-[22rem] truncate px-3 py-2';
            $selectedCreateCount = count($this->selectedSupplierProductIds);
            $summaryCounters = [
                'Total Rows' => $summary['total'],
                'Included Rows' => $summary['included'],
                'Excluded Rows' => $summary['excluded'],
                'Matched Rows' => $summary['matched'],
                'Unmatched Rows' => $summary['unmatched'],
                'Match Errors' => $summary['match_errors'],
                'Create Rows' => $summary['create_rows'],
                'Update Rows' => $summary['update_rows'],
                'Skip Rows' => $summary['skip_rows'],
                'Conflict Rows' => $summary['conflict_rows'],
                'Error Rows' => $summary['error_rows'],
            ];
        @endphp

        <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm font-medium text-gray-950 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
            Catalog Sync Preview Query Only OK
        </div>

        @if ($discovery['enabled'])
            <div
                data-create-candidate-discovery-summary
                class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-900 shadow-sm dark:border-green-900 dark:bg-green-950 dark:text-green-100"
            >
                <div class="font-semibold">CREATE candidate scan</div>
                <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <div>Scanned rows: {{ $discovery['scanned_rows'] }}</div>
                    <div>Scan limit: {{ $discovery['scan_limit'] }}</div>
                    <div>CREATE candidates found: {{ $discovery['create_candidates_found'] }}</div>
                    <div>Displayed candidates: {{ $discovery['displayed_create_candidates'] }}</div>
                    <div>Skipped rows: {{ $discovery['skipped_rows'] }}</div>
                    <div>Matched/update rows: {{ $discovery['matched_update_rows'] }}</div>
                    <div>Excluded rows: {{ $discovery['excluded_rows'] }}</div>
                    <div>Display limit: {{ $discovery['result_limit'] }}</div>
                </div>

                @if ($discovery['create_candidates_found'] === 0)
                    <div class="mt-3 rounded-md border border-yellow-200 bg-yellow-50 p-3 text-yellow-800 dark:border-yellow-900 dark:bg-yellow-950 dark:text-yellow-100">
                        No eligible CREATE candidates found in the scanned supplier products.
                    </div>

                    <div
                        data-create-candidate-zero-diagnostics
                        class="mt-4 rounded-md border border-gray-200 bg-white p-3 text-gray-800 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100"
                    >
                        <div class="font-semibold">Why no CREATE candidates?</div>
                        <div class="mt-3 grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Unmatched but not CREATE
                                </div>
                                <dl class="mt-2 space-y-1">
                                    @foreach ($unmatchedReasonLabels as $key => $label)
                                        <div class="flex justify-between gap-3">
                                            <dt>{{ $label }}</dt>
                                            <dd class="font-semibold">{{ $discovery['unmatched_not_create_reasons'][$key] ?? 0 }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>

                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Full scan skip reasons
                                </div>
                                <dl class="mt-2 space-y-1">
                                    @foreach ($skipReasonLabels as $key => $label)
                                        <div class="flex justify-between gap-3">
                                            <dt>{{ $label }}</dt>
                                            <dd class="font-semibold">{{ $discovery['skip_reason_summary'][$key] ?? 0 }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>

                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Match type summary
                                </div>
                                <dl class="mt-2 space-y-1">
                                    @foreach ($matchTypeLabels as $key => $label)
                                        <div class="flex justify-between gap-3">
                                            <dt>{{ $label }}</dt>
                                            <dd class="font-semibold">{{ $discovery['match_type_summary'][$key] ?? 0 }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        </div>

                        @if (! empty($discovery['sample_rows']))
                            <div class="mt-4">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Sample rows that did not become CREATE
                                </div>
                                <div
                                    data-create-candidate-sample-rows
                                    class="mt-2 max-w-full overflow-x-auto"
                                    style="width: 100%; max-width: 100%; overflow-x: auto;"
                                >
                                    <table class="min-w-[1200px] divide-y divide-gray-200 text-xs dark:divide-gray-800" style="min-width: 1200px;">
                                        <thead class="bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-950 dark:text-gray-400">
                                            <tr>
                                                <th class="whitespace-nowrap px-2 py-2">ID</th>
                                                <th class="whitespace-nowrap px-2 py-2">Supplier SKU</th>
                                                <th class="whitespace-nowrap px-2 py-2">EAN</th>
                                                <th class="whitespace-nowrap px-2 py-2">Name</th>
                                                <th class="whitespace-nowrap px-2 py-2">Match Type</th>
                                                <th class="whitespace-nowrap px-2 py-2">Action</th>
                                                <th class="whitespace-nowrap px-2 py-2">Reason</th>
                                                <th class="whitespace-nowrap px-2 py-2">Excluded</th>
                                                <th class="whitespace-nowrap px-2 py-2">Exclusion Reason</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                            @foreach ($discovery['sample_rows'] as $sampleRow)
                                                <tr>
                                                    <td class="whitespace-nowrap px-2 py-2">{{ $sampleRow['supplier_product_id'] }}</td>
                                                    <td class="whitespace-nowrap px-2 py-2">{{ $sampleRow['supplier_sku'] }}</td>
                                                    <td class="whitespace-nowrap px-2 py-2">{{ $sampleRow['ean'] }}</td>
                                                    <td class="max-w-[22rem] truncate px-2 py-2" title="{{ $sampleRow['name'] }}">{{ $sampleRow['name'] }}</td>
                                                    <td class="whitespace-nowrap px-2 py-2">{{ $sampleRow['match_type'] }}</td>
                                                    <td class="whitespace-nowrap px-2 py-2">{{ $sampleRow['sync_action'] }}</td>
                                                    <td class="whitespace-nowrap px-2 py-2">{{ $sampleRow['sync_reason'] }}</td>
                                                    <td class="whitespace-nowrap px-2 py-2">{{ $sampleRow['excluded'] ? 'Yes' : 'No' }}</td>
                                                    <td class="max-w-[18rem] truncate px-2 py-2" title="{{ $sampleRow['exclusion_reason'] }}">{{ $sampleRow['exclusion_reason'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        @if ($this->lastManualSyncResult)
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-sm font-semibold text-gray-950 dark:text-white">Manual CREATE sync result</div>
                <div class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                    <div class="rounded-md border border-green-200 bg-green-50 p-3 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                        Created: {{ $this->lastManualSyncResult['created'] }}
                    </div>
                    <div class="rounded-md border border-yellow-200 bg-yellow-50 p-3 text-yellow-800 dark:border-yellow-900 dark:bg-yellow-950 dark:text-yellow-200">
                        Skipped: {{ $this->lastManualSyncResult['skipped'] }}
                    </div>
                    <div class="rounded-md border border-red-200 bg-red-50 p-3 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                        Failed: {{ $this->lastManualSyncResult['failed'] }}
                    </div>
                </div>

                @if (! empty($this->lastManualSyncResult['messages']))
                    <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                        @foreach ($this->lastManualSyncResult['messages'] as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif

                @if (! empty($this->lastManualSyncResult['batch_uuid']))
                    <div class="mt-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300">
                        Catalog sync batch: {{ $this->lastManualSyncResult['batch_uuid'] }}
                    </div>
                @endif
            </div>
        @endif

        <div
            data-catalog-sync-preview-summary-grid
            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5"
            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 0.75rem;"
        >
            @foreach ($summaryCounters as $label => $value)
                <div class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        @if ($queryError)
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 shadow-sm dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                <div class="font-semibold">Supplier products query failed.</div>
                <div class="mt-1">{{ $queryError }}</div>
            </div>
        @else
            <div
                data-selected-create-sync-toolbar
                class="rounded-lg border border-gray-200 bg-white px-3 py-2 shadow-sm dark:border-gray-800 dark:bg-gray-900"
            >
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="text-sm font-semibold text-gray-950 dark:text-white">Manual CREATE sync</div>
                        <div class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                            Only eligible CREATE rows will be processed.
                        </div>
                    </div>

                    @php
                        $createSyncEnabled = (bool) config('catalog_sync.create_enabled', true);
                        $createSyncButtonDisabled = ! $createSyncEnabled || $selectedCreateCount === 0;
                        $createSyncButtonStyle = $createSyncButtonDisabled
                            ? 'display: inline-flex; align-items: center; justify-content: center; border: 1px solid #d1d5db; color: #6b7280; background: #f3f4f6; padding: 8px 14px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: not-allowed; opacity: 0.8;'
                            : 'display: inline-flex; align-items: center; justify-content: center; border: 1px solid #16a34a; color: #15803d; background: #ffffff; padding: 8px 14px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer;';
                    @endphp

                    @if (! $createSyncEnabled)
                        <div class="rounded-md border border-yellow-200 bg-yellow-50 px-3 py-2 text-xs font-medium text-yellow-800 dark:border-yellow-900 dark:bg-yellow-950 dark:text-yellow-200">
                            Manual CREATE sync is disabled by configuration.
                        </div>
                    @endif

                    <button
                        type="button"
                        wire:click="syncSelectedCreateProducts"
                        wire:loading.attr="disabled"
                        wire:target="syncSelectedCreateProducts"
                        @disabled($createSyncButtonDisabled)
                        data-selected-create-sync-button
                        data-selected-create-sync-disabled="{{ $createSyncButtonDisabled ? 'true' : 'false' }}"
                        class="inline-flex items-center justify-center rounded-md border border-green-600 bg-white px-4 py-2 text-sm font-medium text-green-700 shadow-sm transition hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-green-600 focus:ring-offset-2 disabled:cursor-not-allowed disabled:border-gray-300 disabled:bg-gray-100 disabled:text-gray-500 disabled:shadow-none dark:border-green-500 dark:bg-gray-900 dark:text-green-300 dark:hover:bg-green-950/40 dark:focus:ring-green-400 dark:disabled:border-gray-700 dark:disabled:bg-gray-800 dark:disabled:text-gray-500"
                        style="{{ $createSyncButtonStyle }}"
                    >
                        Sync Selected CREATE Products ({{ $selectedCreateCount }})
                    </button>
                </div>
            </div>

            <div class="max-w-full rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900" style="width: 100%; max-width: 100%;">
                <div
                    data-catalog-sync-preview-scroll-panel
                    class="max-w-full overflow-x-auto overflow-y-auto pb-4"
                    style="width: 100%; max-width: 100%; max-height: 70vh; overflow-x: auto; overflow-y: auto; padding-bottom: 1rem;"
                >
                    <div class="block min-w-[2400px]" style="display: block; min-width: 2400px;">
                        <table class="w-full min-w-[2400px] divide-y divide-gray-200 text-xs dark:divide-gray-800" style="width: 100%; min-width: 2400px;">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-950 dark:text-gray-400">
                            <tr>
                                <th class="{{ $headerCell }}">Select</th>
                                <th class="{{ $headerCell }}">ID</th>
                                <th class="{{ $headerCell }}">Supplier</th>
                                <th class="{{ $headerCell }}">Supplier SKU</th>
                                <th class="{{ $headerCell }}">EAN</th>
                                <th class="{{ $headerCell }}">MPN</th>
                                <th class="{{ $headerCell }}">Name</th>
                                <th class="{{ $headerCell }}">Price</th>
                                <th class="{{ $headerCell }}">Supplier Cost</th>
                                <th class="{{ $headerCell }}">Pricing Rule</th>
                                <th class="{{ $headerCell }}">Margin Type</th>
                                <th class="{{ $headerCell }}">Margin Value</th>
                                <th class="{{ $headerCell }}">Calculated Price</th>
                                <th class="{{ $headerCell }}">Excluded</th>
                                <th class="{{ $headerCell }}">Exclusion Reason</th>
                                <th class="{{ $headerCell }}">Matched Product ID</th>
                                <th class="{{ $headerCell }}">Matched Product</th>
                                <th class="{{ $headerCell }}">Match Type</th>
                                <th class="{{ $headerCell }}">Match Confidence</th>
                                <th class="{{ $headerCell }}">Sync Action</th>
                                <th class="{{ $headerCell }}">Sync Reason</th>
                                <th class="{{ $headerCell }}">Quantity</th>
                                <th class="{{ $headerCell }}">Availability</th>
                                <th class="{{ $headerCell }}">Status</th>
                                <th class="{{ $headerCell }}">Updated</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($rows as $row)
                                <tr>
                                    <td class="{{ $cell }}">
                                        <input
                                            type="checkbox"
                                            wire:model="selectedSupplierProductIds"
                                            value="{{ $row['supplier_product_id'] }}"
                                            @disabled($row['sync_action'] !== 'CREATE')
                                            aria-label="Select supplier product {{ $row['supplier_product_id'] }}"
                                            class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:bg-gray-900"
                                        >
                                    </td>
                                    <td class="{{ $cell }}">{{ $row['supplier_product_id'] }}</td>
                                    <td class="{{ $cell }}">{{ $row['supplier'] }}</td>
                                    <td class="{{ $cell }}">{{ $row['supplier_sku'] }}</td>
                                    <td class="{{ $cell }}">{{ $row['ean'] }}</td>
                                    <td class="{{ $cell }}">{{ $row['mpn'] }}</td>
                                    <td class="{{ $truncateCell }} font-medium text-gray-950 dark:text-white" title="{{ $row['name'] }}">{{ $row['name'] }}</td>
                                    <td class="{{ $cell }}">{{ $money($row['price']) }}</td>
                                    <td class="{{ $cell }}">{{ $money($row['supplier_cost']) }}</td>
                                    <td class="{{ $truncateCell }}" title="{{ $row['pricing_rule_used'] }}">
                                        <div class="truncate">{{ $row['pricing_rule_used'] }}</div>
                                        @if ($row['pricing_error'])
                                            <div class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $row['pricing_error'] }}</div>
                                        @endif
                                    </td>
                                    <td class="{{ $cell }}">{{ $row['margin_type'] ?: '-' }}</td>
                                    <td class="{{ $cell }}">{{ $row['margin_value'] ?: '-' }}</td>
                                    <td class="{{ $cell }}">{{ $money($row['calculated_price']) }}</td>
                                    <td class="{{ $cell }}">{{ $row['excluded'] ? 'Yes' : 'No' }}</td>
                                    <td class="{{ $truncateCell }}" title="{{ $row['exclusion_reason'] }}">
                                        <div class="truncate">{{ $row['exclusion_reason'] }}</div>
                                        @if ($row['exclusion_error'])
                                            <div class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $row['exclusion_error'] }}</div>
                                        @endif
                                    </td>
                                    <td class="{{ $cell }}">{{ $row['matched_product_id'] ?: '-' }}</td>
                                    <td class="{{ $truncateCell }}" title="{{ $row['matched_product_name'] ?: '-' }}">{{ $row['matched_product_name'] ?: '-' }}</td>
                                    <td class="{{ $cell }}">{{ $row['match_type'] ?: '-' }}</td>
                                    <td class="{{ $cell }}">{{ $row['match_confidence'] ?: '-' }}</td>
                                    <td class="{{ $cell }}">{{ $row['sync_action'] }}</td>
                                    <td class="{{ $truncateCell }}" title="{{ $row['sync_reason'] }}">{{ $row['sync_reason'] }}</td>
                                    <td class="{{ $cell }}">{{ $row['quantity'] }}</td>
                                    <td class="{{ $cell }}">{{ $row['availability'] }}</td>
                                    <td class="{{ $cell }}">{{ $row['status'] }}</td>
                                    <td class="{{ $cell }}">{{ $row['updated_at'] ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="25" class="px-3 py-8 text-center text-gray-500">
                                        {{ $discovery['enabled'] ? 'No eligible CREATE candidates found in the scanned supplier products.' : 'No supplier products match the query-only filters.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
