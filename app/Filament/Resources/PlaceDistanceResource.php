<?php

namespace App\Filament\Resources;

use App\Models\PlaceDistance;
use App\Models\Place;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\NumberColumn;
use App\Filament\Resources\PlaceDistanceResource\Pages;

class PlaceDistanceResource extends Resource
{
    protected static ?string $model = PlaceDistance::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrow-right-on-rectangle';
    protected static ?string $navigationLabel = 'Odległości między miejscami';
    protected static ?string $navigationGroup = 'Ustawienia ogólne';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('from_place_id')
                    ->label('Miejsce początkowe')
                    ->options(Place::pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                Select::make('to_place_id')
                    ->label('Miejsce docelowe')
                    ->options(Place::pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                TextInput::make('distance_km')
                    ->label('Odległość drogowa (km)')
                    ->numeric()
                    ->minValue(0)
                    ->nullable(),
                TextInput::make('api_source')
                    ->label('Źródło API')
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fromPlace.name')->label('Od'),
                TextColumn::make('toPlace.name')->label('Do'),
                TextColumn::make('distance_km')->label('Odległość (km)')->sortable(),
                TextColumn::make('api_source')->label('Źródło'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlaceDistances::route('/'),
            'create' => Pages\CreatePlaceDistance::route('/create'),
            'edit' => Pages\EditPlaceDistance::route('/{record}/edit'),
        ];
    }
}
