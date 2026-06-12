<?php

namespace App\Filament\Widgets;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiRecommendationLog;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AiUsageStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('AI conversations', AiConversation::query()->count()),
            Stat::make('AI messages', AiMessage::query()->count()),
            Stat::make('Recommendations', AiRecommendationLog::query()->count()),
            Stat::make('Today', AiRecommendationLog::query()->whereDate('created_at', today())->count()),
        ];
    }
}
