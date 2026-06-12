<x-filament-panels::page>
    @php($status = $this->getStatus())

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($status as $label => $value)
            <x-filament::section>
                <x-slot name="heading">{{ str($label)->replace('_', ' ')->title() }}</x-slot>
                <div class="text-2xl font-bold">{{ $value }}</div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
