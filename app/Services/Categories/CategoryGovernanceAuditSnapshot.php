<?php

namespace App\Services\Categories;

final readonly class CategoryGovernanceAuditSnapshot
{
    /**
     * @param  array<string, int|array<string, int>>  $summary
     * @param  array<int, int>  $depthDistribution
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<int, array<string, mixed>>  $issues
     */
    public function __construct(
        public string $generatedAt,
        public array $summary,
        public array $depthDistribution,
        public array $categories,
        public array $issues,
    ) {}

    /**
     * @return array{
     *     generated_at: string,
     *     summary: array<string, int|array<string, int>>,
     *     depth_distribution: array<int, int>,
     *     categories: array<int, array<string, mixed>>,
     *     issues: array<int, array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt,
            'summary' => $this->summary,
            'depth_distribution' => $this->depthDistribution,
            'categories' => $this->categories,
            'issues' => $this->issues,
        ];
    }
}
