<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            {{ $this->form }}
        </div>

        @php
            $preview = $this->preview();
            $summary = $preview['summary'];
            $rows = $preview['rows'];
        @endphp

        <div class="grid gap-4 md:grid-cols-4">
            @foreach ([
                'total_staged_products' => 'Staged',
                'to_create' => 'Create',
                'to_update' => 'Update',
                'to_skip' => 'Skip',
                'conflicts' => 'Conflicts',
                'missing_categories' => 'Missing categories',
                'missing_images' => 'Missing images',
                'missing_ean' => 'Missing EAN',
            ] as $key => $label)
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary[$key] ?? 0 }}</div>
                </div>
            @endforeach
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-950 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3">Action</th>
                            <th class="px-4 py-3">Supplier</th>
                            <th class="px-4 py-3">Supplier SKU</th>
                            <th class="px-4 py-3">EAN</th>
                            <th class="px-4 py-3">Product Name</th>
                            <th class="px-4 py-3">Category</th>
                            <th class="px-4 py-3">Supplier Price</th>
                            <th class="px-4 py-3">Pricing Rule</th>
                            <th class="px-4 py-3">Margin Rule</th>
                            <th class="px-4 py-3">Margin Amount</th>
                            <th class="px-4 py-3">Final Selling Price</th>
                            <th class="px-4 py-3">Stock</th>
                            <th class="px-4 py-3">Image Count</th>
                            <th class="px-4 py-3">Matched By</th>
                            <th class="px-4 py-3">Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($rows as $row)
                            @php
                                $actionClass = match ($row['target_catalog_action']) {
                                    'create' => 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-300',
                                    'update' => 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
                                    'skip' => 'bg-yellow-50 text-yellow-700 dark:bg-yellow-950 dark:text-yellow-300',
                                    'conflict' => 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
                                    default => 'bg-gray-50 text-gray-700 dark:bg-gray-950 dark:text-gray-300',
                                };
                            @endphp
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="rounded-md px-2 py-1 text-xs font-medium {{ $actionClass }}">
                                        {{ strtoupper($row['target_catalog_action']) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ $row['supplier_name'] }}</td>
                                <td class="px-4 py-3">
                                    {{ $row['supplier_sku'] ?: '-' }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ $row['ean'] ?: 'Missing EAN' }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $row['product_name'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $row['brand'] ?: 'No brand' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div>{{ $row['normalized_category'] ?: '-' }}</div>
                                    @unless ($row['category_exists'])
                                        <div class="text-xs text-yellow-600">Missing category</div>
                                    @endunless
                                </td>
                                <td class="px-4 py-3">{{ $row['supplier_price'] !== null ? number_format((float) $row['supplier_price'], 2).' EUR' : '-' }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ $row['matched_pricing_rule'] ?: 'Not applied' }}</div>
                                    <div class="mt-1 text-xs text-gray-500">Matched Pricing Rule: {{ $row['matched_pricing_rule'] ?: '-' }}</div>
                                    <div class="text-xs text-gray-500">Inheritance: {{ $row['pricing_inheritance'] ? implode(' -> ', $row['pricing_inheritance']) : '-' }}</div>
                                    <div class="text-xs text-gray-500">Winning Rule: {{ $row['winning_pricing_rule'] ?: '-' }}</div>
                                    @if ($row['pricing_rule_reason'])
                                        <div class="text-xs text-gray-500">{{ $row['pricing_rule_reason'] }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    {{ $row['margin_rule'] ?: '-' }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ $row['margin_amount'] !== null ? number_format((float) $row['margin_amount'], 2).' EUR' : '-' }}
                                </td>
                                <td class="px-4 py-3">
                                    <div>{{ $row['final_calculated_selling_price'] !== null ? number_format((float) $row['final_calculated_selling_price'], 2).' EUR' : '-' }}</div>
                                    @if ($row['sale_price'] !== null)
                                        <div class="text-xs text-green-600">Sale: {{ number_format((float) $row['sale_price'], 2) }} EUR</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div>{{ $row['stock_quantity'] ?? 0 }}</div>
                                    <div class="text-xs text-gray-500">{{ $row['stock_status'] }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $row['image_count'] }}</td>
                                <td class="px-4 py-3">
                                    <div>{{ $row['matched_by_display'] }}</div>
                                    <div class="text-xs text-gray-500">Reason: {{ $row['reason'] }}</div>
                                    @if ($row['target_catalog_action'] === 'update')
                                        <div class="mt-1 text-xs text-gray-500">Catalog Product: {{ $row['target_product_name'] ?: '-' }}</div>
                                        <div class="text-xs text-gray-500">Catalog ID: {{ $row['target_product_id'] ?: '-' }}</div>
                                        <div class="text-xs text-gray-500">Current Price: {{ $row['current_price'] !== null ? number_format((float) $row['current_price'], 2).' EUR' : '-' }}</div>
                                        <div class="text-xs text-gray-500">New Price: {{ $row['new_price'] !== null ? number_format((float) $row['new_price'], 2).' EUR' : '-' }}</div>
                                        <div class="text-xs text-gray-500">Current Stock: {{ $row['current_stock'] ?? '-' }}</div>
                                        <div class="text-xs text-gray-500">New Stock: {{ $row['new_stock'] ?? '-' }}</div>
                                    @endif
                                    @if ($row['conflict_reasons'])
                                        <div class="text-xs text-red-600">{{ implode(', ', $row['conflict_reasons']) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $row['result'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="15" class="px-4 py-8 text-center text-gray-500">No supplier products match the preview filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
