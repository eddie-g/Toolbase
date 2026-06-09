<?php

namespace App\UserPortal\Pages;

use App\Models\AiDomainRequest;
use App\Models\SavedDomain;
use App\Services\NamecheapClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

class Domains extends Page
{
    private const REFRESH_COOLDOWN_KEY_PREFIX = 'saved-domains:refresh:user:';

    protected static ?string $title = 'Domains';

    protected static ?string $navigationLabel = 'Domains';

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'user-portal.pages.domains';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshDomains')
                ->label(fn (): string => $this->refreshDomainsLabel())
                ->icon('heroicon-o-arrow-path')
                ->disabled(fn (): bool => $this->refreshDomainsDisabled())
                ->action('refreshDomains'),
        ];
    }

    public function refreshDomains(): void
    {
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

        $domains = $this->domainsToRefresh();

        if (empty($domains)) {
            Notification::make()
                ->title('No domains to refresh')
                ->body('Save domains or run a domain search first.')
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

        $this->refreshRecentSearchRows($rows->all());

        Cache::put($cooldownKey, now()->addHour()->toISOString(), now()->addHour());

        Notification::make()
            ->title('Domain availability refreshed')
            ->body('Saved domains and recent search results were updated.')
            ->success()
            ->send();

        $this->dispatch('domains-refreshed');
    }

    public function selectedSearchRecord(): ?AiDomainRequest
    {
        $searchId = (int) request()->query('search', 0);
        $user = auth()->user();

        if ($searchId <= 0 || !$user) {
            return null;
        }

        return AiDomainRequest::query()
            ->where('user_id', $user->id)
            ->find($searchId);
    }

    public function resultRowsFor(AiDomainRequest $record): array
    {
        $payload = $this->resultDataPayload($record);
        $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];

        if (!empty($rows)) {
            return array_values(array_filter(array_map(function ($row) use ($record) {
                if (!is_array($row) || empty($row['domain'])) {
                    return null;
                }

                if (empty($row['checked_at'])) {
                    $row['checked_at'] = optional($record->updated_at)?->toISOString();
                }

                return $row;
            }, $rows)));
        }

        $domains = is_array($record->response)
            ? (is_array($record->response['domains'] ?? null) ? $record->response['domains'] : [])
            : [];

        return array_values(array_map(function ($domain) use ($record) {
            return [
                'domain' => strtolower((string) $domain),
                'available' => null,
                'for_sale' => null,
                'error' => null,
                'checked_at' => optional($record->updated_at)?->toISOString(),
            ];
        }, array_filter($domains)));
    }

    private function refreshDomainsLabel(): string
    {
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
    }

    private function refreshDomainsDisabled(): bool
    {
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
    }

    private function domainsToRefresh(): array
    {
        $user = auth()->user();

        $savedDomains = SavedDomain::query()
            ->where('user_id', $user?->id)
            ->pluck('domain');

        $recentDomains = AiDomainRequest::query()
            ->where('user_id', $user?->id)
            ->latest()
            ->limit(100)
            ->get()
            ->flatMap(fn (AiDomainRequest $record) => $this->extractDomains($record));

        return $savedDomains
            ->merge($recentDomains)
            ->filter()
            ->map(fn ($domain) => strtolower(trim((string) $domain)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function refreshRecentSearchRows(array $resultsByDomain): void
    {
        $user = auth()->user();

        AiDomainRequest::query()
            ->where('user_id', $user?->id)
            ->latest()
            ->limit(100)
            ->get()
            ->each(function (AiDomainRequest $record) use ($resultsByDomain): void {
                $payload = $this->resultDataPayload($record);
                $rows = is_array($payload['results'] ?? null) ? $payload['results'] : null;

                if (!$rows) {
                    return;
                }

                $changed = false;
                foreach ($rows as &$row) {
                    if (!is_array($row) || empty($row['domain'])) {
                        continue;
                    }

                    $domain = strtolower((string) $row['domain']);
                    $result = $resultsByDomain[$domain] ?? null;
                    if (!$result) {
                        continue;
                    }

                    $row['available'] = (bool) ($result['available'] ?? false);
                    $row['is_available'] = (bool) ($result['available'] ?? false);
                    $row['premium'] = (bool) ($result['premium'] ?? false);
                    $row['is_premium'] = (bool) ($result['premium'] ?? false);
                    $row['checked_at'] = now()->toISOString();
                    $changed = true;
                }
                unset($row);

                if (!$changed) {
                    return;
                }

                $payload['results'] = $rows;
                $record->forceFill(['result_data' => json_encode($payload)])->save();
            });
    }

    private function extractDomains(AiDomainRequest $record): array
    {
        $payload = $this->resultDataPayload($record);
        $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];

        if ($rows) {
            return array_values(array_filter(array_map(
                fn ($row) => is_array($row) ? ($row['domain'] ?? null) : null,
                $rows
            )));
        }

        if (is_array($record->response)) {
            return is_array($record->response['domains'] ?? null) ? $record->response['domains'] : [];
        }

        return [];
    }

    private function resultDataPayload(AiDomainRequest $record): array
    {
        $resultData = $record->getAttribute('result_data');

        if (is_string($resultData) && $resultData !== '') {
            $decoded = json_decode($resultData, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($resultData) ? $resultData : [];
    }
}
