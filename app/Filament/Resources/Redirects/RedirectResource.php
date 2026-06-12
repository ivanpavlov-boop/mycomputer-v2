<?php

namespace App\Filament\Resources\Redirects;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\Redirects\Pages\CreateRedirect;
use App\Filament\Resources\Redirects\Pages\EditRedirect;
use App\Filament\Resources\Redirects\Pages\ListRedirects;
use App\Models\Redirect;
use App\Services\Content\RedirectService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class RedirectResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = Redirect::class;

    protected static ?string $permission = 'manage pages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $navigationLabel = 'Redirects';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('source_url')
                ->required()
                ->unique(ignoreRecord: true)
                ->rule('starts_with:/')
                ->maxLength(255),
            TextInput::make('target_url')
                ->required()
                ->maxLength(255)
                ->rule(fn () => function (string $attribute, $value, \Closure $fail): void {
                    try {
                        app(RedirectService::class)->assertSafeTarget((string) $value);
                    } catch (\Throwable) {
                        $fail('Redirect target must be relative or within mycomputer.bg.');
                    }
                }),
            Select::make('status_code')->options([301 => '301 Permanent', 302 => '302 Temporary'])->required()->default(301),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source_url')->searchable()->sortable(),
                TextColumn::make('target_url')->searchable(),
                TextColumn::make('status_code')->badge()->sortable(),
                IconColumn::make('is_active')->boolean()->sortable(),
            ])
            ->filters([TernaryFilter::make('is_active')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRedirects::route('/'),
            'create' => CreateRedirect::route('/create'),
            'edit' => EditRedirect::route('/{record}/edit'),
        ];
    }
}
