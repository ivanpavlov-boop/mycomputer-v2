<?php

namespace App\Filament\Resources\CompatibilityRules;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\CompatibilityRules\Pages\CreateCompatibilityRule;
use App\Filament\Resources\CompatibilityRules\Pages\EditCompatibilityRule;
use App\Filament\Resources\CompatibilityRules\Pages\ListCompatibilityRules;
use App\Models\PcCompatibilityRule;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class CompatibilityRuleResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = PcCompatibilityRule::class;

    protected static ?string $permission = 'manage products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Compatibility Rules';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('rule_type')->options(array_combine(PcCompatibilityRule::RULE_TYPES, PcCompatibilityRule::RULE_TYPES))->required(),
            TextInput::make('source_attribute')->required(),
            TextInput::make('target_attribute')->required(),
            Select::make('operator')->options(array_combine(PcCompatibilityRule::OPERATORS, PcCompatibilityRule::OPERATORS))->required(),
            TextInput::make('value'),
            TextInput::make('priority')->numeric()->default(0),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rule_type')->badge()->sortable(),
                TextColumn::make('source_attribute')->searchable(),
                TextColumn::make('operator')->badge(),
                TextColumn::make('target_attribute')->searchable(),
                TextColumn::make('priority')->sortable(),
                IconColumn::make('is_active')->boolean()->sortable(),
            ])
            ->filters([
                SelectFilter::make('rule_type')->options(array_combine(PcCompatibilityRule::RULE_TYPES, PcCompatibilityRule::RULE_TYPES)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompatibilityRules::route('/'),
            'create' => CreateCompatibilityRule::route('/create'),
            'edit' => EditCompatibilityRule::route('/{record}/edit'),
        ];
    }
}
