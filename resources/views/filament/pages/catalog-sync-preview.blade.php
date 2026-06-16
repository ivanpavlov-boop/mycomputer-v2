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
                            <th class="px-4 py-3">SKU / EAN</th>
                            <th class="px-4 py-3">Product</th>
                            <th class="px-4 py-3">Category</th>
                            <th class="px-4 py-3">Supplier price</th>
                            <th class="px-4 py-3">Pricing rule</th>
                            <th class="px-4 py-3">Final price</th>
                            <th class="px-4 py-3">Stock</th>
                            <th class="px-4 py-3">Images</th>
                            <th class="px-4 py-3">Matched by</th>
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
                                    <div>{{ $row['supplier_sku'] ?: '-' }}</div>
                                    <div class="text-xs text-gray-500">{{ $row['ean'] ?: 'Missing EAN' }}</div>
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
                                    <div>{{ $row['pricing_rule_applied'] ?: 'Not applied' }}</div>
                                    <div class="text-xs text-gray-500">Margin: {{ $row['margin_applied'] !== null ? number_format((float) $row['margin_applied'], 2).' EUR' : '-' }}</div>
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
                                    {{ $row['matched_by'] ? implode(', ', $row['matched_by']) : '-' }}
                                    @if ($row['conflict_reasons'])
                                        <div class="text-xs text-red-600">{{ implode(', ', $row['conflict_reasons']) }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-4 py-8 text-center text-gray-500">No supplier products match the preview filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
