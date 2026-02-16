<?php

namespace App\Filament\Widgets;

use App\Models\CreditTransaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UsageChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Usage Breakdown';

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $maxHeight = '300px';

    public ?string $filter = '7d';

    protected function getFilters(): ?array
    {
        return [
            '24h' => 'Last 24 hours',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            'all' => 'All time',
        ];
    }

    protected function getData(): array
    {
        $filter = $this->filter;

        [$startDate, $groupFormat, $labelFormat, $interval] = match ($filter) {
            '24h' => [now()->subHours(24), '%Y-%m-%d %H:00', 'H:i', 'hour'],
            '7d' => [now()->subDays(7), '%Y-%m-%d', 'M d', 'day'],
            '30d' => [now()->subDays(30), '%Y-%m-%d', 'M d', 'day'],
            'all' => [now()->subYear(), '%Y-%m', 'M Y', 'month'],
            default => [now()->subDays(7), '%Y-%m-%d', 'M d', 'day'],
        };

        $query = CreditTransaction::where('type', 'debit')
            ->where('created_at', '>=', $startDate);

        $services = $query->clone()->distinct()->pluck('service')->toArray();

        $colorMap = [
            'logo_generation' => 'rgba(59, 130, 246, 0.8)',
            'logo_describe' => 'rgba(168, 85, 247, 0.8)',
            'domain_search' => 'rgba(34, 197, 94, 0.8)',
            'compliance' => 'rgba(249, 115, 22, 0.8)',
            'pdf_editor' => 'rgba(236, 72, 153, 0.8)',
        ];
        $defaultColors = [
            'rgba(99, 102, 241, 0.8)',
            'rgba(20, 184, 166, 0.8)',
            'rgba(245, 158, 11, 0.8)',
            'rgba(239, 68, 68, 0.8)',
        ];

        $datasets = [];
        $allLabels = [];

        $current = Carbon::parse($startDate);
        $end = now();
        while ($current <= $end) {
            $allLabels[$current->format(match ($interval) {
                'hour' => 'Y-m-d H:00',
                'day' => 'Y-m-d',
                'month' => 'Y-m',
            })] = $current->format($labelFormat);
            $current = match ($interval) {
                'hour' => $current->addHour(),
                'day' => $current->addDay(),
                'month' => $current->addMonth(),
            };
        }

        $colorIdx = 0;
        foreach ($services as $service) {
            $rows = CreditTransaction::where('type', 'debit')
                ->where('service', $service)
                ->where('created_at', '>=', $startDate)
                ->select(DB::raw("DATE_FORMAT(created_at, '{$groupFormat}') as period"), DB::raw('SUM(amount) as total'))
                ->groupBy('period')
                ->pluck('total', 'period')
                ->toArray();

            $data = [];
            foreach (array_keys($allLabels) as $key) {
                $data[] = round((float) ($rows[$key] ?? 0), 6);
            }

            $color = $colorMap[$service] ?? ($defaultColors[$colorIdx % count($defaultColors)] ?? 'rgba(107, 114, 128, 0.8)');
            $colorIdx++;

            $datasets[] = [
                'label' => ucwords(str_replace('_', ' ', $service)),
                'data' => $data,
                'backgroundColor' => $color,
                'borderColor' => str_replace('0.8', '1', $color),
                'borderWidth' => 1,
            ];
        }

        if (empty($datasets)) {
            $datasets[] = [
                'label' => 'No usage data',
                'data' => array_fill(0, max(1, count($allLabels)), 0),
                'backgroundColor' => 'rgba(107, 114, 128, 0.3)',
            ];
        }

        return [
            'labels' => array_values($allLabels),
            'datasets' => $datasets,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
            'scales' => [
                'x' => [
                    'stacked' => true,
                ],
                'y' => [
                    'stacked' => true,
                ],
            ],
        ];
    }
}
