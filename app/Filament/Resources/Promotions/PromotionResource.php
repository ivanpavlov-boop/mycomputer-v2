<?php

namespace App\Filament\Resources\Promotions;

use App\Filament\Concerns\RequiresFilamentPermission;
use App\Filament\Resources\Promotions\Pages\CreatePromotion;
use App\Filament\Resources\Promotions\Pages\EditPromotion;
use App\Filament\Resources\Promotions\Pages\ListPromotions;
use App\Models\Promotion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PromotionResource extends Resource
{
    use RequiresFilamentPermission;

    protected static ?string $model = Promotion::class;

    protected static ?string $permission = 'manage marketing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Promotions';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->maxLength(100)->unique(ignoreRecord: true)->helperText('Leave empty for automatic promotions.'),
            Textarea::make('description')->columnSpanFull(),
            Select::make('type')->options(array_combine(Promotion::TYPES, Promotion::TYPES))->required(),
            Select::make('status')->options(array_combine(Promotion::STATUSES, Promotion::STATUSES))->required()->default('inactive'),
            TextInput::make('priority')->numeric()->default(0),
            DateTimePicker::make('starts_at'),
            DateTimePicker::make('ends_at'),
            TextInput::make('usage_limit')->numeric()->minValue(1),
            TextInput::make('usage_count')->numeric()->disabled(),
            Toggle::make('stackable')->default(false),
            Toggle::make('stop_further_rules')->default(false),
            Repeater::make('rules')
                ->relationship()
                ->schema([
                    Select::make('rule_type')->options([
                        'minimum_order_amount' => 'Minimum order amount',
                        'category_id' => 'Category',
                        'brand_id' => 'Brand',
                        'product_id' => 'Product',
                        'quantity_min' => 'Minimum quantity',
                        'loyalty_tier' => 'Loyalty tier',
                        'per_user_limit' => 'Per user limit',
                        'per_session_limit' => 'Per session limit',
                        'b2b_ready' => 'B2B ready placeholder',
                    ])->required(),
                    Select::make('operator')->options([
                        'gte' => 'Greater or equal',
                        'equals' => 'Equals',
                        'gt' => 'Greater than',
                        'lte' => 'Less or equal',
                    ])->default('gte')->required(),
                    TextInput::make('value.value')->label('Value')->required(),
                ])->columnSpanFull(),
            Repeater::make('actions')
                ->relationship()
                ->schema([
                    Select::make('action_type')->options([
                        'percentage_discount' => 'Percentage discount',
                        'fixed_discount' => 'Fixed discount',
                        'free_shipping' => 'Free shipping',
                        'gift_product' => 'Gift product',
                        'bundle_discount' => 'Bundle discount',
                        'buy_x_get_y' => 'Buy X Get Y',
                    ])->required(),
                    TextInput::make('configuration.amount')->label('Amount / Percent'),
                    TextInput::make('configuration.product_id')->label('Gift/Get product ID'),
                    TextInput::make('configuration.quantity')->label('Gift quantity'),
                    TextInput::make('configuration.scope')->label('Scope'),
                    TextInput::make('configuration.scope_value')->label('Scope value'),
                ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge()->searchable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('priority')->numeric()->sortable(),
                IconColumn::make('stackable')->boolean(),
                TextColumn::make('usage_count')->numeric()->sortable(),
                TextColumn::make('redemptions_count')->counts('redemptions')->label('Redemptions'),
                TextColumn::make('starts_at')->dateTime()->sortable(),
                TextColumn::make('ends_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(array_combine(Promotion::TYPES, Promotion::TYPES)),
                SelectFilter::make('status')->options(array_combine(Promotion::STATUSES, Promotion::STATUSES)),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('duplicate')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->action(function (Promotion $record): void {
                        $copy = $record->replicate(['code', 'usage_count']);
                        $copy->name = $record->name.' Copy';
                        $copy->status = 'inactive';
                        $copy->save();
                        foreach ($record->rules as $rule) {
                            $copy->rules()->create($rule->only(['rule_type', 'operator', 'value']));
                        }
                        foreach ($record->actions as $action) {
                            $copy->actions()->create($action->only(['action_type', 'configuration']));
                        }
                    }),
                Action::make('activate')->icon(Heroicon::OutlinedCheckCircle)->action(fn (Promotion $record) => $record->update(['status' => 'active'])),
                Action::make('deactivate')->icon(Heroicon::OutlinedXCircle)->action(fn (Promotion $record) => $record->update(['status' => 'inactive'])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('priority', 'desc');
    }

    public static function canDelete(Model $record): bool
    {
        return $record->redemptions()->doesntExist() && parent::canDelete($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromotions::route('/'),
            'create' => CreatePromotion::route('/create'),
            'edit' => EditPromotion::route('/{record}/edit'),
        ];
    }
}
