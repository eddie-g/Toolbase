<?php

namespace App\Filament\Pages;

use App\Models\AiLogoPrice;
use App\Models\AiLogoRequest;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LogoRequests extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Logo Requests';

    protected static ?string $title = 'Logo Requests';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.logo-requests';

    public function table(Table $table): Table
    {
        return $table
            ->query(AiLogoRequest::query()->with('user'))
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
                        'failed', 'error' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('domain')
                    ->label('Domain')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Icon only')
                    ->limit(30),

                TextColumn::make('model')
                    ->label('Model')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->limit(28),

                TextColumn::make('style')
                    ->label('Style')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('output_format')
                    ->label('Format')
                    ->badge()
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('prompt')
                    ->label('Prompt')
                    ->searchable()
                    ->limit(70)
                    ->tooltip(fn (?string $state): ?string => $state),

                TextColumn::make('image_count')
                    ->label('Images')
                    ->getStateUsing(fn (AiLogoRequest $record): int => $this->imageCount($record))
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('image_size')
                    ->label('Size')
                    ->getStateUsing(fn (AiLogoRequest $record): string => $this->latestPrice($record)?->image_size ?? $this->dimensions($record))
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('response_time_ms')
                    ->label('Runtime')
                    ->formatStateUsing(fn ($state): string => $state ? number_format(((int) $state) / 1000, 2) . 's' : '-')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cost_usd')
                    ->label('Cost')
                    ->getStateUsing(fn (AiLogoRequest $record): string => '$' . number_format($this->costFor($record), 6))
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'completed' => 'Completed',
                        'processing' => 'Processing',
                        'pending' => 'Pending',
                        'failed' => 'Failed',
                        'error' => 'Error',
                    ])
                    ->placeholder('All statuses'),

                SelectFilter::make('model')
                    ->options(fn (): array => AiLogoRequest::query()
                        ->whereNotNull('model')
                        ->distinct()
                        ->orderBy('model')
                        ->pluck('model', 'model')
                        ->all())
                    ->placeholder('All models'),

                SelectFilter::make('output_format')
                    ->label('Format')
                    ->options([
                        'raster' => 'Raster',
                        'vector' => 'Vector',
                    ])
                    ->placeholder('All formats'),

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
        $totalCount = AiLogoRequest::query()->count();
        $completedCount = AiLogoRequest::query()->where('status', 'completed')->count();
        $failedCount = AiLogoRequest::query()->whereIn('status', ['failed', 'error'])->count();
        $totalCost = (float) AiLogoPrice::query()->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(actual_cost_usd, estimated_cost_usd, 0)'));
        $completedCost = (float) AiLogoPrice::query()
            ->where('status', 'completed')
            ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(actual_cost_usd, estimated_cost_usd, 0)'));

        return [
            'totalCount' => $totalCount,
            'completedCount' => $completedCount,
            'failedCount' => $failedCount,
            'estimatedCost' => $totalCost,
            'completedCost' => $completedCost,
            'averageCost' => $totalCount > 0 ? $totalCost / $totalCount : 0.0,
        ];
    }

    private function latestPrice(AiLogoRequest $request): ?AiLogoPrice
    {
        return AiLogoPrice::query()
            ->where('ai_logo_request_id', $request->id)
            ->latest('id')
            ->first();
    }

    private function costFor(AiLogoRequest $request): float
    {
        return (float) AiLogoPrice::query()
            ->where('ai_logo_request_id', $request->id)
            ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(actual_cost_usd, estimated_cost_usd, 0)'));
    }

    private function imageCount(AiLogoRequest $request): int
    {
        $urls = $request->image_urls;
        if (is_string($urls)) {
            $decoded = json_decode($urls, true);
            $urls = is_array($decoded) ? $decoded : [];
        }

        if (is_array($urls) && count($urls) > 0) {
            return count($urls);
        }

        return (int) (AiLogoPrice::query()
            ->where('ai_logo_request_id', $request->id)
            ->sum('image_count') ?: 0);
    }

    private function dimensions(AiLogoRequest $request): string
    {
        if ($request->width && $request->height) {
            return "{$request->width}x{$request->height}";
        }

        return '-';
    }
}
