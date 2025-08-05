<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EventTemplateResource\Pages;
use App\Models\EventTemplate;
use App\Models\HotelRoom;
use App\Models\EventPriceDescription;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Filament\Forms\Components\View as ViewComponent;

/**
 * Resource Filament dla modelu EventTemplate.
 * Definiuje formularz, tabelę, uprawnienia i strony powiązane z szablonami wydarzeń.
 */
class EventTemplateResource extends Resource
{
    protected static ?string $model = EventTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    /**
     * Definicja formularza do edycji/dodawania szablonu wydarzenia
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nazwa')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(
                    fn($state, callable $set) =>
                    $set('slug', Str::slug($state))
                ),
            Forms\Components\Select::make('eventTypes')
                ->label('Typy wydarzenia')
                ->multiple()
                ->relationship('eventTypes', 'name')
                ->preload()
                ->searchable()
                ->columnSpanFull(),
            Forms\Components\Select::make('transportTypes')
                ->label('Rodzaje transportu')
                ->multiple()
                ->relationship('transportTypes', 'name')
                ->preload()
                ->searchable()
                ->columnSpanFull(),
            Forms\Components\TextInput::make('subtitle')
                ->label('Podtytuł')
                ->maxLength(255),
            Forms\Components\TextInput::make('slug')
                ->label('Slug')
                ->required(),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktywny')
                ->default(true)
                ->helperText('Tylko aktywne szablony są widoczne w systemie'),
            Forms\Components\TextInput::make('duration_days')
                ->label('Długość imprezy (dni)')
                ->numeric()
                ->default(1)
                ->required()
                ->extraInputAttributes(['step' => 1, 'min' => 1]),
            Forms\Components\Select::make('markup_id')
                ->label('Narzut')
                ->options(fn() => \App\Models\Markup::pluck('name', 'id'))
                ->searchable()
                ->nullable()
                ->default(function () {
                    $defaultMarkup = \App\Models\Markup::where('is_default', true)->first();
                    return $defaultMarkup?->id;
                })
                ->helperText('Jeśli nie wybierzesz, zostanie użyty domyślny narzut.'),
            Forms\Components\Select::make('event_price_description_id')
                ->label('Opis ceny imprezy')
                ->options(fn() => \App\Models\EventPriceDescription::pluck('name', 'id'))
                ->searchable()
                ->nullable()
                ->helperText('Wybierz opis ceny imprezy. Możesz zostawić puste.')
                ->live(),
            Forms\Components\FileUpload::make('featured_image')
                ->label('Zdjęcie wyróżniające')
                ->image(),
            Forms\Components\CheckboxList::make('taxes')
                ->label('Podatki')
                ->helperText('Wybierz podatki, które mają być naliczane dla tej imprezy')
                ->relationship('taxes', 'name', function ($query) {
                    return $query->where('is_active', true);
                })
                ->getOptionLabelFromRecordUsing(function ($record) {
                    $baseText = $record->apply_to_base ? 'od sumy bez narzutu' : '';
                    $markupText = $record->apply_to_markup ? 'od narzutu' : '';
                    $description = array_filter([$baseText, $markupText]);
                    $descriptionText = !empty($description) ? ' (' . implode(', ', $description) . ')' : '';
                    return $record->name . $descriptionText;
                })
                ->columns(2),
            Forms\Components\Textarea::make('short_description')
                ->label('Krótki opis')
                ->rows(3)
                ->columnSpanFull(),
            Forms\Components\RichEditor::make('description')
                ->label('Opis')
                ->disableToolbarButtons(['codeBlock'])
                ->columnSpanFull(),
            Forms\Components\Section::make('SEO')
                ->schema([
                    Forms\Components\TextInput::make('seo_title')
                        ->label('Tytuł SEO')
                        ->maxLength(70)
                        ->helperText('Tytuł strony widoczny w Google (max 70 znaków)'),
                    Forms\Components\Textarea::make('seo_description')
                        ->label('Opis SEO')
                        ->maxLength(350)
                        ->rows(2)
                        ->helperText('Opis strony widoczny w Google (max 350 znaków)'),
                    Forms\Components\TextInput::make('seo_keywords')
                        ->label('Słowa kluczowe')
                        ->helperText('Oddziel przecinkami'),
                    Forms\Components\TextInput::make('seo_canonical')
                        ->label('Canonical URL')
                        ->helperText('Adres kanoniczny (jeśli dotyczy)'),
                    Forms\Components\TextInput::make('seo_og_title')
                        ->label('OpenGraph Title')
                        ->maxLength(70),
                    Forms\Components\Textarea::make('seo_og_description')
                        ->label('OpenGraph Description')
                        ->maxLength(350)
                        ->rows(2),
                    Forms\Components\TextInput::make('seo_og_image')
                        ->label('OpenGraph Image URL'),
                    Forms\Components\TextInput::make('seo_twitter_title')
                        ->label('Twitter Title')
                        ->maxLength(70),
                    Forms\Components\Textarea::make('seo_twitter_description')
                        ->label('Twitter Description')
                        ->maxLength(350)
                        ->rows(2),
                    Forms\Components\TextInput::make('seo_twitter_image')
                        ->label('Twitter Image URL'),
                    Forms\Components\Textarea::make('seo_schema')
                        ->label('Schema.org (JSON-LD)')
                        ->rows(3)
                        ->helperText('Wklej kod JSON-LD dla zaawansowanego SEO (opcjonalnie)'),
                ]),
        ]);
    }

    /**
     * Definicja tabeli szablonów wydarzeń w panelu
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nazwa')
                    ->searchable(),
                Tables\Columns\TextColumn::make('subtitle')
                    ->label('Podtytuł')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable()
                    ->action(function ($record) {
                        $record->update(['is_active' => !$record->is_active]);
                    })
                    ->tooltip(fn($record) => $record->is_active ? 'Kliknij, aby dezaktywować' : 'Kliknij, aby aktywować'),
                Tables\Columns\TextColumn::make('duration_days')
                    ->label('Długość imprezy (dni)')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('startPlace.name')
                    ->label('Miejsce startowe')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Nie ustawiono')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('available_start_places')
                    ->label('Dostępne miejsca wyjazdów')
                    ->getStateUsing(function ($record) {
                        return $record->startingPlaceAvailabilities()
                            ->where('available', true)
                            ->with('startPlace')
                            ->get()
                            ->pluck('startPlace.name')
                            ->filter()
                            ->join(', ');
                    })
                    ->placeholder('Brak dostępnych')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('markup.name')
                    ->label('Narzut')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Domyślny')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Zaktualizowano')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('is_active')
                    ->label('Tylko aktywne')
                    ->query(fn($query) => $query->where('is_active', true))
                    ->default(),
                Tables\Filters\TrashedFilter::make()
                    ->label('Kosz'),
            ])
            ->actions([
                Tables\Actions\Action::make('create_event')
                    ->label('Utwórz imprezę')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->url(fn($record) => route('filament.admin.resources.events.create', ['template' => $record->id]))
                    ->openUrlInNewTab(),
                Tables\Actions\ViewAction::make()
                    ->label('Podgląd'),
                Tables\Actions\EditAction::make()
                    ->label('Edytuj'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Usuń zaznaczone'),
                    Tables\Actions\ForceDeleteBulkAction::make()
                        ->label('Usuń na stałe'),
                    Tables\Actions\RestoreBulkAction::make()
                        ->label('Przywróć'),
                ]),
            ])
            ->defaultSort('name', 'asc');
    }

    /**
     * Relacje powiązane z szablonem wydarzenia (jeśli są)
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * Rejestracja stron powiązanych z tym resource (zgodnie z Filament 3)
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEventTemplates::route('/'),
            'create' => Pages\CreateEventTemplate::route('/create'),
            'edit' => Pages\EditEventTemplate::route('/{record}/edit'),
            'edit-program' => Pages\EditEventTemplateProgram::route('/{record}/program'),
            'calculation' => Pages\EventTemplateCalculation::route('/{record}/calculation'),
            'transport' => Pages\EventTemplateTransport::route('/{record}/transport'),
        ];
    }

    /**
     * Uprawnienia do widoczności resource w panelu
     */
    public static function canViewAny(): bool
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user && $user->roles && $user->roles->contains('name', 'admin')) {
            return true;
        }
        if ($user && $user->roles && $user->roles->flatMap->permissions->contains('name', 'view eventtemplate')) {
            return true;
        }
        return false;
    }
}
