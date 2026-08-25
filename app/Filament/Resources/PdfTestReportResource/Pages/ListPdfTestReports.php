<?php

namespace App\Filament\Resources\PdfTestReportResource\Pages;

use App\Filament\Resources\PdfTestReportResource;
use App\Filament\Resources\PdfTestReportResource\Widgets\PdfTestReportStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListPdfTestReports extends ListRecords
{
    protected static string $resource = PdfTestReportResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            PdfTestReportStatsWidget::class,
        ];
    }
}
