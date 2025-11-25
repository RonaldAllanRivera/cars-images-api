<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CarSearchResource\Pages;
use App\Filament\Resources\CarSearchResource\RelationManagers\CarImagesRelationManager;
use App\Models\CarSearch;
use App\Models\CarMake;
use BackedEnum;
use UnitEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class CarSearchResource extends Resource
{
    protected static ?string $model = CarSearch::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static UnitEnum|string|null $navigationGroup = 'Cars';

    protected static ?string $navigationLabel = 'Car Image Searches';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Select::make('make')
                ->required()
                ->options(static::getMakeOptions())
                ->default('Toyota')
                ->live()
                ->afterStateUpdated(function (Set $set) {
                    $set('model', null);
                })
                ->searchable(),
            Forms\Components\Select::make('model')
                ->options(function (Get $get): array {
                    $models = static::getModelOptionsForMake($get('make'));

                    // `__all__` is treated as "no model filter" in CreateCarSearch.
                    return ['__all__' => 'All models'] + $models;
                })
                ->default('__all__')
                ->searchable()
                ->nullable()
                ->afterStateHydrated(function ($component, $state): void {
                    if ($state === null || $state === '') {
                        $component->state('__all__');
                    }
                })
                ->dehydrateStateUsing(function ($state) {
                    if ($state === '' || $state === '__all__') {
                        return null;
                    }

                    return $state;
                }),
            Forms\Components\TextInput::make('from_year')
                ->numeric()
                ->required()
                ->default(2018)
                ->minValue(1900)
                ->maxValue((int) date('Y') + 1),
            Forms\Components\TextInput::make('to_year')
                ->numeric()
                ->required()
                ->default(2022)
                ->minValue(1900)
                ->maxValue((int) date('Y') + 1),
            Forms\Components\Select::make('color')
                ->options([
                    '__all__' => 'All colors',
                    'red' => 'Red',
                    'white' => 'White',
                    'black' => 'Black',
                    'blue' => 'Blue',
                    'silver' => 'Silver',
                    'grey' => 'Grey',
                    'green' => 'Green',
                    'yellow' => 'Yellow',
                ])
                ->default('__all__')
                ->searchable()
                ->nullable()
                ->afterStateHydrated(function ($component, $state): void {
                    if ($state === null || $state === '') {
                        $component->state('__all__');
                    }
                })
                ->dehydrateStateUsing(function ($state) {
                    if ($state === '' || $state === '__all__') {
                        return null;
                    }

                    return $state;
                }),
            Forms\Components\Select::make('transmission')
                ->options([
                    '__all__' => 'All transmissions',
                    'Automatic' => 'Automatic',
                    'Manual' => 'Manual',
                    'CVT' => 'CVT',
                ])
                ->default('__all__')
                ->searchable()
                ->nullable()
                ->afterStateHydrated(function ($component, $state): void {
                    if ($state === null || $state === '') {
                        $component->state('__all__');
                    }
                })
                ->dehydrateStateUsing(function ($state) {
                    if ($state === '' || $state === '__all__') {
                        return null;
                    }

                    return $state;
                }),
            Forms\Components\Toggle::make('transparent_background')
                ->label('Transparent background')
                ->default(false),
            Forms\Components\TextInput::make('images_per_year')
                ->numeric()
                ->default(10)
                ->minValue(1)
                ->maxValue(50),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('make')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('model')
                    ->label('Model')
                    ->getStateUsing(function (CarSearch $record): string {
                        return $record->model ?? 'All';
                    })
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('color')
                    ->label('Color')
                    ->getStateUsing(function (CarSearch $record): string {
                        return $record->color ?? 'All';
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('transmission')
                    ->label('Transmission')
                    ->getStateUsing(function (CarSearch $record): string {
                        return $record->transmission ?? 'All';
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('from_year')
                    ->label('From')
                    ->sortable(),
                Tables\Columns\TextColumn::make('to_year')
                    ->label('To')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'running',
                        'success' => 'completed',
                        'danger' => 'failed',
                    ]),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(100);
    }

    protected static function getMakeOptions(): array
    {
        $dbMakes = CarMake::query()
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();

        if (! empty($dbMakes)) {
            return $dbMakes;
        }

        return [
            'Toyota' => 'Toyota',
            'Honda' => 'Honda',
            'Tesla' => 'Tesla',
            'Ford' => 'Ford',
            'BMW' => 'BMW',
            'Mercedes-Benz' => 'Mercedes-Benz',
            'Nissan' => 'Nissan',
            'Hyundai' => 'Hyundai',
            'Kia' => 'Kia',
            'Volkswagen' => 'Volkswagen',
        ];
    }

    protected static function getModelOptionsForMake(?string $make): array
    {
        if ($make) {
            $makeRecord = CarMake::query()
                ->where('name', $make)
                ->with('models')
                ->first();

            if ($makeRecord) {
                return $makeRecord->models
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->pluck('name', 'name')
                    ->all();
            }
        }

        $all = [
            'Toyota' => [
                'Corolla' => 'Corolla',
                'Camry' => 'Camry',
                'RAV4' => 'RAV4',
                'Hilux' => 'Hilux',
            ],
            'Honda' => [
                'Civic' => 'Civic',
                'Accord' => 'Accord',
                'CR-V' => 'CR-V',
                'Jazz' => 'Jazz',
            ],
            'Tesla' => [
                'Model 3' => 'Model 3',
                'Model Y' => 'Model Y',
                'Model S' => 'Model S',
                'Model X' => 'Model X',
            ],
            'Ford' => [
                'Mustang' => 'Mustang',
                'F-150' => 'F-150',
                'Focus' => 'Focus',
                'Explorer' => 'Explorer',
            ],
            'BMW' => [
                '3 Series' => '3 Series',
                '5 Series' => '5 Series',
                'X3' => 'X3',
                'X5' => 'X5',
            ],
            'Mercedes-Benz' => [
                'A-Class' => 'A-Class',
                'C-Class' => 'C-Class',
                'E-Class' => 'E-Class',
                'GLC' => 'GLC',
            ],
            'Nissan' => [
                'Altima' => 'Altima',
                'Sentra' => 'Sentra',
                'X-Trail' => 'X-Trail',
            ],
            'Hyundai' => [
                'Elantra' => 'Elantra',
                'Tucson' => 'Tucson',
                'Santa Fe' => 'Santa Fe',
            ],
            'Kia' => [
                'Sportage' => 'Sportage',
                'Sorento' => 'Sorento',
                'Rio' => 'Rio',
            ],
            'Volkswagen' => [
                'Golf' => 'Golf',
                'Passat' => 'Passat',
                'Tiguan' => 'Tiguan',
            ],
        ];

        if ($make && isset($all[$make])) {
            return $all[$make];
        }

        // If no make selected yet, offer a combined popular list.
        return collect($all)
            ->flatMap(fn (array $models) => $models)
            ->all();
    }

    public static function getRelations(): array
    {
        return [
            CarImagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCarSearches::route('/'),
            'create' => Pages\CreateCarSearch::route('/create'),
            'view' => Pages\ViewCarSearch::route('/{record}'),
            'edit' => Pages\EditCarSearch::route('/{record}/edit'),
        ];
    }
}
