<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProviderInterface;

class BuyingGuideService
{
    public function __construct(private readonly AiProviderInterface $provider) {}

    public function guide(string $topic): array
    {
        return $this->provider->buyingGuide($topic);
    }
}
