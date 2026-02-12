<?php

namespace App\Filament\Resources\OverlayEditorTestResource\Pages;

use App\Filament\Resources\OverlayEditorTestResource;
use App\Filament\Resources\OverlayEditorTestResource\Widgets\OverlayEditorTestStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListOverlayEditorTests extends ListRecords
{
    protected static string $resource = OverlayEditorTestResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            OverlayEditorTestStatsWidget::class,
        ];
    }
}
