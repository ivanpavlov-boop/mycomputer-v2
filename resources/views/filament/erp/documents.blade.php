<div class="space-y-3">
    @forelse ($documents as $document)
        <div class="rounded-lg border border-gray-200 p-3">
            <div class="font-semibold">{{ $document->document_type }} · {{ $document->status }}</div>
            <div class="text-sm text-gray-600">
                Number: {{ $document->document_number ?? 'N/A' }} · External ID: {{ $document->external_id ?? 'N/A' }}
            </div>
        </div>
    @empty
        <div class="text-sm text-gray-600">No ERP documents for this order yet.</div>
    @endforelse
</div>
