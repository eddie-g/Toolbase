<?php

namespace App\Filament\Resources\ComplianceReportResource\Widgets;

use App\Models\ComplianceReport;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ComplianceStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        try {
            $latestRun = ComplianceReport::latest()->first();
            if (!$latestRun) {
                return [
                    Stat::make('Total Tests', '0')
                        ->description('No tests run yet')
                        ->color('gray'),
                    Stat::make('Passed', '0')
                        ->color('gray'),
                    Stat::make('Failed', '0')
                        ->color('gray'),
                    Stat::make('Errors', '0')
                        ->color('gray'),
                ];
            }

            $runId = $latestRun->run_id;
            $query = ComplianceReport::where('run_id', $runId);

            $total = (clone $query)->count();
            $passed = (clone $query)->where('status', 'pass')->count();
            $failed = (clone $query)->where('status', 'fail')->count();
            $errors = (clone $query)->where('status', 'error')->count();
            $passRate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

            return [
                Stat::make('Total Tests', $total)
                    ->description('Latest run: ' . substr($runId, 0, 8))
                    ->color('primary'),
                Stat::make('Passed', $passed)
                    ->description("{$passRate}% pass rate")
                    ->color('success'),
                Stat::make('Failed', $failed)
                    ->description($failed > 0 ? 'Needs attention' : 'All clear!')
                    ->color($failed > 0 ? 'danger' : 'success'),
                Stat::make('Errors', $errors)
                    ->description($errors > 0 ? 'Processing errors' : 'No errors')
                    ->color($errors > 0 ? 'warning' : 'success'),
            ];
        } catch (\Throwable $e) {
            return [
                Stat::make('Status', 'Error')
                    ->description('Could not load stats')
                    ->color('danger'),
            ];
        }
    }
}