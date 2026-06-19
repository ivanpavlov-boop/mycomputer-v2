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
        @endphp

        <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm font-medium text-gray-950 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
            Catalog Sync Preview Query Only OK
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Included Rows</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['included'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Excluded Rows</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['excluded'] }}</div>
            </div>
        </div>

        @if ($queryError)
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 shadow-sm dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                <div class="font-semibold">Supplier products query failed.</div>
                <div class="mt-1">{{ $queryError }}</div>
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-950 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-3">ID</th>
                                <th class="px-4 py-3">Supplier</th>
                                <th class="px-4 py-3">Supplier SKU</th>
                                <th class="px-4 py-3">EAN</th>
                                <th class="px-4 py-3">MPN</th>
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">Price</th>
                                <th class="px-4 py-3">Supplier Cost</th>
                                <th class="px-4 py-3">Pricing Rule</th>
                                <th class="px-4 py-3">Margin Type</th>
                                <th class="px-4 py-3">Margin Value</th>
                                <th class="px-4 py-3">Calculated Price</th>
                                <th class="px-4 py-3">Excluded</th>
                                <th class="px-4 py-3">Exclusion Reason</th>
                                <th class="px-4 py-3">Quantity</th>
                                <th class="px-4 py-3">Availability</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Updated</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($rows as $row)
                                <tr>
                                    <td class="px-4 py-3">{{ $row['supplier_product_id'] }}</td>
                                    <td class="px-4 py-3">{{ $row['supplier'] }}</td>
                                    <td class="px-4 py-3">{{ $row['supplier_sku'] }}</td>
                                    <td class="px-4 py-3">{{ $row['ean'] }}</td>
                                    <td class="px-4 py-3">{{ $row['mpn'] }}</td>
                                    <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</td>
                                    <td class="px-4 py-3">{{ $money($row['price']) }}</td>
                                    <td class="px-4 py-3">{{ $money($row['supplier_cost']) }}</td>
                                    <td class="px-4 py-3">
                                        <div>{{ $row['pricing_rule_used'] }}</div>
                                        @if ($row['pricing_error'])
                                            <div class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $row['pricing_error'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">{{ $row['margin_type'] ?: '-' }}</td>
                                    <td class="px-4 py-3">{{ $row['margin_value'] ?: '-' }}</td>
                                    <td class="px-4 py-3">{{ $money($row['calculated_price']) }}</td>
                                    <td class="px-4 py-3">{{ $row['excluded'] ? 'Yes' : 'No' }}</td>
                                    <td class="px-4 py-3">
                                        <div>{{ $row['exclusion_reason'] }}</div>
                                        @if ($row['exclusion_error'])
                                            <div class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $row['exclusion_error'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">{{ $row['quantity'] }}</td>
                                    <td class="px-4 py-3">{{ $row['availability'] }}</td>
                                    <td class="px-4 py-3">{{ $row['status'] }}</td>
                                    <td class="px-4 py-3">{{ $row['updated_at'] ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="18" class="px-4 py-8 text-center text-gray-500">No supplier products match the query-only filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
