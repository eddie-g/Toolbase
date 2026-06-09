<?php

namespace App\UserPortal\Widgets;

use App\Models\AiDomainRequest;
use App\UserPortal\Pages\Domains;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Livewire\Attributes\On;

class UserRecentDomainSearchesWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Domains · Recent Searches';

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
                    ->url(fn (AiDomainRequest $record): string => Domains::getUrl(['search' => $record->id], panel: 'user')),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Delete')
                    ->modalHeading('Delete domain search')
                    ->modalDescription('This will permanently delete this domain search and its stored results.')
                    ->successNotificationTitle('Domain search deleted'),
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
