<?php

namespace App\Filament\Resources\UserActivityResource\Pages;

use App\Filament\Resources\UserActivityResource;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewUserActivity extends ViewRecord
{
    protected static string $resource = UserActivityResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Activity Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('user.name')
                            ->label('User'),
                        Infolists\Components\TextEntry::make('action'),
                        Infolists\Components\TextEntry::make('category')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'image_export' => 'info',
                                'pdfa_export' => 'success',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'image_export' => 'Image Export',
                                'pdfa_export' => 'PDF/A Export',
                                default => ucfirst(str_replace('_', ' ', $state)),
                            }),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'success' => 'success',
                                'failed' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('document.original_name')
                            ->label('Document'),
                        Infolists\Components\KeyValueEntry::make('details')
                            ->label('Details'),
                    ])->columns(2),
                Infolists\Components\Section::make('Request Info')
                    ->schema([
                        Infolists\Components\TextEntry::make('ip_address')
                            ->label('IP Address'),
                        Infolists\Components\TextEntry::make('user_agent')
                            ->label('User Agent')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Date')
                            ->dateTime(),
                    ])->columns(2),
            ]);
    }
}