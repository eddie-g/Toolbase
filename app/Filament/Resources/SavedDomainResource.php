<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SavedDomainResource\Pages;
use App\Models\SavedDomain;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SavedDomainResource extends Resource
{
    protected static ?string $model = SavedDomain::class;
    protected static ?string $navigationIcon = 'heroicon-o-heart';
    protected static ?string $navigationLabel = 'Saved Domains';
    protected static ?string $navigationGroup = 'Domains';
    protected static ?string $modelLabel = 'Saved Domain';
    protected static ?string $pluralModelLabel = 'Saved Domains';
    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('domain')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('domain')
                    ->searchable()->sortable()
                    ->url(fn (SavedDomain $record): string =>
                        'https://www.namecheap.com/domains/registration/results/?domain=' . $record->domain,
                        shouldOpenInNewTab: true),
                Tables\Columns\IconColumn::make('is_available')
                    ->label('Available')->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')->falseColor('danger')->sortable(),
                Tables\Columns\IconColumn::make('is_premium')
                    ->label('Premium')->boolean()
                    ->trueColor('warning')->falseColor('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('premium_price')
                    ->label('Premium $')->money('USD')->placeholder('—')
                    ->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('checked_at')
                    ->label('Last Checked')->since()->placeholder('Never')->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Saved At')->dateTime()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('is_available')
                    ->label('Availability')
                    ->options(['1' => 'Available', '0' => 'Taken'])
                    ->placeholder('All'),
                Tables\Filters\SelectFilter::make('user')
                    ->label('User')->relationship('user', 'name')->searchable(),
            ])
            ->actions([Tables\Actions\DeleteAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(),
            ])]);
    }

    public static function getRelations(): array { return []; }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSavedDomains::route('/')];
    }
}
