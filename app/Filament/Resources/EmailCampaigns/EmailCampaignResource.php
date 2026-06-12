<?php

namespace App\Filament\Resources\EmailCampaigns;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\EmailCampaigns\Pages\CreateEmailCampaign;
use App\Filament\Resources\EmailCampaigns\Pages\EditEmailCampaign;
use App\Filament\Resources\EmailCampaigns\Pages\ListEmailCampaigns;
use App\Models\EmailCampaign;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class EmailCampaignResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = EmailCampaign::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Email Campaigns';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('subject')->required(),
            TextInput::make('template')->required(),
            Select::make('status')->options(array_combine(EmailCampaign::STATUSES, EmailCampaign::STATUSES))->required(),
            DateTimePicker::make('scheduled_at'),
            DateTimePicker::make('sent_at'),
            TextInput::make('recipients_count')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('subject')->searchable(),
            TextColumn::make('template')->badge(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('recipients_count')->sortable(),
            TextColumn::make('scheduled_at')->dateTime()->sortable(),
        ])->recordActions([EditAction::make()])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailCampaigns::route('/'),
            'create' => CreateEmailCampaign::route('/create'),
            'edit' => EditEmailCampaign::route('/{record}/edit'),
        ];
    }
}
