<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ComplianceReportResource\Pages;
use App\Models\ComplianceReport;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;

class ComplianceReportResource extends Resource
{
    protected static ?string $model = ComplianceReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Compliance Report';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $modelLabel = 'Compliance Test';

    protected static ?string $pluralModelLabel = 'Compliance Tests';

    protected static ?int $navigationSort = 20;

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
                Tables\Columns\TextColumn::make('test_category')
                    ->label('Section')
                    ->sortable()
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('section_name')
                    ->label('Area')
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
                Tables\Columns\IconColumn::make('conversion_success')
                    ->label('Conv.')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('checks_summary')
                    ->label('Checks')
                    ->state(fn ($record) => "{$record->checks_passed}/{$record->checks_total}")
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('checks_passed', $direction))
                    ->color(fn ($record) => $record->checks_passed === $record->checks_total ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('compliance_status')
                    ->label('Compliance')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'Compliant' => 'success',
                        'Non-Compliant' => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('error')
                    ->label('Error')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->error)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('level')
                    ->label('Level')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (string $state): string => "PDF/A-" . strtoupper($state))
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
                Tables\Filters\SelectFilter::make('test_category')
                    ->label('Section')
                    ->options(fn () => ComplianceReport::query()
                        ->distinct()
                        ->pluck('test_category', 'test_category')
                        ->mapWithKeys(fn ($cat) => [$cat => $cat])
                        ->toArray()
                    ),
                Tables\Filters\SelectFilter::make('run_id')
                    ->label('Test Run')
                    ->options(fn () => ComplianceReport::query()
                        ->select('run_id', 'created_at')
                        ->distinct('run_id')
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn ($r) => [$r->run_id => substr($r->run_id, 0, 8) . ' — ' . $r->created_at->format('M d, H:i')])
                        ->toArray()
                    ),
                Tables\Filters\TernaryFilter::make('conversion_success')
                    ->label('Conversion Success'),
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
                Action::make('run_tests')
                    ->label('Run Compliance Tests')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->url(fn () => route('filament.admin.pages.run-compliance-tests')),
            ]);
    }

    public static function getWidgets(): array
    {
        return [
            ComplianceReportResource\Widgets\ComplianceStatsWidget::class,
        ];
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComplianceReports::route('/'),
            'view' => Pages\ViewComplianceReport::route('/{record}'),
        ];
    }
}