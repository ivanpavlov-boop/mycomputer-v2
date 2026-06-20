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
        @endphp

        <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm font-medium text-gray-950 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
            Catalog Sync Preview Query Only OK
        </div>

        <div class="grid gap-4 md:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Total Rows</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['total'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Included Rows</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['included'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Excluded Rows</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['excluded'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Matched Rows</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['matched'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Unmatched Rows</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['unmatched'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Match Errors</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['match_errors'] }}</div>
            </div>
        </div>

        @if ($queryError)
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 shadow-sm dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                <div class="font-semibold">Supplier products query failed.</div>
                <div class="mt-1">{{ $queryError }}</div>
            </div>
        @else
            <div class="max-w-full rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900" style="width: 100%; max-width: 100%;">
                <div class="max-w-full overflow-x-auto overflow-y-auto" style="width: 100%; max-width: 100%; max-height: 70vh; overflow-x: auto; overflow-y: auto;">
                    <div class="block min-w-[2400px]" style="display: block; min-width: 2400px;">
                        <table class="w-full min-w-[2400px] divide-y divide-gray-200 text-xs dark:divide-gray-800" style="width: 100%; min-width: 2400px;">
                        <thead class="sticky top-0 z-20 bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-950 dark:text-gray-400">
                            <tr>
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
                                <th class="{{ $headerCell }}">Quantity</th>
                                <th class="{{ $headerCell }}">Availability</th>
                                <th class="{{ $headerCell }}">Status</th>
                                <th class="{{ $headerCell }}">Updated</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($rows as $row)
                                <tr>
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
                                    <td class="{{ $cell }}">{{ $row['quantity'] }}</td>
                                    <td class="{{ $cell }}">{{ $row['availability'] }}</td>
                                    <td class="{{ $cell }}">{{ $row['status'] }}</td>
                                    <td class="{{ $cell }}">{{ $row['updated_at'] ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="22" class="px-3 py-8 text-center text-gray-500">No supplier products match the query-only filters.</td>
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
