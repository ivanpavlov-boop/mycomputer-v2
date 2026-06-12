<x-filament-panels::page>
    @php($status = $this->getStatus())

    <div class="grid gap-4 md:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500">Engine</div>
            <div class="mt-1 text-2xl font-semibold">{{ $status['engine'] ?? 'unknown' }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500">Meilisearch status</div>
            <div class="mt-1 text-2xl font-semibold">
                {{ ($status['available'] ?? false) ? 'Available' : 'Unavailable' }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500">Indexed products</div>
            <div class="mt-1 text-2xl font-semibold">{{ $status['indexed_products_count'] ?? 0 }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500">Last indexing date</div>
            <div class="mt-1 text-lg font-semibold">{{ $status['last_indexed_at'] ?? 'Not recorded' }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <div class="text-sm text-gray-600">{{ $status['message'] ?? 'Search status loaded.' }}</div>
    </x-filament::section>
</x-filament-panels::page>
