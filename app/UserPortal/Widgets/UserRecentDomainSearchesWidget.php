<?php

namespace App\UserPortal\Widgets;

use App\Models\AiDomainRequest;
use App\Models\SavedDomain;
use App\Services\NamecheapClient;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Cache;

class UserRecentDomainSearchesWidget extends BaseWidget
{
    private const REFRESH_COOLDOWN_KEY_PREFIX = 'saved-domains:refresh:user:';

    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Domains · Recent Searches';

    public function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->query(
                AiDomainRequest::query()
                    ->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->whereRaw('1 = 0'))
                    ->latest()
                    ->limit(100)
            )
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('M d, Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('prompt')
                    ->label('Search Prompt')
                    ->searchable()
                    ->limit(80)
                    ->tooltip(fn ($state) => $state),

                Tables\Columns\TextColumn::make('response')
                    ->label('Results')
                    ->getStateUsing(function (AiDomainRequest $record) {
                        return (string) count($this->extractResultRows($record));
                    })
                    ->badge()
                    ->color('info')
                    ->action(
                        Action::make('viewResults')
                            ->modalHeading(fn (AiDomainRequest $record) => 'Results · ' . str($record->prompt)->limit(80))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Close')
                            ->modalContent(fn (AiDomainRequest $record) => view('user-portal.widgets.domain-search-results', [
                                'rows' => $this->extractResultRows($record),
                            ]))
                    ),
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

                        Notification::make()
                            ->title('Domain availability refreshed from Namecheap')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    private function extractResultRows(AiDomainRequest $record): array
    {
        $rows = [];
        $resultData = $record->result_data;

        if (is_string($resultData) && $resultData !== '') {
            $decoded = json_decode($resultData, true);
            if (is_array($decoded)) {
                $rows = is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
            }
        } elseif (is_array($resultData)) {
            $rows = is_array($resultData['results'] ?? null) ? $resultData['results'] : [];
        }

        if (!empty($rows)) {
            return array_values(array_filter(array_map(function ($row) use ($record) {
                if (!is_array($row) || empty($row['domain'])) {
                    return null;
                }

                if (empty($row['checked_at'])) {
                    $row['checked_at'] = optional($record->updated_at)?->toISOString();
                }

                return $row;
            }, array_filter($rows, fn ($row) => is_array($row) && !empty($row['domain'])))));
        }

        $domains = [];
        if (is_array($record->response)) {
            $domains = is_array($record->response['domains'] ?? null) ? $record->response['domains'] : [];
        }

        return array_values(array_map(function ($domain) {
            return [
                'domain' => strtolower((string) $domain),
                'available' => null,
                'for_sale' => null,
                'error' => null,
                'checked_at' => optional($record->updated_at)?->toISOString(),
            ];
        }, array_filter($domains)));
    }
}
