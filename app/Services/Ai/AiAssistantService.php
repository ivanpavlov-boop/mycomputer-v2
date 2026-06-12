<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\Contracts\AiProviderInterface;
use Illuminate\Support\Str;

class AiAssistantService
{
    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly ProductRecommendationService $recommendations,
        private readonly BuyingGuideService $guides,
    ) {}

    public function chat(string $message, ?User $user = null, ?string $sessionId = null, ?int $conversationId = null): AiConversation
    {
        $conversation = $this->resolveConversation($message, $user, $sessionId, $conversationId);
        $conversation->messages()->create(['role' => 'user', 'content' => $message]);

        $recommendation = $this->recommendations->recommend($message, $user, $sessionId);
        $guide = $this->guides->guide($message);
        $response = $this->provider->chat($conversation->messages()->get(['role', 'content'])->toArray(), [
            'recommendation' => $recommendation,
            'guide' => $guide,
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $response['content'],
            'metadata' => [
                'recommendation' => $recommendation,
                'guide' => $guide,
                'provider' => $response['metadata']['provider'] ?? 'mock',
            ],
        ]);

        return $conversation->fresh(['messages']);
    }

    public function conversations(?User $user = null, ?string $sessionId = null)
    {
        return AiConversation::query()
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->when(! $user, fn ($query) => $query->where('session_id', $sessionId))
            ->with('messages')
            ->latest()
            ->get();
    }

    public function resolveOwned(int $conversationId, ?User $user = null, ?string $sessionId = null): AiConversation
    {
        return AiConversation::query()
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->when(! $user, fn ($query) => $query->where('session_id', $sessionId))
            ->with('messages')
            ->findOrFail($conversationId);
    }

    private function resolveConversation(string $message, ?User $user, ?string $sessionId, ?int $conversationId): AiConversation
    {
        if ($conversationId) {
            return $this->resolveOwned($conversationId, $user, $sessionId);
        }

        return AiConversation::query()->create([
            'user_id' => $user?->id,
            'session_id' => $user ? null : ($sessionId ?: (string) Str::uuid()),
            'title' => Str::limit($message, 80),
        ]);
    }
}
