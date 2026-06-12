<?php

namespace App\Services\Search\Contracts;

interface SearchServiceInterface
{
    public function search(array $criteria): array;

    public function suggestions(string $query): array;

    public function categoryFilters(string $slug): array;

    public function indexedProductsCount(): int;

    public function status(): array;

    public function reindex(): int;

    public function flush(): void;
}
