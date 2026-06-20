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
            $money = fn ($value): string => $value !== null ? number_format((float) $value, 2).' EUR' : '-';
            $headerCell = 'sticky top-0 z-30 whitespace-nowrap bg-gray-50 px-3 py-2 shadow-sm dark:bg-gray-950';
            $cell = 'whitespace-nowrap px-3 py-2';
            $truncateCell = 'max-w-[22rem] truncate px-3 py-2';
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
            <div class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm text-gray-600 dark:text-gray-300">
                    Select eligible CREATE rows, then run a safe manual create sync. UPDATE, SKIP, CONFLICT and ERROR rows are revalidated and ignored.
                </div>
                <button
                    type="button"
                    wire:click="syncSelectedCreateProducts"
                    wire:loading.attr="disabled"
                    wire:target="syncSelectedCreateProducts"
                    class="inline-flex items-center justify-center rounded-md bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-primary-500 dark:hover:bg-primary-400"
                >
                    Sync Selected CREATE Products
                </button>
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
                                    <td colspan="25" class="px-3 py-8 text-center text-gray-500">No supplier products match the query-only filters.</td>
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
