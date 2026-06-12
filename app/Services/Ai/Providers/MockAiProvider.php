<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiProviderInterface;

class MockAiProvider implements AiProviderInterface
{
    public function chat(array $messages, array $context = []): array
    {
        $last = collect($messages)->where('role', 'user')->last()['content'] ?? '';

        return [
            'content' => 'AI асистентът анализира заявката: '.$last.'. Мога да предложа продукти, алтернативи, сравнение и насоки за покупка.',
            'metadata' => ['provider' => 'mock'],
        ];
    }

    public function recommend(string $query, array $products, array $context = []): array
    {
        return [
            'summary' => 'Подбрах продукти според заявката: '.$query,
            'reasoning' => collect($products)->map(fn (array $product): string => $product['name'].' е подходящ избор спрямо цена, наличност и категория.')->values()->all(),
            'provider' => 'mock',
        ];
    }

    public function explainComparison(array $products, array $comparison): array
    {
        return [
            'summary' => 'Сравнението показва основните разлики в цена, наличност и спецификации.',
            'strengths' => collect($products)->mapWithKeys(fn (array $product): array => [$product['id'] => ['Добър баланс между цена и характеристики.']])->all(),
            'weaknesses' => collect($products)->mapWithKeys(fn (array $product): array => [$product['id'] => ['Проверете гаранция, наличност и точни спецификации преди покупка.']])->all(),
            'use_cases' => collect($products)->mapWithKeys(fn (array $product): array => [$product['id'] => ['Офис работа', 'Домашна употреба', 'Обучение']])->all(),
            'provider' => 'mock',
        ];
    }

    public function buyingGuide(string $topic, array $context = []): array
    {
        return [
            'title' => 'Как да изберем: '.$topic,
            'content' => 'Започнете с бюджет, предназначение, гаранция и ключови характеристики. За лаптопи гледайте процесор, RAM, SSD, видеокарта и дисплей. За монитори гледайте панел, резолюция, честота и цветово покритие.',
            'checklist' => ['Определете бюджет', 'Изберете категория', 'Сравнете ключови характеристики', 'Проверете наличност и гаранция'],
            'provider' => 'mock',
        ];
    }
}
