<?php

namespace App\Filament\Resources\ContractorResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $recordTitleAttribute = 'full_name';
    
    protected static ?string $title = 'Kontakty';
    
    protected static ?string $modelLabel = 'kontakt';
    
    protected static ?string $pluralModelLabel = 'kontakty';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('first_name')
                    ->label('Imię')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('last_name')
                    ->label('Nazwisko')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('position')
                    ->label('Stanowisko')
                    ->maxLength(255),
                Forms\Components\TextInput::make('department')
                    ->label('Dział')
                    ->maxLength(255),
                Forms\Components\TextInput::make('phone')
                    ->label('Telefon')
                    ->tel(),
                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email(),
                Forms\Components\Textarea::make('notes')
                    ->label('Uwagi')
                    ->rows(3),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->label('Imię')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->label('Nazwisko')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('position')
                    ->label('Stanowisko'),
                Tables\Columns\TextColumn::make('department')
                    ->label('Dział'),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefon'),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
                Tables\Actions\AttachAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DetachAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make(),
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}