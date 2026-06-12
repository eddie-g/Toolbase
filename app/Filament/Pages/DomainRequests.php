<?php

namespace App\Filament\Pages;

use App\Models\AiDomainRequest;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DomainRequests extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Domain Requests';

    protected static ?string $title = 'Domain Requests';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.domain-requests';

    public function table(Table $table): Table
    {
        return $table
            ->query(AiDomainRequest::query()->with('user'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),

                TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->placeholder('Unknown')
                    ->limit(30),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing', 'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('model')
                    ->label('Model')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('prompt')
                    ->label('Prompt')
                    ->searchable()
                    ->limit(70)
                    ->tooltip(fn (?string $state): ?string => $state),

                TextColumn::make('domains')
                    ->label('Domains')
                    ->getStateUsing(fn (AiDomainRequest $record): string => implode(', ', array_slice($this->extractDomains($record), 0, 6)))
                    ->placeholder('-')
                    ->limit(70)
                    ->tooltip(fn (AiDomainRequest $record): string => implode(', ', $this->extractDomains($record)))
                    ->toggleable(),

                TextColumn::make('tlds_list')
                    ->label('TLDs')
                    ->getStateUsing(fn (AiDomainRequest $record): string => implode(', ', $this->extractTlds($record)))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('input_tokens')
                    ->label('Input')
                    ->getStateUsing(fn (AiDomainRequest $record): int => $this->inputTokens($record))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('output_tokens')
                    ->label('Output')
                    ->getStateUsing(fn (AiDomainRequest $record): int => $this->outputTokens($record))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('cost_usd')
                    ->label('Cost')
                    ->getStateUsing(fn (AiDomainRequest $record): string => '$' . number_format($this->costFor($record), 6))
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'completed' => 'Completed',
                        'processing' => 'Processing',
                        'pending' => 'Pending',
                        'failed' => 'Failed',
                    ])
                    ->placeholder('All statuses'),

                SelectFilter::make('model')
                    ->options(fn (): array => AiDomainRequest::query()
                        ->whereNotNull('model')
                        ->distinct()
                        ->orderBy('model')
                        ->pluck('model', 'model')
                        ->all())
                    ->placeholder('All models'),

                Filter::make('period')
                    ->label('Time Period')
                    ->form([
                        Select::make('period')
                            ->label('Time Period')
                            ->options([
                                'today' => 'Today',
                                '7d' => 'Last 7 days',
                                '30d' => 'Last 30 days',
                                'all' => 'All time',
                            ])
                            ->default('all'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['period'] ?? 'all') {
                            'today' => $query->whereDate('created_at', today()),
                            '7d' => $query->where('created_at', '>=', now()->subDays(7)),
                            '30d' => $query->where('created_at', '>=', now()->subDays(30)),
                            default => $query,
                        };
                    })
                    ->indicateUsing(function (array $data): ?string {
                        return match ($data['period'] ?? 'all') {
                            'today' => 'Today',
                            '7d' => 'Last 7 days',
                            '30d' => 'Last 30 days',
                            default => null,
                        };
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->filtersLayout(\Filament\Tables\Enums\FiltersLayout::AboveContent);
    }

    public function getViewData(): array
    {
        $requests = AiDomainRequest::query()->get();
        $completed = $requests->where('status', 'completed');

        return [
            'totalCount' => $requests->count(),
            'completedCount' => $completed->count(),
            'estimatedCost' => $requests->sum(fn (AiDomainRequest $request): float => $this->costFor($request)),
            'inputTokens' => $requests->sum(fn (AiDomainRequest $request): int => $this->inputTokens($request)),
            'outputTokens' => $requests->sum(fn (AiDomainRequest $request): int => $this->outputTokens($request)),
        ];
    }

    private function extractDomains(AiDomainRequest $request): array
    {
        $payload = $request->response;

        if (!is_array($payload) && $request->result_data) {
            $decoded = json_decode((string) $request->result_data, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $domains = $payload['domains'] ?? [];

        return array_values(array_filter(array_map(
            fn ($domain): string => strtolower(trim((string) $domain)),
            is_array($domains) ? $domains : []
        )));
    }

    private function extractTlds(AiDomainRequest $request): array
    {
        $tlds = $request->getAttribute('tlds');
        if (is_string($tlds)) {
            $decoded = json_decode($tlds, true);
            $tlds = is_array($decoded) ? $decoded : [];
        }

        return array_values(array_filter(array_map(
            fn ($tld): string => ltrim(strtolower(trim((string) $tld)), '.'),
            is_array($tlds) ? $tlds : []
        )));
    }

    private function inputTokens(AiDomainRequest $request): int
    {
        $usage = $request->usage ?? [];

        return (int) ($usage['promptTokenCount'] ?? 0);
    }

    private function outputTokens(AiDomainRequest $request): int
    {
        $usage = $request->usage ?? [];
        $total = (int) ($usage['totalTokenCount'] ?? 0);
        $input = $this->inputTokens($request);

        if ($total > $input) {
            return $total - $input;
        }

        return (int) ($usage['candidatesTokenCount'] ?? 0)
            + (int) ($usage['thoughtsTokenCount'] ?? 0);
    }

    private function costFor(AiDomainRequest $request): float
    {
        [$inputPerMillion, $outputPerMillion] = $this->pricingFor($request->model);

        return ($this->inputTokens($request) / 1_000_000 * $inputPerMillion)
            + ($this->outputTokens($request) / 1_000_000 * $outputPerMillion);
    }

    private function pricingFor(?string $model): array
    {
        $model = strtolower((string) $model);

        return match (true) {
            str_contains($model, '3.1-flash-lite') => [0.25, 1.50],
            str_contains($model, '2.5-flash-lite') => [0.10, 0.40],
            str_contains($model, '2.5-flash') => [0.30, 2.50],
            str_contains($model, '2.0-flash') => [0.10, 0.40],
            default => [
                (float) config('services.gemini.pricing.input_per_million', 0.10),
                (float) config('services.gemini.pricing.output_per_million', 0.40),
            ],
        };
    }
}
