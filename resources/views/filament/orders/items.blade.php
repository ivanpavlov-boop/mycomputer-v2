<div class="space-y-3">
    @foreach ($order->items as $item)
        <div class="rounded-lg border border-gray-200 p-3">
            <div class="font-medium">{{ $item->product_name }}</div>
            <div class="mt-1 text-sm text-gray-500">
                SKU: {{ $item->sku }} · Qty: {{ $item->quantity }} · Unit: {{ number_format((float) $item->unit_price, 2) }} BGN · Total: {{ number_format((float) $item->total_price, 2) }} BGN
            </div>
        </div>
    @endforeach
</div>
