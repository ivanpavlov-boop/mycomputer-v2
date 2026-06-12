<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmailSubscriberResource;
use App\Services\Email\EmailMarketingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    public function subscribe(Request $request, EmailMarketingService $emailMarketing): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'in:checkout,account,newsletter,popup,import'],
            'gdpr_consent' => ['accepted'],
        ]);

        $subscriber = $emailMarketing->subscribe($data, $request->user());

        return response()->json(['data' => EmailSubscriberResource::make($subscriber)], 201);
    }

    public function unsubscribe(Request $request, EmailMarketingService $emailMarketing): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $subscriber = $emailMarketing->unsubscribe($data['email']);

        return response()->json(['data' => EmailSubscriberResource::make($subscriber)]);
    }

    public function status(Request $request, EmailMarketingService $emailMarketing): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $subscriber = $emailMarketing->status($data['email']);

        return response()->json([
            'data' => [
                'email' => strtolower($data['email']),
                'status' => $subscriber?->status ?? 'not_subscribed',
                'subscribed_at' => $subscriber?->subscribed_at,
                'unsubscribed_at' => $subscriber?->unsubscribed_at,
            ],
        ]);
    }
}
