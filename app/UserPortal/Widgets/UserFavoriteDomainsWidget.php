<?php

namespace App\UserPortal\Widgets;

use App\Models\SavedDomain;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Livewire\Attributes\On;

class UserFavoriteDomainsWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Domains · Favorited Domains';

    #[On('domains-refreshed')]
    public function refreshAfterDomainsRefresh(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->query(
                SavedDomain::query()
                    ->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->whereRaw('1 = 0'))
                    ->latest()
                    ->limit(100)
            )
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Saved')
                    ->dateTime('M d, Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('domain')
                    ->label('Domain')
                    ->searchable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\IconColumn::make('is_available')
                    ->label('Available')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),

                Tables\Columns\IconColumn::make('is_premium')
                    ->label('Premium')
                    ->boolean(),

                Tables\Columns\TextColumn::make('checked_at')
                    ->label('Last Checked')
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->actions([
                Action::make('buyNow')
                    ->label('Buy Now')
                    ->icon('heroicon-o-shopping-cart')
                    ->url(fn (SavedDomain $record) => 'https://www.namecheap.com/domains/registration/results/?domain=' . urlencode($record->domain))
                    ->openUrlInNewTab(),
                Tables\Actions\DeleteAction::make()
                    ->label('Remove')
                    ->modalHeading('Remove favorited domain')
                    ->modalDescription('This will remove the domain from your favorited domains.')
                    ->successNotificationTitle('Favorited domain removed'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
