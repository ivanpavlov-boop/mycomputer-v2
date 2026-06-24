<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;
use UnitEnum;

class RoleResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = Role::class;

    protected static ?string $permission = 'manage roles';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Roles';

    protected static string|UnitEnum|null $navigationGroup = 'Access Control';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Role')->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')->required()->unique(ignoreRecord: true),
                    TextInput::make('guard_name')->default('web')->required(),
                    Select::make('permissions')
                        ->relationship('permissions', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->columnSpanFull(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('guard_name')->badge(),
            TextColumn::make('permissions.name')->badge()->limitList(5),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ]);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccessResource()
            && $record instanceof Role
            && ! in_array($record->name, array_merge(User::ADMIN_ROLES, ['admin', 'manager', 'support', 'customer', 'b2b_customer']), true);
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    protected static function canAccessResource(): bool
    {
        return (bool) auth()->user()?->canManageRoles();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
