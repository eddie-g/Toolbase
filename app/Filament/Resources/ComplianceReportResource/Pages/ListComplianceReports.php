<?php

namespace App\Filament\Resources\ComplianceReportResource\Pages;

use App\Filament\Resources\ComplianceReportResource;
use App\Filament\Resources\ComplianceReportResource\Widgets\ComplianceStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListComplianceReports extends ListRecords
{
    protected static string $resource = ComplianceReportResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ComplianceStatsWidget::class,
        ];
    }
}