<x-filament-panels::page>
    @php($dashboard = $this->getDashboard())

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <x-filament::section>
            <x-slot name="heading">Top Categories</x-slot>
            <div class="space-y-2">
                @foreach ($dashboard['top_categories'] as $category)
                    <div class="flex justify-between text-sm">
                        <span>{{ $category->name }}</span>
                        <span>{{ $category->products_count }}</span>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Top Products</x-slot>
            <div class="space-y-2">
                @foreach ($dashboard['top_products'] as $product)
                    <div class="text-sm">
                        <div class="font-medium">{{ $product->name }}</div>
                        <div class="text-gray-500">{{ $product->sku }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Most Searched Terms</x-slot>
            <div class="space-y-2">
                @forelse ($dashboard['most_searched_terms'] as $term)
                    <div class="text-sm">{{ $term }}</div>
                @empty
                    <div class="text-sm text-gray-500">No search events yet.</div>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Abandoned Cart Recovery</x-slot>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span>Total</span><span>{{ $dashboard['abandoned_carts']['total'] }}</span></div>
                <div class="flex justify-between"><span>Recovered</span><span>{{ $dashboard['abandoned_carts']['recovered'] }}</span></div>
                <div class="flex justify-between"><span>Recovery rate</span><span>{{ $dashboard['abandoned_carts']['recovery_rate'] }}%</span></div>
                <div class="flex justify-between"><span>Recovered revenue</span><span>{{ number_format($dashboard['abandoned_carts']['recovered_revenue'], 2) }} EUR</span></div>
                <div class="flex justify-between"><span>Pending emails</span><span>{{ $dashboard['abandoned_carts']['pending_emails'] }}</span></div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
