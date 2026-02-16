<?php

namespace App\Filament\Widgets;

use App\Models\CreditTransaction;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentTransactionsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Recent Transactions';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CreditTransaction::query()
                    ->latest()
                    ->limit(50)
            )
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('M d, Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'debit' => 'danger',
                        'topup' => 'success',
                        'refund' => 'warning',
                        'credit' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('service')
                    ->label('Service')
                    ->formatStateUsing(fn (string $state) => ucwords(str_replace('_', ' ', $state)))
                    ->searchable(),

                Tables\Columns\TextColumn::make('model_name')
                    ->label('Model')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(function ($state, CreditTransaction $record) {
                        $prefix = $record->type === 'debit' ? '-' : '+';
                        return $prefix . '$' . number_format((float) $state, 6);
                    })
                    ->color(fn (CreditTransaction $record) => $record->type === 'debit' ? 'danger' : 'success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Balance After')
                    ->formatStateUsing(fn ($state) => '$' . number_format((float) $state, 4))
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(40)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
