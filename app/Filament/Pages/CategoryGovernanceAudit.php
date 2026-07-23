<?php

namespace App\Filament\Pages;

use App\Models\Category;
use App\Services\Categories\CategoryGovernanceAuditService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use UnitEnum;

class CategoryGovernanceAudit extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Одит на категориите';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 31;

    protected static ?string $title = 'Одит на категориите';

    protected string $view = 'filament.pages.category-governance-audit';

    public string $search = '';

    public string $severity = 'all';

    public string $issueCode = 'all';

    public string $status = 'all';

    public string $depth = 'all';

    public string $scope = 'all';

    public string $directProducts = 'all';

    public string $publishedSubtree = 'all';

    public string $zeroSortOrder = 'all';

    public string $missingBulgarian = 'all';

    public string $sortBy = 'path';

    public string $sortDirection = 'asc';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $snapshotCache = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('viewAny', Category::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuditSnapshot(): array
    {
        return $this->snapshotCache ??= app(CategoryGovernanceAuditService::class)
            ->snapshot()
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFilteredCategories(): array
    {
        $rows = $this->getAuditSnapshot()['categories'];
        $search = Str::lower(trim($this->search));

        $rows = array_values(array_filter($rows, function (array $row) use ($search): bool {
            if ($search !== '') {
                $haystack = Str::lower(implode(' ', [
                    $row['name'],
                    $row['slug'],
                    $row['full_path'],
                ]));

                if (! str_contains($haystack, $search)) {
                    return false;
                }
            }

            if ($this->severity !== 'all' && $row['highest_severity'] !== $this->severity) {
                return false;
            }

            if ($this->issueCode !== 'all' && ! in_array($this->issueCode, $row['issue_codes'], true)) {
                return false;
            }

            if ($this->status !== 'all' && $this->rowStatus($row) !== $this->status) {
                return false;
            }

            if ($this->depth !== 'all' && (string) ($row['depth'] ?? '') !== $this->depth) {
                return false;
            }

            if ($this->scope === 'root' && $row['parent_id'] !== null) {
                return false;
            }

            if ($this->scope === 'non_root' && $row['parent_id'] === null) {
                return false;
            }

            if ($this->directProducts === 'with' && $row['direct_product_count'] === 0) {
                return false;
            }

            if ($this->directProducts === 'without' && $row['direct_product_count'] > 0) {
                return false;
            }

            if ($this->publishedSubtree === 'with' && $row['published_subtree_product_count'] === 0) {
                return false;
            }

            if ($this->publishedSubtree === 'without' && $row['published_subtree_product_count'] > 0) {
                return false;
            }

            if ($this->zeroSortOrder === 'yes' && $row['sort_order'] !== 0) {
                return false;
            }

            if ($this->zeroSortOrder === 'no' && $row['sort_order'] === 0) {
                return false;
            }

            $hasMissingBulgarian = in_array('missing_explicit_bg_translation', $row['issue_codes'], true)
                || in_array('possible_latin_only_public_name', $row['issue_codes'], true);

            if ($this->missingBulgarian === 'yes' && ! $hasMissingBulgarian) {
                return false;
            }

            if ($this->missingBulgarian === 'no' && $hasMissingBulgarian) {
                return false;
            }

            return true;
        }));

        usort($rows, function (array $left, array $right): int {
            $comparison = $this->sortValue($left) <=> $this->sortValue($right);

            if ($comparison === 0) {
                $comparison = $left['id'] <=> $right['id'];
            }

            return $this->sortDirection === 'desc' ? -$comparison : $comparison;
        });

        return $rows;
    }

    /**
     * @return array<string, array{severity: string, label: string, recommendation: string}>
     */
    public function getIssueDefinitions(): array
    {
        return CategoryGovernanceAuditService::issueDefinitions();
    }

    public function resetAuditFilters(): void
    {
        $this->search = '';
        $this->severity = 'all';
        $this->issueCode = 'all';
        $this->status = 'all';
        $this->depth = 'all';
        $this->scope = 'all';
        $this->directProducts = 'all';
        $this->publishedSubtree = 'all';
        $this->zeroSortOrder = 'all';
        $this->missingBulgarian = 'all';
        $this->sortBy = 'path';
        $this->sortDirection = 'asc';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function rowStatus(array $row): string
    {
        if ($row['is_deleted']) {
            return 'deleted';
        }

        return $row['is_active'] ? 'active' : 'inactive';
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Активна',
            'inactive' => 'Неактивна',
            'deleted' => 'Изтрита',
            default => 'Неизвестно',
        };
    }

    public function severityLabel(string $severity): string
    {
        return match ($severity) {
            'critical' => 'Критичен',
            'warning' => 'Предупреждение',
            'info' => 'Информация',
            'none' => 'Без проблеми',
            default => 'Неизвестно',
        };
    }

    public function severityClasses(string $severity): string
    {
        return match ($severity) {
            'critical' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400',
            'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400',
            'info' => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-400/10 dark:text-info-400',
            default => 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-400',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sortValue(array $row): int|string
    {
        return match ($this->sortBy) {
            'depth' => (int) ($row['depth'] ?? PHP_INT_MAX),
            'status' => $this->rowStatus($row),
            'direct_products' => $row['direct_product_count'],
            'published_direct' => $row['published_direct_product_count'],
            'published_subtree' => $row['published_subtree_product_count'],
            'sort_order' => $row['sort_order'],
            'issues' => count($row['issue_codes']),
            default => Str::lower($row['full_path']),
        };
    }
}
