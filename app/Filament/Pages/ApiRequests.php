<?php

namespace App\Filament\Pages;

use App\Models\CreditTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ApiRequests extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Requests';

    protected static ?string $title = 'API Requests';

    protected static ?string $navigationGroup = 'Requests';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.api-requests';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CreditTransaction::query()
                    ->where('type', '!=', 'topup')
                    ->with('user')
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),

                TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('service')
                    ->label('Service')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'logo_generation'  => 'Logo Generation',
                        'logo_bg_removal'  => 'BG Removal',
                        'domain_search'    => 'Domain Search',
                        'pdf_editor'       => 'PDF Editor',
                        'compliance'       => 'Compliance',
                        default            => ucwords(str_replace('_', ' ', $state)),
                    })
                    ->color(fn (string $state) => match ($state) {
                        'logo_generation' => 'success',
                        'logo_bg_removal' => 'info',
                        'domain_search'   => 'warning',
                        'pdf_editor'      => 'primary',
                        default           => 'gray',
                    }),

                TextColumn::make('model_name')
                    ->label('Endpoint / Model')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => $state ?? '—'),

                TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(),

                TextColumn::make('amount')
                    ->label('Cost')
                    ->formatStateUsing(function ($state, CreditTransaction $record) {
                        $prefix = $record->type === 'debit' ? '-' : '+';
                        return $prefix . '$' . number_format((float) $state, 6);
                    })
                    ->color(fn (CreditTransaction $record) => $record->type === 'debit' ? 'danger' : 'success')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('service')
                    ->label('Service')
                    ->options([
                        'logo_generation' => 'Logo Generation',
                        'logo_bg_removal' => 'BG Removal',
                        'domain_search'   => 'Domain Search',
                        'pdf_editor'      => 'PDF Editor',
                        'compliance'      => 'Compliance',
                    ])
                    ->placeholder('All Services'),

                SelectFilter::make('model_name')
                    ->label('Endpoint')
                    ->options([
                        'fal-ai/flux/schnell'      => 'Flux Schnell',
                        'fal-ai/flux-2-flex'       => 'Flux 2 Flex (Pro)',
                        'fal-ai/flux-pro/v1.1'     => 'Flux Pro v1.1 (Legacy)',
                        'gpt-image-1.5'            => 'GPT Image 1.5',
                        'dall-e-3'                 => 'DALL·E 3 (Legacy)',
                        'recraft-v3-vector'        => 'Recraft v3 Vector',
                        'recraft-v3-raster'        => 'Recraft v3 Raster',
                        'recraft-v4-raster'        => 'Recraft v4 Raster',
                        'recraft-v2-vector'        => 'Recraft v2 Vector',
                        'recraft-v2-raster'        => 'Recraft v2 Raster',
                        'recraft/removeBackground' => 'Recraft BG Removal',
                    ])
                    ->placeholder('All Endpoints'),

                Filter::make('period')
                    ->label('Time Period')
                    ->form([
                        \Filament\Forms\Components\Select::make('period')
                            ->label('Time Period')
                            ->options([
                                'today' => 'Today',
                                '7d'    => 'Last 7 days',
                                '30d'   => 'Last 30 days',
                                'all'   => 'All time',
                            ])
                            ->default('all'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $period = $data['period'] ?? 'all';
                        return match ($period) {
                            'today' => $query->whereDate('created_at', today()),
                            '7d'    => $query->where('created_at', '>=', now()->subDays(7)),
                            '30d'   => $query->where('created_at', '>=', now()->subDays(30)),
                            default => $query,
                        };
                    })
                    ->indicateUsing(function (array $data): ?string {
                        return match ($data['period'] ?? 'all') {
                            'today' => 'Today',
                            '7d'    => 'Last 7 days',
                            '30d'   => 'Last 30 days',
                            default => null,
                        };
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->filtersLayout(\Filament\Tables\Enums\FiltersLayout::AboveContent);
    }
}
