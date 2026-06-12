<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateFeedJob;
use App\Models\FeedExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'data' => [
                'latest' => FeedExport::query()->latest()->limit(10)->get(),
                'counts' => FeedExport::query()
                    ->selectRaw('feed_type, count(*) as total')
                    ->groupBy('feed_type')
                    ->pluck('total', 'feed_type'),
            ],
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'feed_type' => ['required', 'in:google_merchant,facebook_catalog'],
        ]);

        GenerateFeedJob::dispatch($data['feed_type']);

        return response()->json(['data' => ['status' => 'queued', 'feed_type' => $data['feed_type']]], 202);
    }
}
