<?php

namespace App\Services\Ai\Contracts;

interface AiProviderInterface
{
    public function chat(array $messages, array $context = []): array;

    public function recommend(string $query, array $products, array $context = []): array;

    public function explainComparison(array $products, array $comparison): array;

    public function buyingGuide(string $topic, array $context = []): array;
}
