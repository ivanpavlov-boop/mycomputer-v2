<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="space-y-3">
            <h2 class="text-base font-semibold">Duplicate canonical attributes</h2>
            <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">Normalized name</th>
                            <th class="px-4 py-3 text-left font-medium">Attributes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                        @forelse ($this->getDuplicateAttributes() as $name => $attributes)
                            <tr>
                                <td class="px-4 py-3">{{ $name }}</td>
                                <td class="px-4 py-3">{{ $attributes->pluck('code')->join(', ') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="px-4 py-6 text-center text-gray-500">No duplicate attributes detected.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-base font-semibold">Duplicate canonical values</h2>
            <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">Normalized value</th>
                            <th class="px-4 py-3 text-left font-medium">Values</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                        @forelse ($this->getDuplicateValues() as $value => $values)
                            <tr>
                                <td class="px-4 py-3">{{ $value }}</td>
                                <td class="px-4 py-3">{{ $values->pluck('display_value')->join(', ') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="px-4 py-6 text-center text-gray-500">No duplicate values detected.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
