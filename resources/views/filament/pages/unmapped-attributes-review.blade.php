<x-filament-panels::page>
    <div class="space-y-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Top unmapped or low-confidence supplier attributes. Use Supplier Attribute Staging to approve mappings and create aliases.
        </p>

        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium">Raw name</th>
                        <th class="px-4 py-3 text-left font-medium">Raw value</th>
                        <th class="px-4 py-3 text-left font-medium">Supplier</th>
                        <th class="px-4 py-3 text-left font-medium">Source</th>
                        <th class="px-4 py-3 text-left font-medium">Status</th>
                        <th class="px-4 py-3 text-left font-medium">Rows</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                    @forelse ($this->getRows() as $row)
                        <tr>
                            <td class="px-4 py-3">{{ $row->raw_name }}</td>
                            <td class="px-4 py-3">{{ trim($row->raw_value.' '.$row->raw_unit) }}</td>
                            <td class="px-4 py-3">{{ $row->supplier?->company_name ?? 'Generic' }}</td>
                            <td class="px-4 py-3">{{ strtoupper((string) $row->source_type) }}</td>
                            <td class="px-4 py-3">{{ $row->status }}</td>
                            <td class="px-4 py-3">{{ $row->rows_count }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-gray-500">No unmapped attributes.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
