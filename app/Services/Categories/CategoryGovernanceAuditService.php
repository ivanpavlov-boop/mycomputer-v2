<?php

namespace App\Services\Categories;

use App\Models\Category;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CategoryGovernanceAuditService
{
    /**
     * @var array<string, array{severity: string, label: string, recommendation: string}>
     */
    private const ISSUE_DEFINITIONS = [
        'cycle' => [
            'severity' => 'critical',
            'label' => 'Цикъл в йерархията',
            'recommendation' => 'Проверете и коригирайте ръчно родителските връзки, които образуват цикъл.',
        ],
        'orphan_parent' => [
            'severity' => 'critical',
            'label' => 'Липсваща родителска категория',
            'recommendation' => 'Задайте съществуваща родителска категория или преместете категорията на основно ниво.',
        ],
        'duplicate_slug' => [
            'severity' => 'critical',
            'label' => 'Дублиран slug',
            'recommendation' => 'Прегледайте дублираните slug стойности и задайте уникални публични адреси.',
        ],
        'missing_slug' => [
            'severity' => 'critical',
            'label' => 'Липсва slug',
            'recommendation' => 'Добавете уникален slug след ръчна проверка на публичния адрес.',
        ],
        'missing_name' => [
            'severity' => 'critical',
            'label' => 'Липсва име',
            'recommendation' => 'Добавете ясно публично име на категорията.',
        ],
        'unreachable_from_root' => [
            'severity' => 'warning',
            'label' => 'Недостъпна от основна категория',
            'recommendation' => 'Прегледайте родителската верига и публичния статус на всички предшественици.',
        ],
        'active_under_inactive_parent' => [
            'severity' => 'warning',
            'label' => 'Активна под неактивен родител',
            'recommendation' => 'Проверете дали родителят трябва да бъде активен или категорията трябва да бъде преместена.',
        ],
        'active_under_deleted_parent' => [
            'severity' => 'warning',
            'label' => 'Активна под изтрит родител',
            'recommendation' => 'Проверете дали родителят трябва да бъде възстановен или категорията трябва да бъде преместена.',
        ],
        'duplicate_normalized_name' => [
            'severity' => 'warning',
            'label' => 'Дублирано нормализирано име',
            'recommendation' => 'Сравнете категориите ръчно и преценете дали имената или структурата трябва да се уточнят.',
        ],
        'suspicious_name_punctuation' => [
            'severity' => 'warning',
            'label' => 'Подозрителна пунктуация в името',
            'recommendation' => 'Проверете ръчно името за излишни кавички или пунктуация.',
        ],
        'no_published_products_in_subtree' => [
            'severity' => 'warning',
            'label' => 'Няма публикувани продукти в дървото',
            'recommendation' => 'Проверете дали категорията трябва да остане публична или дали продуктите са разпределени неправилно.',
        ],
        'zero_sort_order' => [
            'severity' => 'info',
            'label' => 'Нулева подредба',
            'recommendation' => 'Прегледайте позицията на категорията спрямо съседните категории.',
        ],
        'sibling_sort_order_collision' => [
            'severity' => 'info',
            'label' => 'Еднаква подредба между съседни категории',
            'recommendation' => 'Прегледайте ръчно реда на категориите с еднаква стойност за подредба.',
        ],
        'no_direct_products' => [
            'severity' => 'info',
            'label' => 'Няма директно зададени продукти',
            'recommendation' => 'Проверете дали категорията служи само като група или очаква директно зададени продукти.',
        ],
        'no_published_direct_products' => [
            'severity' => 'info',
            'label' => 'Няма директно публикувани продукти',
            'recommendation' => 'Прегледайте статуса и категоризацията на директно зададените продукти.',
        ],
        'missing_explicit_bg_translation' => [
            'severity' => 'info',
            'label' => 'Липсва изричен български превод',
            'recommendation' => 'Добавете изрична българска локализация при следваща ръчна редакция.',
        ],
        'possible_latin_only_public_name' => [
            'severity' => 'info',
            'label' => 'Възможно име само на латиница',
            'recommendation' => 'Проверете дали категорията има подходящо българско публично име.',
        ],
    ];

    /**
     * @var array<string, int>
     */
    private const SEVERITY_RANK = [
        'none' => 0,
        'info' => 1,
        'warning' => 2,
        'critical' => 3,
    ];

    /**
     * @return array<string, array{severity: string, label: string, recommendation: string}>
     */
    public static function issueDefinitions(): array
    {
        return self::ISSUE_DEFINITIONS;
    }

    public function snapshot(): CategoryGovernanceAuditSnapshot
    {
        $categories = Category::query()
            ->withTrashed()
            ->select([
                'id',
                'parent_id',
                'name',
                'name_translations',
                'slug',
                'is_active',
                'sort_order',
                'deleted_at',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->get();

        $directProductCounts = Product::query()
            ->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) AS aggregate')
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $publishedProductCounts = Product::query()
            ->published()
            ->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) AS aggregate')
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return $this->analyze(
            $categories,
            $directProductCounts,
            $publishedProductCounts,
            now(),
        );
    }

    /**
     * Public analysis seam for deterministic malformed-hierarchy tests.
     *
     * @param  Collection<int, Category>  $categories
     * @param  array<int|string, int>  $directProductCounts
     * @param  array<int|string, int>  $publishedProductCounts
     */
    public function analyze(
        Collection $categories,
        array $directProductCounts = [],
        array $publishedProductCounts = [],
        ?CarbonInterface $generatedAt = null,
    ): CategoryGovernanceAuditSnapshot {
        $categoriesById = $categories
            ->filter(fn (Category $category): bool => is_numeric($category->getKey()))
            ->keyBy(fn (Category $category): int => (int) $category->getKey());

        $childrenByParent = [];

        foreach ($categoriesById as $category) {
            $parentId = $category->parent_id === null ? null : (int) $category->parent_id;

            if ($parentId !== null && isset($categoriesById[$parentId])) {
                $childrenByParent[$parentId][] = (int) $category->id;
            }
        }

        foreach ($childrenByParent as &$childIds) {
            usort($childIds, fn (int $left, int $right): int => $this->compareCategories(
                $categoriesById[$left],
                $categoriesById[$right],
            ));
        }
        unset($childIds);

        $hierarchy = [];
        $cycleIds = [];

        foreach ($categoriesById->keys() as $categoryId) {
            $resolved = $this->resolveHierarchy((int) $categoryId, $categoriesById);
            $hierarchy[(int) $categoryId] = $resolved;

            foreach ($resolved['cycle_ids'] as $cycleId) {
                $cycleIds[$cycleId] = true;
            }
        }

        $duplicateSlugs = $this->duplicateGroups(
            $categoriesById,
            fn (Category $category): string => Str::lower(trim((string) $category->slug)),
        );
        $duplicateNames = $this->duplicateGroups(
            $categoriesById,
            fn (Category $category): string => $this->normalizeName((string) $category->name),
        );
        $sortCollisions = $this->sortOrderCollisions($categoriesById);

        $rows = [];
        $allIssues = [];
        $depthDistribution = [];

        foreach ($categoriesById as $categoryId => $category) {
            $categoryId = (int) $categoryId;
            $resolved = $hierarchy[$categoryId];
            $descendantIds = $this->descendantIds($categoryId, $childrenByParent);
            $directCount = (int) ($directProductCounts[$categoryId] ?? 0);
            $publishedDirectCount = (int) ($publishedProductCounts[$categoryId] ?? 0);
            $publishedSubtreeCount = $publishedDirectCount;

            foreach ($descendantIds as $descendantId) {
                $publishedSubtreeCount += (int) ($publishedProductCounts[$descendantId] ?? 0);
            }

            $isDeleted = $category->deleted_at !== null;
            $isActive = (bool) $category->is_active;
            $publiclyReachable = $this->isPubliclyReachable($resolved, $categoriesById);
            $issueCodes = [];

            if (isset($cycleIds[$categoryId])) {
                $issueCodes[] = 'cycle';
            }

            if ($category->parent_id !== null && ! isset($categoriesById[(int) $category->parent_id])) {
                $issueCodes[] = 'orphan_parent';
            }

            if (! $resolved['structurally_reachable'] || ($isActive && ! $isDeleted && ! $publiclyReachable)) {
                $issueCodes[] = 'unreachable_from_root';
            }

            if ($isActive && ! $isDeleted && $this->hasInactiveAncestor($resolved, $categoriesById)) {
                $issueCodes[] = 'active_under_inactive_parent';
            }

            if ($isActive && ! $isDeleted && $this->hasDeletedAncestor($resolved, $categoriesById)) {
                $issueCodes[] = 'active_under_deleted_parent';
            }

            $slugKey = Str::lower(trim((string) $category->slug));
            $nameKey = $this->normalizeName((string) $category->name);

            if ($slugKey === '') {
                $issueCodes[] = 'missing_slug';
            } elseif (isset($duplicateSlugs[$slugKey])) {
                $issueCodes[] = 'duplicate_slug';
            }

            if ($nameKey === '') {
                $issueCodes[] = 'missing_name';
            } elseif (isset($duplicateNames[$nameKey])) {
                $issueCodes[] = 'duplicate_normalized_name';
            }

            if (isset($sortCollisions[$categoryId])) {
                $issueCodes[] = 'sibling_sort_order_collision';
            }

            if ((int) $category->sort_order === 0) {
                $issueCodes[] = 'zero_sort_order';
            }

            if ($this->hasSuspiciousPunctuation((string) $category->name)) {
                $issueCodes[] = 'suspicious_name_punctuation';
            }

            if (! $this->hasExplicitBulgarianName($category)) {
                $issueCodes[] = 'missing_explicit_bg_translation';
            }

            if ($this->isPossiblyLatinOnly((string) $category->name)) {
                $issueCodes[] = 'possible_latin_only_public_name';
            }

            if (! $isDeleted && $directCount === 0) {
                $issueCodes[] = 'no_direct_products';
            }

            if (! $isDeleted && $isActive && $publishedDirectCount === 0) {
                $issueCodes[] = 'no_published_direct_products';
            }

            if (! $isDeleted && $isActive && $publishedSubtreeCount === 0) {
                $issueCodes[] = 'no_published_products_in_subtree';
            }

            $issueCodes = array_values(array_unique($issueCodes));
            usort($issueCodes, fn (string $left, string $right): int => $this->compareIssueCodes($left, $right));

            $rowIssues = array_map(
                fn (string $code): array => $this->issuePayload($category, $resolved['full_path'], $code),
                $issueCodes,
            );
            array_push($allIssues, ...$rowIssues);

            if ($resolved['structurally_reachable'] && ! $isDeleted && $resolved['depth'] !== null) {
                $depthDistribution[$resolved['depth']] = ($depthDistribution[$resolved['depth']] ?? 0) + 1;
            }

            $rows[] = [
                'id' => $categoryId,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                'parent_name' => $category->parent_id !== null && isset($categoriesById[(int) $category->parent_id])
                    ? (string) $categoriesById[(int) $category->parent_id]->name
                    : null,
                'full_path' => $resolved['full_path'],
                'depth' => $resolved['depth'],
                'is_active' => $isActive,
                'is_deleted' => $isDeleted,
                'is_publicly_reachable' => $publiclyReachable,
                'sort_order' => (int) $category->sort_order,
                'children_count' => count($childrenByParent[$categoryId] ?? []),
                'direct_product_count' => $directCount,
                'published_direct_product_count' => $publishedDirectCount,
                'published_subtree_product_count' => $publishedSubtreeCount,
                'descendant_count' => count($descendantIds),
                'public_url' => $slugKey === '' ? null : '/c/'.ltrim((string) $category->slug, '/'),
                'issue_codes' => $issueCodes,
                'issues' => $rowIssues,
                'highest_severity' => $this->highestSeverity($issueCodes),
            ];
        }

        usort($rows, fn (array $left, array $right): int => [
            Str::lower($left['full_path']),
            $left['id'],
        ] <=> [
            Str::lower($right['full_path']),
            $right['id'],
        ]);
        usort($allIssues, fn (array $left, array $right): int => [
            -self::SEVERITY_RANK[$left['severity']],
            $left['code'],
            $left['category_id'],
        ] <=> [
            -self::SEVERITY_RANK[$right['severity']],
            $right['code'],
            $right['category_id'],
        ]);
        ksort($depthDistribution);

        $notDeletedRows = array_values(array_filter($rows, fn (array $row): bool => ! $row['is_deleted']));
        $activeRows = array_values(array_filter(
            $notDeletedRows,
            fn (array $row): bool => $row['is_active'],
        ));
        $issueCountByCode = array_fill_keys(array_keys(self::ISSUE_DEFINITIONS), 0);
        $issueCountBySeverity = array_fill_keys(['critical', 'warning', 'info'], 0);

        foreach ($allIssues as $issue) {
            $issueCountByCode[$issue['code']]++;
            $issueCountBySeverity[$issue['severity']]++;
        }

        $summary = [
            'all_including_deleted' => count($rows),
            'not_deleted' => count($notDeletedRows),
            'active' => count($activeRows),
            'inactive' => count(array_filter($notDeletedRows, fn (array $row): bool => ! $row['is_active'])),
            'soft_deleted' => count(array_filter($rows, fn (array $row): bool => $row['is_deleted'])),
            'root_categories' => count(array_filter($notDeletedRows, fn (array $row): bool => $row['parent_id'] === null)),
            'non_root_categories' => count(array_filter($notDeletedRows, fn (array $row): bool => $row['parent_id'] !== null)),
            'maximum_depth' => $depthDistribution === [] ? 0 : max(array_keys($depthDistribution)),
            'reachable_active_categories' => count(array_filter(
                $activeRows,
                fn (array $row): bool => $row['is_publicly_reachable'],
            )),
            'unreachable_active_categories' => count(array_filter(
                $activeRows,
                fn (array $row): bool => ! $row['is_publicly_reachable'],
            )),
            'categories_with_direct_products' => count(array_filter(
                $notDeletedRows,
                fn (array $row): bool => $row['direct_product_count'] > 0,
            )),
            'categories_with_published_direct_products' => count(array_filter(
                $activeRows,
                fn (array $row): bool => $row['published_direct_product_count'] > 0,
            )),
            'categories_with_published_products_in_subtree' => count(array_filter(
                $activeRows,
                fn (array $row): bool => $row['published_subtree_product_count'] > 0,
            )),
            'categories_without_direct_products' => count(array_filter(
                $notDeletedRows,
                fn (array $row): bool => $row['direct_product_count'] === 0,
            )),
            'categories_without_published_products_in_subtree' => count(array_filter(
                $activeRows,
                fn (array $row): bool => $row['published_subtree_product_count'] === 0,
            )),
            'total_issue_count' => count($allIssues),
            'issue_count_by_code' => $issueCountByCode,
            'issue_count_by_severity' => $issueCountBySeverity,
        ];

        return new CategoryGovernanceAuditSnapshot(
            generatedAt: ($generatedAt ?? now())->toIso8601String(),
            summary: $summary,
            depthDistribution: $depthDistribution,
            categories: $rows,
            issues: $allIssues,
        );
    }

    /**
     * @param  Collection<int, Category>  $categoriesById
     * @return array{structurally_reachable: bool, path_ids: array<int, int>, cycle_ids: array<int, int>, depth: ?int, full_path: string}
     */
    private function resolveHierarchy(int $categoryId, Collection $categoriesById): array
    {
        $chain = [];
        $positions = [];
        $currentId = $categoryId;
        $structurallyReachable = false;
        $cycleIds = [];

        while (true) {
            if (isset($positions[$currentId])) {
                $cycleIds = array_slice($chain, $positions[$currentId]);
                break;
            }

            $category = $categoriesById->get($currentId);

            if (! $category instanceof Category) {
                break;
            }

            $positions[$currentId] = count($chain);
            $chain[] = $currentId;

            if ($category->parent_id === null) {
                $structurallyReachable = true;
                break;
            }

            $currentId = (int) $category->parent_id;
        }

        $pathIds = array_reverse($chain);
        $names = array_map(
            fn (int $id): string => $this->displayName($categoriesById->get($id)),
            $pathIds,
        );

        return [
            'structurally_reachable' => $structurallyReachable,
            'path_ids' => $pathIds,
            'cycle_ids' => $cycleIds,
            'depth' => $structurallyReachable ? count($pathIds) : null,
            'full_path' => implode(' › ', $names),
        ];
    }

    /**
     * @param  array{structurally_reachable: bool, path_ids: array<int, int>}  $resolved
     * @param  Collection<int, Category>  $categoriesById
     */
    private function isPubliclyReachable(array $resolved, Collection $categoriesById): bool
    {
        if (! $resolved['structurally_reachable']) {
            return false;
        }

        foreach ($resolved['path_ids'] as $categoryId) {
            $category = $categoriesById->get($categoryId);

            if (! $category instanceof Category || ! $category->is_active || $category->deleted_at !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{path_ids: array<int, int>}  $resolved
     * @param  Collection<int, Category>  $categoriesById
     */
    private function hasInactiveAncestor(array $resolved, Collection $categoriesById): bool
    {
        foreach (array_slice($resolved['path_ids'], 0, -1) as $categoryId) {
            $category = $categoriesById->get($categoryId);

            if ($category instanceof Category && ! $category->is_active) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{path_ids: array<int, int>}  $resolved
     * @param  Collection<int, Category>  $categoriesById
     */
    private function hasDeletedAncestor(array $resolved, Collection $categoriesById): bool
    {
        foreach (array_slice($resolved['path_ids'], 0, -1) as $categoryId) {
            $category = $categoriesById->get($categoryId);

            if ($category instanceof Category && $category->deleted_at !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<int, int>>  $childrenByParent
     * @return array<int, int>
     */
    private function descendantIds(int $categoryId, array $childrenByParent): array
    {
        $seen = [$categoryId => true];
        $descendants = [];
        $stack = array_reverse($childrenByParent[$categoryId] ?? []);

        while ($stack !== []) {
            $currentId = array_pop($stack);

            if (isset($seen[$currentId])) {
                continue;
            }

            $seen[$currentId] = true;
            $descendants[] = $currentId;

            foreach (array_reverse($childrenByParent[$currentId] ?? []) as $childId) {
                $stack[] = $childId;
            }
        }

        return $descendants;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<string, array<int, int>>
     */
    private function duplicateGroups(Collection $categories, callable $keyResolver): array
    {
        $groups = [];

        foreach ($categories as $category) {
            $key = $keyResolver($category);

            if ($key !== '') {
                $groups[$key][] = (int) $category->id;
            }
        }

        return array_filter($groups, fn (array $ids): bool => count($ids) > 1);
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<int, true>
     */
    private function sortOrderCollisions(Collection $categories): array
    {
        $groups = [];

        foreach ($categories as $category) {
            $parentKey = $category->parent_id === null ? 'root' : (string) $category->parent_id;
            $groups[$parentKey.':'.(int) $category->sort_order][] = (int) $category->id;
        }

        $collisions = [];

        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }

            foreach ($ids as $id) {
                $collisions[$id] = true;
            }
        }

        return $collisions;
    }

    private function normalizeName(string $name): string
    {
        return Str::lower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    private function hasSuspiciousPunctuation(string $name): bool
    {
        $name = trim($name);

        return $name !== '' && (
            preg_match('/^[,.;:!?]/u', $name) === 1
            || preg_match('/["\'„“”«»]$/u', $name) === 1
            || preg_match('/[!?.,;:]{2,}$/u', $name) === 1
        );
    }

    private function hasExplicitBulgarianName(Category $category): bool
    {
        $translations = $category->name_translations;

        return is_array($translations) && filled($translations['bg'] ?? null);
    }

    private function isPossiblyLatinOnly(string $name): bool
    {
        return preg_match('/\p{Latin}/u', $name) === 1
            && preg_match('/\p{Cyrillic}/u', $name) !== 1;
    }

    private function compareCategories(Category $left, Category $right): int
    {
        return [
            (int) $left->sort_order,
            $this->normalizeName((string) $left->name),
            (int) $left->id,
        ] <=> [
            (int) $right->sort_order,
            $this->normalizeName((string) $right->name),
            (int) $right->id,
        ];
    }

    private function compareIssueCodes(string $left, string $right): int
    {
        return [
            -self::SEVERITY_RANK[self::ISSUE_DEFINITIONS[$left]['severity']],
            $left,
        ] <=> [
            -self::SEVERITY_RANK[self::ISSUE_DEFINITIONS[$right]['severity']],
            $right,
        ];
    }

    /**
     * @return array{category_id: int, category_name: string, full_path: string, code: string, label: string, severity: string, recommendation: string}
     */
    private function issuePayload(Category $category, string $fullPath, string $code): array
    {
        $definition = self::ISSUE_DEFINITIONS[$code];

        return [
            'category_id' => (int) $category->id,
            'category_name' => (string) $category->name,
            'full_path' => $fullPath,
            'code' => $code,
            'label' => $definition['label'],
            'severity' => $definition['severity'],
            'recommendation' => $definition['recommendation'],
        ];
    }

    /**
     * @param  array<int, string>  $issueCodes
     */
    private function highestSeverity(array $issueCodes): string
    {
        $severity = 'none';

        foreach ($issueCodes as $code) {
            $candidate = self::ISSUE_DEFINITIONS[$code]['severity'];

            if (self::SEVERITY_RANK[$candidate] > self::SEVERITY_RANK[$severity]) {
                $severity = $candidate;
            }
        }

        return $severity;
    }

    private function displayName(mixed $category): string
    {
        if (! $category instanceof Category || blank($category->name)) {
            return '(без име)';
        }

        return trim((string) $category->name);
    }
}
