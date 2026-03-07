<?php

namespace App\UserPortal\Widgets;

use App\Models\SavedDomain;
use App\Services\NamecheapClient;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Cache;

class UserFavoriteDomainsWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Domains · Favorited Domains';

    private const REFRESH_COOLDOWN_KEY_PREFIX = 'saved-domains:refresh:user:';

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
            ])
            ->headerActions([
                Action::make('refreshDomains')
                    ->label(function (): string {
                        $user = auth()->user();
                        if (!$user) {
                            return 'Refresh Domains';
                        }

                        $nextAllowedAt = Cache::get(self::REFRESH_COOLDOWN_KEY_PREFIX . $user->id);
                        if (!$nextAllowedAt) {
                            return 'Refresh Domains';
                        }

                        try {
                            $next = \Illuminate\Support\Carbon::parse((string) $nextAllowedAt);
                        } catch (\Throwable $e) {
                            return 'Refresh Domains';
                        }

                        if (now()->gte($next)) {
                            Cache::forget(self::REFRESH_COOLDOWN_KEY_PREFIX . $user->id);
                            return 'Refresh Domains';
                        }

                        return 'Refresh in ' . now()->diffForHumans($next, true, false, 2);
                    })
                    ->icon('heroicon-o-arrow-path')
                    ->disabled(function (): bool {
                        $user = auth()->user();
                        if (!$user) {
                            return false;
                        }

                        $nextAllowedAt = Cache::get(self::REFRESH_COOLDOWN_KEY_PREFIX . $user->id);
                        if (!$nextAllowedAt) {
                            return false;
                        }

                        try {
                            return now()->lt(\Illuminate\Support\Carbon::parse((string) $nextAllowedAt));
                        } catch (\Throwable $e) {
                            return false;
                        }
                    })
                    ->action(function (): void {
                        $user = auth()->user();
                        if (!$user) {
                            return;
                        }

                        $cooldownKey = self::REFRESH_COOLDOWN_KEY_PREFIX . $user->id;
                        $nextAllowedAt = Cache::get($cooldownKey);

                        if ($nextAllowedAt && now()->lt(\Illuminate\Support\Carbon::parse($nextAllowedAt))) {
                            Notification::make()
                                ->title('Refresh available in ' . now()->diffForHumans(\Illuminate\Support\Carbon::parse($nextAllowedAt), true))
                                ->warning()
                                ->send();
                            return;
                        }

                        $domains = SavedDomain::query()
                            ->where('user_id', $user->id)
                            ->pluck('domain')
                            ->filter()
                            ->map(fn ($d) => strtolower(trim((string) $d)))
                            ->unique()
                            ->values()
                            ->all();

                        if (empty($domains)) {
                            Notification::make()
                                ->title('No favorited domains to refresh')
                                ->warning()
                                ->send();
                            return;
                        }

                        try {
                            $results = app(NamecheapClient::class)->checkFqdns($domains);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Namecheap refresh failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                            return;
                        }
                        $rows = collect($results['results'] ?? [])
                            ->keyBy(fn ($item) => strtolower((string) ($item['domain'] ?? '')));

                        foreach ($domains as $domain) {
                            $row = $rows->get($domain);
                            if (!$row) {
                                continue;
                            }

                            SavedDomain::query()
                                ->where('user_id', $user->id)
                                ->whereRaw('LOWER(domain) = ?', [$domain])
                                ->update([
                                    'is_available' => (bool) ($row['available'] ?? false),
                                    'is_premium' => (bool) ($row['premium'] ?? false),
                                    'checked_at' => now(),
                                ]);
                        }

                        Cache::put($cooldownKey, now()->addHour()->toISOString(), now()->addHour());
                        $this->resetTable();

                        Notification::make()
                            ->title('Domain availability refreshed from Namecheap')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
