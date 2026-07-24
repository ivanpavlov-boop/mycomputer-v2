<?php

namespace App\Services\Cart;

final readonly class CartLineReadiness
{
    public function __construct(
        public bool $isEligible,
        public bool $canCheckout,
        public array $issues,
        public array $stock,
    ) {}

    public function toArray(): array
    {
        return [
            'is_eligible' => $this->isEligible,
            'can_checkout' => $this->canCheckout,
            'issues' => $this->issues,
            'stock' => $this->stock,
        ];
    }

    public function issueCodes(): array
    {
        return array_column($this->issues, 'code');
    }

    public function hasIssue(string $code): bool
    {
        return in_array($code, $this->issueCodes(), true);
    }
}
