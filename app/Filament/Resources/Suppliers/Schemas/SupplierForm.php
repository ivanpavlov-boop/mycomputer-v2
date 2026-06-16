<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Supplier')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('company_name')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                            TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                            TextInput::make('contact_person')->maxLength(255),
                            TextInput::make('email')->email()->maxLength(255),
                            TextInput::make('phone')->maxLength(255),
                            TextInput::make('website')->url()->maxLength(255),
                            TextInput::make('priority')
                                ->numeric()
                                ->default(100)
                                ->helperText('Lower value means higher priority.'),
                            Select::make('sync_strategy')
                                ->options([
                                    'lowest_price' => 'Lowest price',
                                    'preferred_supplier' => 'Preferred supplier',
                                ])
                                ->default('lowest_price')
                                ->required(),
                            Select::make('status')
                                ->options([
                                    'active' => 'Active',
                                    'inactive' => 'Inactive',
                                    'on_hold' => 'On hold',
                                ])
                                ->default('active')
                                ->required(),
                        ]),
                        Textarea::make('notes')->rows(4)->columnSpanFull(),
                    ]),
                Section::make('Pricing')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('msrp_strategy')
                                ->options([
                                    'margin_only' => 'Margin price only',
                                    'recommended_only' => 'Recommended price only',
                                    'recommended_min_margin' => 'Recommended with minimum margin',
                                    'higher_of_margin_or_recommended' => 'Higher of margin/recommended',
                                    'lower_of_margin_or_recommended' => 'Lower of margin/recommended',
                                ])
                                ->default('margin_only')
                                ->required(),
                            Select::make('vat_mode')
                                ->options([
                                    'price_includes_vat' => 'Price includes VAT',
                                    'price_excludes_vat' => 'Price excludes VAT',
                                    'reverse_charge_eu' => 'Reverse-charge EU',
                                ])
                                ->default('price_excludes_vat')
                                ->required(),
                            TextInput::make('vat_rate')
                                ->numeric()
                                ->helperText('Optional supplier VAT rate used only for normalized cost calculations.'),
                        ]),
                    ]),
                Section::make('Import schedule')
                    ->schema([
                        Grid::make(3)->schema([
                            Toggle::make('import_enabled')->default(true),
                            Toggle::make('schedule_enabled')->default(false),
                            Select::make('schedule_type')
                                ->options([
                                    'twice_daily' => 'Twice daily',
                                    'daily' => 'Daily',
                                    'hourly' => 'Hourly',
                                    'manual_only' => 'Manual only',
                                    'custom' => 'Custom',
                                ])
                                ->default('manual_only')
                                ->required(),
                            TimePicker::make('morning_import_time')->seconds(false),
                            TimePicker::make('evening_import_time')->seconds(false),
                            TextInput::make('timezone')->default('Europe/Sofia')->maxLength(64),
                            TextInput::make('stagger_minutes')->numeric()->default(20)->minValue(0),
                            TextInput::make('maximum_product_drop_percent')->numeric()->default(40)->minValue(1)->maxValue(100),
                            TextInput::make('minimum_product_count')->numeric()->default(1)->minValue(0),
                            Toggle::make('allow_destructive_sync')->default(false),
                            TextInput::make('last_import_at')->disabled(),
                            TextInput::make('next_import_at')->disabled(),
                        ]),
                    ]),
            ]);
    }
}
