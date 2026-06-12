<?php

namespace App\Filament\Resources\AiRecommendationLogs;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\AiRecommendationLogs\Pages\EditAiRecommendationLog;
use App\Filament\Resources\AiRecommendationLogs\Pages\ListAiRecommendationLogs;
use App\Models\AiRecommendationLog;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AiRecommendationLogResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = AiRecommendationLog::class;

    protected static ?string $permission = 'manage settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'AI Recommendation Logs';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('user.email')->label('User')->disabled(),
            TextInput::make('session_id')->disabled(),
            TextInput::make('recommendation_type')->disabled(),
            Textarea::make('query')->disabled()->rows(3),
            Textarea::make('results')->disabled()->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(14),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('recommendation_type')->badge()->sortable(),
                TextColumn::make('query')->searchable()->limit(60),
                TextColumn::make('user.email')->searchable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('recommendation_type')->options(array_combine(AiRecommendationLog::TYPES, AiRecommendationLog::TYPES)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiRecommendationLogs::route('/'),
            'edit' => EditAiRecommendationLog::route('/{record}/edit'),
        ];
    }
}
