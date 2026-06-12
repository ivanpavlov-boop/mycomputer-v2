<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiConversationResource;
use App\Services\Ai\AiAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class AiChatController extends Controller
{
    public function __construct(private readonly AiAssistantService $assistant) {}

    public function chat(Request $request): AiConversationResource
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        return AiConversationResource::make(
            $this->assistant->chat($data['message'], Auth::guard('sanctum')->user(), $this->sessionId($request), $data['conversation_id'] ?? null)
        );
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return AiConversationResource::collection(
            $this->assistant->conversations(Auth::guard('sanctum')->user(), $this->sessionId($request))
        );
    }

    public function show(Request $request, int $conversation): AiConversationResource
    {
        return AiConversationResource::make(
            $this->assistant->resolveOwned($conversation, Auth::guard('sanctum')->user(), $this->sessionId($request))
        );
    }

    public function destroy(Request $request, int $conversation): JsonResponse
    {
        $this->assistant->resolveOwned($conversation, Auth::guard('sanctum')->user(), $this->sessionId($request))->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function sessionId(Request $request): ?string
    {
        return $request->header('X-AI-Session');
    }
}
