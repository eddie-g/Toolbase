<?php

namespace App\UserPortal\Widgets;

use App\Models\UserActivity;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class UserPdfCommandsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'PDF Commands';

    public function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->query(
                UserActivity::query()
                    ->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->whereRaw('1 = 0'))
                    ->latest()
                    ->limit(500)
            )
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('M d, Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('action')
                    ->label('Command')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->searchable(),

                Tables\Columns\TextColumn::make('document.original_name')
                    ->label('Document')
                    ->placeholder('—')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => $state === 'success' ? 'success' : 'danger'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
