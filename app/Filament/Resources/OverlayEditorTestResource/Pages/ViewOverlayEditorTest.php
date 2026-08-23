<?php

namespace App\Filament\Resources\OverlayEditorTestResource\Pages;

use App\Filament\Resources\OverlayEditorTestResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewOverlayEditorTest extends ViewRecord
{
    protected static string $resource = OverlayEditorTestResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Test Overview')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('filename')
                            ->label('Test File')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('test_type')
                            ->label('Type')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'extraction' => 'info',
                                'shapes' => 'warning',
                                'pdf' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Result')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pass' => 'success',
                                'fail' => 'danger',
                                'error' => 'warning',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => strtoupper($state)),
                        Infolists\Components\TextEntry::make('description')
                            ->label('Description')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('test_category')
                            ->label('Category'),
                        Infolists\Components\TextEntry::make('section_name')
                            ->label('Section'),
                        Infolists\Components\TextEntry::make('run_id')
                            ->label('Run ID')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('checks_passed')
                            ->label('Checks Passed'),
                        Infolists\Components\TextEntry::make('checks_total')
                            ->label('Checks Total'),
                        Infolists\Components\TextEntry::make('page_count')
                            ->label('Page Count'),
                        Infolists\Components\TextEntry::make('file_size')
                            ->label('File Size')
                            ->formatStateUsing(fn ($state) => $state ? number_format($state) . ' bytes' : '-'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Run Date')
                            ->dateTime(),
                    ]),
                Infolists\Components\Section::make('Individual Checks')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('checks')
                            ->label('')
                            // Runners emit either `item`/`result`/`detail` or `name`/`status`/`message`;
                            // normalise here because repeater children resolve keys off the item state.
                            ->state(fn ($record) => collect($record->checks ?? [])
                                ->map(fn ($check) => [
                                    'item' => $check['item'] ?? $check['name'] ?? '-',
                                    'result' => strtoupper((string) ($check['result'] ?? $check['status'] ?? '')),
                                    'description' => $check['description'] ?? '',
                                    'detail' => $check['detail'] ?? $check['message'] ?? '-',
                                ])
                                ->all())
                            ->schema([
                                Infolists\Components\TextEntry::make('item')
                                    ->label('Check')
                                    ->weight('medium'),
                                Infolists\Components\TextEntry::make('result')
                                    ->label('Result')
                                    ->badge()
                                    ->color(fn ($state) => $state === 'PASS' ? 'success' : 'danger'),
                                Infolists\Components\TextEntry::make('description')
                                    ->label('Assertion')
                                    ->placeholder('-')
                                    ->columnSpanFull(),
                                Infolists\Components\TextEntry::make('detail')
                                    ->label('Details')
                                    ->placeholder('-')
                                    ->copyable()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ])
                    ->visible(fn ($record) => ! empty($record->checks)),
                Infolists\Components\Section::make('Error Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('error')
                            ->label('Error Message')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => !empty($record->error)),
                Infolists\Components\Section::make('Warnings')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('warnings')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('')
                                    ->label('Warning'),
                            ]),
                    ])
                    ->visible(fn ($record) => !empty($record->warnings)),
            ]);
    }
}
