<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class DebugPdf extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bug-ant';

    protected static ?string $navigationLabel = 'Debug PDF';

    protected static ?string $navigationGroup = 'PDF Tests';

    protected static ?string $title = 'Debug PDF Annotations';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.debug-pdf';
}
