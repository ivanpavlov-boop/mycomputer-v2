@php
    /** @var \App\Services\Products\ProductDataQualitySummaryResult $summary */
    $issueGroups = [
        'critical' => 'Критични',
        'warning' => 'Предупреждения',
        'recommendation' => 'Препоръки',
    ];
@endphp

<div data-testid="product-data-quality-summary" style="display: grid; gap: 1.25rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
        <div>
            <div style="font-size: 0.75rem; font-weight: 600; color: rgb(107 114 128);">Общ статус</div>
            <div style="margin-top: 0.35rem; display: flex; align-items: center; gap: 0.5rem;">
                <x-filament::badge :color="$summary->statusColor">
                    {{ $summary->overallLabel }}
                </x-filament::badge>
                <span style="font-size: 0.875rem; color: rgb(107 114 128);">
                    {{ $summary->totalActionableIssueCount }} общо
                </span>
            </div>
        </div>

        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <x-filament::badge color="danger">Критични: {{ $summary->criticalIssueCount }}</x-filament::badge>
            <x-filament::badge color="warning">Предупреждения: {{ $summary->warningIssueCount }}</x-filament::badge>
            <x-filament::badge color="info">Препоръки: {{ $summary->recommendationIssueCount }}</x-filament::badge>
        </div>
    </div>

    <div data-testid="product-category-brand-quality">
        <div style="font-size: 0.875rem; font-weight: 600;">Категория и марка</div>
        <div style="margin-top: 0.5rem; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <x-filament::badge :color="$summary->categoryBrandQuality->stateColor">
                {{ $summary->categoryBrandQuality->stateLabel }}
            </x-filament::badge>
        </div>
        <div style="margin-top: 0.65rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 0.75rem;">
            <div style="font-size: 0.875rem;">
                <span style="font-weight: 600;">Категория:</span>
                {{ $summary->categoryBrandQuality->categoryDisplayLabel }}
                @if ($summary->categoryBrandQuality->categoryWarning() !== null)
                    <x-filament::badge color="warning">{{ $summary->categoryBrandQuality->categoryWarning() }}</x-filament::badge>
                @endif
            </div>
            <div style="font-size: 0.875rem;">
                <span style="font-weight: 600;">Марка:</span>
                {{ $summary->categoryBrandQuality->brandDisplayLabel }}
                @if ($summary->categoryBrandQuality->brandWarning() !== null)
                    <x-filament::badge color="warning">{{ $summary->categoryBrandQuality->brandWarning() }}</x-filament::badge>
                @endif
            </div>
        </div>
    </div>

    <div data-testid="product-image-quality">
        <div style="font-size: 0.875rem; font-weight: 600;">Снимки и ALT текст</div>
        <div style="margin-top: 0.5rem; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <x-filament::badge :color="$summary->imageQuality->stateColor">
                {{ $summary->imageQuality->stateLabel }}
            </x-filament::badge>
        </div>
        <div style="margin-top: 0.65rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 0.75rem; font-size: 0.875rem;">
            <div><span style="font-weight: 600;">Снимки:</span> {{ $summary->imageQuality->eligibleImageCount }}</div>
            <div>
                <span style="font-weight: 600;">Основна снимка:</span>
                <x-filament::badge :color="$summary->imageQuality->primaryStatusColor">
                    {{ $summary->imageQuality->primaryStatusLabel }}
                </x-filament::badge>
            </div>
            <div
                @if ($summary->imageQuality->altTextSamples !== [])
                    title="{{ implode(' | ', $summary->imageQuality->altTextSamples) }}"
                @endif
            >
                <span style="font-weight: 600;">ALT текст:</span> {{ $summary->imageQuality->altCoverageLabel }}
            </div>
        </div>
    </div>

    <div data-testid="product-seo-description-quality">
        <div style="font-size: 0.875rem; font-weight: 600;">SEO, описания и английска локализация</div>
        <div style="margin-top: 0.5rem; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <x-filament::badge :color="$summary->seoDescriptionQuality->stateColor">
                {{ $summary->seoDescriptionQuality->stateLabel }}
            </x-filament::badge>
        </div>
        <div style="margin-top: 0.65rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 0.75rem; font-size: 0.875rem;">
            <div><span style="font-weight: 600;">SEO:</span> {{ $summary->seoDescriptionQuality->seoScoreLabel }}</div>
            <div><span style="font-weight: 600;">Описания:</span> {{ $summary->seoDescriptionQuality->descriptionScoreLabel }}</div>
            <div><span style="font-weight: 600;">EN локализация:</span> {{ $summary->seoDescriptionQuality->englishScoreLabel }}</div>
        </div>
        @if ($summary->seoDescriptionQuality->issueLabels() !== [])
            <div
                style="margin-top: 0.5rem; font-size: 0.8rem; color: rgb(107 114 128);"
                title="{{ implode(' · ', $summary->seoDescriptionQuality->issueLabels()) }}"
            >
                Липсва или се нуждае от преглед: {{ implode(', ', $summary->seoDescriptionQuality->issueLabels()) }}
            </div>
        @endif
    </div>

    <div>
        <div style="font-size: 0.875rem; font-weight: 600;">Открити проблеми</div>

        @if ($summary->criticalIssueCount === 0)
            <div style="margin-top: 0.5rem; font-size: 0.875rem; color: rgb(22 163 74);">
                Няма открити критични проблеми
            </div>
        @endif

        <div style="margin-top: 0.5rem; display: grid; gap: 0.65rem;">
            @foreach ($issueGroups as $level => $groupLabel)
                @php($issues = $summary->coreIssuesForLevel($level))

                @if ($issues !== [])
                    <div style="display: flex; align-items: flex-start; gap: 0.5rem; flex-wrap: wrap;">
                        <span style="min-width: 7.5rem; font-size: 0.75rem; font-weight: 600; color: rgb(107 114 128);">
                            {{ $groupLabel }}
                        </span>
                        @foreach ($issues as $issue)
                            <x-filament::badge :color="$issue['color']">
                                {{ $issue['label'] }}
                            </x-filament::badge>
                        @endforeach
                    </div>
                @endif
            @endforeach

            @if ($summary->coreIssues === [])
                <div style="font-size: 0.875rem; color: rgb(107 114 128);">Няма открити проблеми в основните продуктови данни.</div>
            @endif
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem;">
        <div>
            <div style="font-size: 0.875rem; font-weight: 600;">Характеристики</div>
            <div style="margin-top: 0.5rem; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <x-filament::badge :color="$summary->specificationResult->statusColor()">
                    {{ $summary->specificationResult->statusLabel() }}
                </x-filament::badge>
                <span style="font-size: 0.875rem;">{{ $summary->specificationResult->scoreLabel() }}</span>
            </div>

            @if ($summary->specificationResult->missingAttributeLabels() !== [])
                <div
                    style="margin-top: 0.5rem; font-size: 0.8rem; color: rgb(107 114 128);"
                    title="{{ implode(', ', $summary->specificationResult->missingAttributeLabels()) }}"
                >
                    Липсват: {{ $summary->specificationResult->missingAttributeSummary() }}
                </div>
            @endif
        </div>

        <div>
            <div style="font-size: 0.875rem; font-weight: 600;">Ръчни флагове</div>

            @if ($summary->manualFlags === [])
                <div style="margin-top: 0.5rem; font-size: 0.875rem; color: rgb(107 114 128);">Няма активни флагове</div>
            @else
                <div style="margin-top: 0.5rem; display: grid; gap: 0.5rem;">
                    @foreach ($summary->manualFlags as $flag)
                        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                            <x-filament::badge :color="$flag['color']">{{ $flag['label'] }}</x-filament::badge>
                            <span style="font-size: 0.75rem; color: rgb(107 114 128);">
                                {{ $flag['severity_label'] }} · {{ $flag['responsible_role_label'] }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div>
        <div style="font-size: 0.875rem; font-weight: 600;">Следващи стъпки</div>

        @if ($summary->nextSteps === [])
            <div style="margin-top: 0.5rem; font-size: 0.875rem; color: rgb(22 163 74);">Не са необходими допълнителни действия.</div>
        @else
            <ul style="margin: 0.5rem 0 0 1rem; display: grid; gap: 0.25rem; list-style: disc; font-size: 0.875rem;">
                @foreach ($summary->nextSteps as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
