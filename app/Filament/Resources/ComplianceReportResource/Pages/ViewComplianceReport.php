<?php

namespace App\Filament\Resources\ComplianceReportResource\Pages;

use App\Filament\Resources\ComplianceReportResource;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewComplianceReport extends ViewRecord
{
    protected static string $resource = ComplianceReportResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Test Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('filename')
                            ->label('Test File'),
                        Infolists\Components\TextEntry::make('description')
                            ->label('Description')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('test_category')
                            ->label('Section'),
                        Infolists\Components\TextEntry::make('section_name')
                            ->label('Area'),
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
                        Infolists\Components\TextEntry::make('level')
                            ->label('Level')
                            ->formatStateUsing(fn (string $state): string => "PDF/A-" . strtoupper($state)),
                    ])->columns(3),
                Infolists\Components\Section::make('Conversion Results')
                    ->schema([
                        Infolists\Components\IconEntry::make('conversion_success')
                            ->label('Conversion Success')
                            ->boolean(),
                        Infolists\Components\TextEntry::make('compliance_status')
                            ->label('Compliance Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'Compliant' => 'success',
                                'Non-Compliant' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('checks_passed')
                            ->label('Checks Passed'),
                        Infolists\Components\TextEntry::make('checks_total')
                            ->label('Total Checks'),
                        Infolists\Components\TextEntry::make('file_size_input')
                            ->label('Input Size')
                            ->formatStateUsing(fn ($state) => number_format($state / 1024, 1) . ' KB'),
                        Infolists\Components\TextEntry::make('file_size_output')
                            ->label('Output Size')
                            ->formatStateUsing(fn ($state) => $state > 0 ? number_format($state / 1024, 1) . ' KB' : 'N/A'),
                    ])->columns(3),
                Infolists\Components\Section::make('Compliance Checks')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('checks')
                            ->schema([
                                Infolists\Components\TextEntry::make('item')
                                    ->label('Check'),
                                Infolists\Components\TextEntry::make('result')
                                    ->label('Result')
                                    ->badge()
                                    ->color(fn (?string $state): string => match ($state) {
                                        'PASS' => 'success',
                                        'FAIL' => 'danger',
                                        default => 'gray',
                                    }),
                                Infolists\Components\TextEntry::make('detail')
                                    ->label('Detail'),
                                Infolists\Components\TextEntry::make('description')
                                    ->label('Description'),
                            ])->columns(4),
                    ])
                    ->collapsible(),
                Infolists\Components\Section::make('Errors & Warnings')
                    ->schema([
                        Infolists\Components\TextEntry::make('error')
                            ->label('Error')
                            ->columnSpanFull()
                            ->placeholder('No errors'),
                        Infolists\Components\TextEntry::make('warnings')
                            ->label('Warnings')
                            ->listWithLineBreaks()
                            ->columnSpanFull()
                            ->placeholder('No warnings'),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Infolists\Components\Section::make('Run Info')
                    ->schema([
                        Infolists\Components\TextEntry::make('run_id')
                            ->label('Run ID')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Run Date')
                            ->dateTime(),
                    ])->columns(2),
            ]);
    }
}