<x-filament-panels::page>
    @php
        $audit = $this->getAuditSnapshot();
        $summary = $audit['summary'];
        $rows = $this->getFilteredCategories();
        $issueDefinitions = $this->getIssueDefinitions();
        $depths = array_keys($audit['depth_distribution']);
    @endphp

    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['Общо категории', $summary['all_including_deleted']],
                ['Активни', $summary['active']],
                ['Основни категории', $summary['root_categories']],
                ['Максимална дълбочина', $summary['maximum_depth']],
                ['Без публикувани продукти', $summary['categories_without_published_products_in_subtree']],
                ['Критични проблеми', $summary['issue_count_by_severity']['critical']],
                ['Предупреждения', $summary['issue_count_by_severity']['warning']],
                ['Информационни сигнали', $summary['issue_count_by_severity']['info']],
            ] as [$label, $value])
                <x-filament::section>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</p>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section>
            <x-slot name="heading">Филтри и подредба</x-slot>
            <x-slot name="description">
                Одитът е само за преглед. Той не променя категории, продукти или публичната навигация.
            </x-slot>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <label class="space-y-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Търсене</span>
                    <input
                        wire:model.live.debounce.300ms="search"
                        type="search"
                        placeholder="Име, slug или път"
                        class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5"
                    >
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Важност</span>
                    <select wire:model.live="severity" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="all">Всички</option>
                        <option value="critical">Критични</option>
                        <option value="warning">Предупреждения</option>
                        <option value="info">Информационни</option>
                        <option value="none">Без проблеми</option>
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Проблем</span>
                    <select wire:model.live="issueCode" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="all">Всички</option>
                        @foreach ($issueDefinitions as $code => $definition)
                            <option value="{{ $code }}">{{ $definition['label'] }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Статус</span>
                    <select wire:model.live="status" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="all">Всички</option>
                        <option value="active">Активни</option>
                        <option value="inactive">Неактивни</option>
                        <option value="deleted">Изтрити</option>
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Ниво</span>
                    <select wire:model.live="depth" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="all">Всички</option>
                        @foreach ($depths as $depth)
                            <option value="{{ $depth }}">{{ $depth }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Обхват</span>
                    <select wire:model.live="scope" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="all">Всички</option>
                        <option value="root">Основни</option>
                        <option value="non_root">Подкатегории</option>
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Директни продукти</span>
                    <select wire:model.live="directProducts" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="all">Всички</option>
                        <option value="with">С продукти</option>
                        <option value="without">Без продукти</option>
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Публикувани в дървото</span>
                    <select wire:model.live="publishedSubtree" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="all">Всички</option>
                        <option value="with">С публикувани</option>
                        <option value="without">Без публикувани</option>
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Нулева подредба</span>
                    <select wire:model.live="zeroSortOrder" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="all">Всички</option>
                        <option value="yes">Да</option>
                        <option value="no">Не</option>
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Българско съдържание</span>
                    <select wire:model.live="missingBulgarian" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="all">Всички</option>
                        <option value="yes">За преглед</option>
                        <option value="no">Без сигнал</option>
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Подредба</span>
                    <select wire:model.live="sortBy" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="path">Път</option>
                        <option value="depth">Ниво</option>
                        <option value="status">Статус</option>
                        <option value="direct_products">Директни продукти</option>
                        <option value="published_direct">Публикувани директно</option>
                        <option value="published_subtree">Публикувани в дървото</option>
                        <option value="sort_order">Ред</option>
                        <option value="issues">Брой проблеми</option>
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Посока</span>
                    <select wire:model.live="sortDirection" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="asc">Възходящо</option>
                        <option value="desc">Низходящо</option>
                    </select>
                </label>

                <div class="flex items-end">
                    <x-filament::button wire:click="resetAuditFilters" color="gray">
                        Изчисти филтрите
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Категории</x-slot>
            <x-slot name="description">
                Показани: {{ count($rows) }} · Генерирано: {{ $audit['generated_at'] }}
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full table-auto divide-y divide-gray-200 text-left text-sm dark:divide-white/10">
                    <thead>
                        <tr class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-3">Път</th>
                            <th class="px-3 py-3">Ниво</th>
                            <th class="px-3 py-3">Статус</th>
                            <th class="px-3 py-3">Подкатегории</th>
                            <th class="px-3 py-3">Директни продукти</th>
                            <th class="px-3 py-3">Публикувани директно</th>
                            <th class="px-3 py-3">Публикувани в дървото</th>
                            <th class="px-3 py-3">Подредба</th>
                            <th class="min-w-80 px-3 py-3">Проблеми</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($rows as $row)
                            <tr wire:key="category-audit-{{ $row['id'] }}" class="align-top">
                                <td class="px-3 py-3">
                                    <p class="font-medium text-gray-950 dark:text-white">{{ $row['full_path'] }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $row['public_url'] ?? 'Няма публичен адрес' }}</p>
                                </td>
                                <td class="px-3 py-3">{{ $row['depth'] ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $this->statusLabel($this->rowStatus($row)) }}</td>
                                <td class="px-3 py-3">{{ $row['children_count'] }}</td>
                                <td class="px-3 py-3">{{ $row['direct_product_count'] }}</td>
                                <td class="px-3 py-3">{{ $row['published_direct_product_count'] }}</td>
                                <td class="px-3 py-3">{{ $row['published_subtree_product_count'] }}</td>
                                <td class="px-3 py-3">{{ $row['sort_order'] }}</td>
                                <td class="px-3 py-3">
                                    @if ($row['issues'] === [])
                                        <span class="text-gray-500">Няма открити проблеми</span>
                                    @else
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($row['issues'] as $issue)
                                                <span
                                                    class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $this->severityClasses($issue['severity']) }}"
                                                    title="{{ $issue['recommendation'] }}"
                                                >
                                                    {{ $issue['label'] }}
                                                </span>
                                            @endforeach
                                        </div>
                                        <details class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                                            <summary class="cursor-pointer font-medium">Ръчни препоръки</summary>
                                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                                @foreach ($row['issues'] as $issue)
                                                    <li>{{ $issue['recommendation'] }}</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-3 py-10 text-center text-gray-500">
                                    Няма категории, които отговарят на избраните филтри.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
