<x-filament-panels::page>
    <div class="space-y-6">
        @if ($this->diagnosticsOnly)
            @php
                $report = $this->diagnosticReport;
                $diagnosticStatus = $report['status'] ?? 'ok';
                $diagnosticIsFailure = $diagnosticStatus === 'failed';
            @endphp

            <div @class([
                'rounded-lg border p-4 text-sm shadow-sm',
                'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200' => $diagnosticIsFailure,
                'border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-200' => ! $diagnosticIsFailure,
            ])>
                <div class="font-semibold">Catalog Sync Preview diagnostics {{ $diagnosticIsFailure ? 'FAILED' : 'OK' }}</div>
                <div class="mt-1">Step: {{ $report['step'] ?? 'static' }}</div>
                <div class="mt-1">{{ $report['message'] ?? 'Static Filament page render completed without loading filters, suppliers, or preview services.' }}</div>
                @if (isset($report['exception']))
                    <div class="mt-1">Exception: {{ $report['exception'] }}</div>
                @endif
                @if (isset($report['duration_ms']))
                    <div class="mt-1">Duration: {{ $report['duration_ms'] }} ms</div>
                @endif
            </div>

            @if (($this->diagnosticStep ?? null) === 'filters')
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    {{ $this->form }}
                </div>
            @endif

            <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-2 font-semibold text-gray-950 dark:text-white">Diagnostic report</div>
                <dl class="space-y-2">
                    @foreach ($report as $key => $value)
                        <div>
                            <dt class="font-medium text-gray-700 dark:text-gray-200">{{ str_replace('_', ' ', (string) $key) }}</dt>
                            <dd class="text-gray-600 dark:text-gray-300">
                                @if (is_array($value))
                                    <pre class="mt-1 overflow-x-auto rounded bg-gray-100 p-2 text-xs dark:bg-gray-950">{{ json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                @else
                                    {{ $value }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-2 font-semibold text-gray-950 dark:text-white">Available diagnostic steps</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->diagnosticSteps as $step)
                        <span @class([
                            'rounded-md border px-3 py-1.5',
                            'border-primary-500 bg-primary-50 text-primary-700' => ($this->diagnosticStep ?? 'static') === $step,
                            'border-gray-200 text-gray-700 dark:border-gray-700 dark:text-gray-200' => ($this->diagnosticStep ?? 'static') !== $step,
                        ])>{{ $step }}</span>
                    @endforeach
                </div>
            </div>
        @else
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            {{ $this->form }}
        </div>

        @php
            $preview = $this->previewPayload;
            $summary = $preview['summary'];
            $rows = $preview['rows'];
            $previewError = $preview['error'] ?? null;

            $quickFilters = [
                'create' => 'CREATE',
                'update' => 'UPDATE',
                'conflict' => 'CONFLICT',
                'apcom' => 'APCOM only',
                'missing_ean' => 'Missing EAN',
                'zero_stock' => 'Zero Stock',
                'missing_images' => 'Missing Images',
            ];

            $money = fn ($value): string => $value !== null ? number_format((float) $value, 2).' EUR' : '-';
            $percent = fn ($value): string => $value !== null ? number_format((float) $value, 2).'%' : '-';
            $sortIndicator = fn (string $column): string => ($this->filters['sort_column'] ?? null) === $column
                ? (($this->filters['sort_direction'] ?? 'asc') === 'asc' ? ' ↑' : ' ↓')
                : '';
        @endphp

        @if ($previewError)
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 shadow-sm dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                <div class="font-semibold">Catalog Sync Preview could not be generated.</div>
                <div class="mt-1">{{ $previewError }}</div>
            </div>
        @endif

        <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                'total_staged_products' => 'Products reviewed',
                'to_create' => 'Products to create',
                'to_update' => 'Products to update',
                'conflicts' => 'Conflicts',
                'missing_ean' => 'Missing EAN',
                'missing_images' => 'Missing Images',
                'excluded' => 'Excluded',
                'average_margin' => 'Average Margin %',
                'estimated_revenue' => 'Estimated Revenue',
                'estimated_profit' => 'Estimated Profit',
            ] as $key => $label)
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                        @if (in_array($key, ['estimated_revenue', 'estimated_profit'], true))
                            {{ $money($summary[$key] ?? 0) }}
                        @elseif ($key === 'average_margin')
                            {{ $percent($summary[$key] ?? 0) }}
                        @else
                            {{ $summary[$key] ?? 0 }}
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 text-sm font-medium text-gray-950 dark:text-white">Quick filters</div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    wire:click="applyQuickFilter(null)"
                    class="rounded-md border px-3 py-1.5 text-sm {{ blank($this->filters['quick_filter'] ?? null) ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-700 dark:border-gray-700 dark:text-gray-200' }}"
                >
                    All
                </button>
                @foreach ($quickFilters as $filter => $label)
                    <button
                        type="button"
                        wire:click="applyQuickFilter('{{ $filter }}')"
                        class="rounded-md border px-3 py-1.5 text-sm {{ ($this->filters['quick_filter'] ?? null) === $filter ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-700 dark:border-gray-700 dark:text-gray-200' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="hidden overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 md:block">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead class="sticky top-0 z-10 bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-950 dark:text-gray-400">
                        <tr>
                            <th class="resize-x overflow-auto px-4 py-3"><button type="button" wire:click="sortBy('product_name')">Product Name{{ $sortIndicator('product_name') }}</button></th>
                            <th class="resize-x overflow-auto px-4 py-3"><button type="button" wire:click="sortBy('supplier_price')">Supplier Price{{ $sortIndicator('supplier_price') }}</button></th>
                            <th class="resize-x overflow-auto px-4 py-3"><button type="button" wire:click="sortBy('final_calculated_selling_price')">Final Selling Price{{ $sortIndicator('final_calculated_selling_price') }}</button></th>
                            <th class="resize-x overflow-auto px-4 py-3"><button type="button" wire:click="sortBy('profit_amount')">Profit Amount{{ $sortIndicator('profit_amount') }}</button></th>
                            <th class="resize-x overflow-auto px-4 py-3"><button type="button" wire:click="sortBy('margin_percent')">Margin %{{ $sortIndicator('margin_percent') }}</button></th>
                            <th class="resize-x overflow-auto px-4 py-3"><button type="button" wire:click="sortBy('winning_pricing_rule')">Winning Rule{{ $sortIndicator('winning_pricing_rule') }}</button></th>
                            <th class="resize-x overflow-auto px-4 py-3"><button type="button" wire:click="sortBy('target_catalog_action')">Action{{ $sortIndicator('target_catalog_action') }}</button></th>
                            <th class="resize-x overflow-auto px-4 py-3"><button type="button" wire:click="sortBy('stock_quantity')">Stock{{ $sortIndicator('stock_quantity') }}</button></th>
                            <th class="resize-x overflow-auto px-4 py-3"><button type="button" wire:click="sortBy('supplier_name')">Supplier{{ $sortIndicator('supplier_name') }}</button></th>
                            <th class="resize-x overflow-auto px-4 py-3"><button type="button" wire:click="sortBy('normalized_category')">Category{{ $sortIndicator('normalized_category') }}</button></th>
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
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <details>
                                        <summary class="cursor-pointer font-medium text-gray-950 dark:text-white">
                                            {{ $row['product_name'] }}
                                            <span class="ml-2 text-xs font-normal text-gray-500">details</span>
                                        </summary>
                                        <div class="mt-3 grid gap-3 text-xs text-gray-600 dark:text-gray-300 lg:grid-cols-2">
                                            <div>
                                                <div class="font-semibold text-gray-800 dark:text-gray-100">Identifiers</div>
                                                <div>EAN: {{ $row['ean'] ?: 'Missing EAN' }}</div>
                                                <div>Supplier SKU: {{ $row['supplier_sku'] ?: '-' }}</div>
                                                <div>MPN: {{ $row['mpn'] ?: '-' }}</div>
                                            </div>
                                            <div>
                                                <div class="font-semibold text-gray-800 dark:text-gray-100">Matching</div>
                                                <div>Matched By: {{ $row['matched_by_display'] }}</div>
                                                <div>Reason: {{ $row['reason'] }}</div>
                                                @if ($row['exclusion_rule'])
                                                    <div>Exclusion Rule: {{ $row['exclusion_rule'] }}</div>
                                                @endif
                                                @if ($row['target_catalog_action'] === 'update')
                                                    <div>Catalog Product ID: {{ $row['target_product_id'] ?: '-' }}</div>
                                                    <div>Current Price: {{ $money($row['current_price']) }}</div>
                                                    <div>New Price: {{ $money($row['new_price']) }}</div>
                                                    <div>Current Stock: {{ $row['current_stock'] ?? '-' }}</div>
                                                    <div>New Stock: {{ $row['new_stock'] ?? '-' }}</div>
                                                @endif
                                            </div>
                                            <div>
                                                <div class="font-semibold text-gray-800 dark:text-gray-100">Pricing</div>
                                                <div>Winning Rule: {{ $row['winning_pricing_rule'] ?: '-' }}</div>
                                                <div>Rule Explanation: {{ $row['pricing_rule_reason'] ?: '-' }}</div>
                                                <div class="mt-1">Inheritance:</div>
                                                <div class="whitespace-pre-line">{{ $row['pricing_inheritance'] ? implode("\n-> ", $row['pricing_inheritance']) : '-' }}</div>
                                            </div>
                                            <div>
                                                <div class="font-semibold text-gray-800 dark:text-gray-100">Catalog Data</div>
                                                <div>Mapped Category: {{ $row['normalized_category'] ?: '-' }}</div>
                                                <div>Raw Category: {{ $row['raw_category_data'] ?: '-' }}</div>
                                                <div>Image Count: {{ $row['image_count'] }}</div>
                                                <div>Result: {{ $row['result'] }}</div>
                                                <div>Winning Offer: {{ $row['winning_offer_supplier'] ?: '-' }}</div>
                                                <div>Winning Reason: {{ $row['winning_offer_reason'] }}</div>
                                                @if ($row['conflict_reasons'])
                                                    <div class="text-red-600">Conflicts: {{ implode(', ', $row['conflict_reasons']) }}</div>
                                                @endif
                                            </div>
                                            <div class="lg:col-span-2">
                                                <div class="font-semibold text-gray-800 dark:text-gray-100">Supplier Offers</div>
                                                <div class="mt-1 grid gap-2 lg:grid-cols-3">
                                                    @forelse ($row['supplier_offers'] as $offer)
                                                        <div class="rounded border border-gray-200 p-2 dark:border-gray-700">
                                                            <div class="font-medium">
                                                                {{ $offer['supplier_name'] ?: '-' }}
                                                                @if ($offer['selected'])
                                                                    <span class="text-green-600">Winning</span>
                                                                @endif
                                                            </div>
                                                            <div>Cost: {{ $money($offer['display_cost']) }}</div>
                                                            <div>Stock: {{ $offer['stock'] }}</div>
                                                            <div>Priority: {{ $offer['supplier_priority'] }}</div>
                                                            @if (! $offer['eligible'])
                                                                <div class="text-yellow-600">{{ $offer['rejection_reason'] }}</div>
                                                            @endif
                                                            @if ($offer['exclusion_rule'])
                                                                <div class="text-red-600">{{ $offer['exclusion_rule'] }}</div>
                                                            @endif
                                                        </div>
                                                    @empty
                                                        <div>No supplier offers found.</div>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>
                                    </details>
                                </td>
                                <td class="px-4 py-3">{{ $money($row['supplier_price']) }}</td>
                                <td class="px-4 py-3">{{ $money($row['final_calculated_selling_price']) }}</td>
                                <td class="px-4 py-3">{{ $money($row['profit_amount']) }}</td>
                                <td class="px-4 py-3">{{ $percent($row['margin_percent']) }}</td>
                                <td class="px-4 py-3">{{ $row['winning_pricing_rule'] ?: '-' }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-md px-2 py-1 text-xs font-medium {{ $actionClass }}">
                                        {{ strtoupper($row['target_catalog_action']) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div>{{ $row['stock_quantity'] ?? 0 }}</div>
                                    <div class="text-xs text-gray-500">{{ $row['stock_status'] }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $row['supplier_name'] }}</td>
                                <td class="px-4 py-3">
                                    <div>{{ $row['normalized_category'] ?: '-' }}</div>
                                    @unless ($row['category_exists'])
                                        <div class="text-xs text-yellow-600">Missing category</div>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-4 py-8 text-center text-gray-500">No supplier products match the preview filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 md:hidden">
            @forelse ($rows as $row)
                <details class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <summary class="cursor-pointer">
                        <div class="font-semibold text-gray-950 dark:text-white">{{ $row['product_name'] }}</div>
                        <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
                            <div>Supplier: {{ $money($row['supplier_price']) }}</div>
                            <div>Final: {{ $money($row['final_calculated_selling_price']) }}</div>
                            <div>Profit: {{ $money($row['profit_amount']) }}</div>
                            <div>Margin: {{ $percent($row['margin_percent']) }}</div>
                            <div>Action: {{ strtoupper($row['target_catalog_action']) }}</div>
                            <div>Stock: {{ $row['stock_quantity'] ?? 0 }}</div>
                        </div>
                    </summary>
                    <div class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                        <div>Supplier: {{ $row['supplier_name'] }}</div>
                        <div>Category: {{ $row['normalized_category'] ?: '-' }}</div>
                        <div>Winning Rule: {{ $row['winning_pricing_rule'] ?: '-' }}</div>
                        <div>Winning Offer: {{ $row['winning_offer_supplier'] ?: '-' }}</div>
                        <div>Matched By: {{ $row['matched_by_display'] }}</div>
                        @if ($row['exclusion_rule'])
                            <div>Exclusion Rule: {{ $row['exclusion_rule'] }}</div>
                        @endif
                        <div>EAN: {{ $row['ean'] ?: 'Missing EAN' }}</div>
                        <div>Supplier SKU: {{ $row['supplier_sku'] ?: '-' }}</div>
                        <div>MPN: {{ $row['mpn'] ?: '-' }}</div>
                        <div>Images: {{ $row['image_count'] }}</div>
                        <div>Result: {{ $row['result'] }}</div>
                    </div>
                </details>
            @empty
                <div class="rounded-lg border border-gray-200 bg-white p-8 text-center text-gray-500 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    No supplier products match the preview filters.
                </div>
            @endforelse
        </div>
        @endif
    </div>
</x-filament-panels::page>
