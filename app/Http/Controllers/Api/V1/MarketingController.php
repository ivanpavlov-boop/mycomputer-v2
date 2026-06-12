<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketingEventResource;
use App\Jobs\AnalyticsEventJob;
use App\Models\MarketingEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class MarketingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'event_name' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'in:'.implode(',', MarketingEvent::SOURCES)],
            'status' => ['nullable', 'in:'.implode(',', MarketingEvent::STATUSES)],
        ]);

        return MarketingEventResource::collection(
            MarketingEvent::query()
                ->with('user')
                ->when($data['event_name'] ?? null, fn ($query, string $eventName) => $query->where('event_name', $eventName))
                ->when($data['source'] ?? null, fn ($query, string $source) => $query->where('source', $source))
                ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->latest()
                ->paginate(min((int) $request->integer('per_page', 25), 100))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_name' => ['required', 'string', 'max:100'],
            'source' => ['nullable', 'in:'.implode(',', MarketingEvent::SOURCES)],
            'payload' => ['nullable', 'array'],
        ]);

        AnalyticsEventJob::dispatch(
            $data['event_name'],
            $data['source'] ?? 'internal',
            $data['payload'] ?? [],
            Auth::guard('sanctum')->id(),
            $request->header('X-Marketing-Session'),
        );

        return response()->json([
            'data' => [
                'status' => 'queued',
                'event_name' => $data['event_name'],
                'source' => $data['source'] ?? 'internal',
            ],
        ], 202);
    }
}
