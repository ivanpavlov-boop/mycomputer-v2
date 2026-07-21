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
