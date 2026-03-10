<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OverlayEditorTestResource\Pages;
use App\Filament\Resources\OverlayEditorTestResource\Widgets\OverlayEditorTestStatsWidget;
use App\Models\OverlayEditorTest;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;

class OverlayEditorTestResource extends Resource
{
    protected static ?string $model = OverlayEditorTest::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationLabel = 'Test Results';

    protected static ?string $navigationGroup = 'Admin';

    protected static ?string $modelLabel = 'Overlay Editor Test';

    protected static ?string $pluralModelLabel = 'Overlay Editor Tests';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('filename')
                    ->label('Test File')
                    ->searchable()
                    ->sortable()
                    ->size('sm')
                    ->copyable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->wrap()
                    ->limit(80)
                    ->tooltip(fn ($record) => $record->description),
                Tables\Columns\TextColumn::make('test_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'extraction' => 'info',
                        'shapes' => 'warning',
                        'pdf' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('test_category')
                    ->label('Category')
                    ->sortable()
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('section_name')
                    ->label('Section')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Result')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pass' => 'success',
                        'fail' => 'danger',
                        'error' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('checks_summary')
                    ->label('Checks')
                    ->state(fn ($record) => "{$record->checks_passed}/{$record->checks_total}")
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('checks_passed', $direction))
                    ->color(fn ($record) => $record->checks_passed === $record->checks_total ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('page_count')
                    ->label('Pages')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('error')
                    ->label('Error')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->error)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('run_id')
                    ->label('Run')
                    ->limit(8)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pass' => 'Pass',
                        'fail' => 'Fail',
                        'error' => 'Error',
                    ]),
                Tables\Filters\SelectFilter::make('test_type')
                    ->label('Test Type')
                    ->options([
                        'extraction' => 'Extraction',
                        'shapes' => 'Shapes',
                        'pdf' => 'PDF Tests',
                    ]),
                Tables\Filters\SelectFilter::make('run_id')
                    ->label('Test Run')
                    ->options(fn () => OverlayEditorTest::query()
                        ->select('run_id', 'created_at', 'test_type')
                        ->distinct('run_id')
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn ($r) => [$r->run_id => substr($r->run_id, 0, 8) . ' — ' . ucfirst($r->test_type) . ' — ' . $r->created_at->format('M d, H:i')])
                        ->toArray()
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Action::make('run_extraction_tests')
                    ->label('Run Extraction Tests')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->url(fn () => route('filament.admin.pages.run-overlay-editor-tests')),
                Action::make('run_shape_tests')
                    ->label('Run Shape Tests')
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->url(fn () => route('filament.admin.pages.run-shape-tests')),
                Action::make('run_pdf_tests')
                    ->label('Run PDF Tests')
                    ->icon('heroicon-o-play')
                    ->color('danger')
                    ->url(fn () => route('filament.admin.pages.run-pdf-tests')),
            ]);
    }

    public static function getWidgets(): array
    {
        return [
            OverlayEditorTestStatsWidget::class,
        ];
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOverlayEditorTests::route('/'),
            'view' => Pages\ViewOverlayEditorTest::route('/{record}'),
        ];
    }
}
