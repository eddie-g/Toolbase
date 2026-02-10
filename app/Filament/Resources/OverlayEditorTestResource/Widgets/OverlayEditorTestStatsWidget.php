<?php

namespace App\Filament\Resources\OverlayEditorTestResource\Widgets;

use App\Models\OverlayEditorTest;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverlayEditorTestStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $latestRunId = OverlayEditorTest::orderByDesc('created_at')->value('run_id');

        if (!$latestRunId) {
            return [
                Stat::make('No test runs yet', 'Run tests to see results'),
            ];
        }

        $latestRun = OverlayEditorTest::where('run_id', $latestRunId);
        $total = $latestRun->count();
        $passed = (clone $latestRun)->where('status', 'pass')->count();
        $failed = (clone $latestRun)->where('status', 'fail')->count();
        $errors = (clone $latestRun)->where('status', 'error')->count();
        $testType = OverlayEditorTest::where('run_id', $latestRunId)->value('test_type');

        return [
            Stat::make('Latest Run', ucfirst($testType ?? 'Unknown'))
                ->description('Run ID: ' . substr($latestRunId, 0, 8))
                ->color('primary'),
            Stat::make('Total Tests', $total)
                ->description('In latest run')
                ->color('primary'),
            Stat::make('Passed', $passed)
                ->description(round($total > 0 ? ($passed / $total) * 100 : 0) . '% pass rate')
                ->color('success'),
            Stat::make('Failed', $failed)
                ->color('danger'),
            Stat::make('Errors', $errors)
                ->color('warning'),
        ];
    }
}
