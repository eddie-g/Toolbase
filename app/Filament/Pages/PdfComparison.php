<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class PdfComparison extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'PDF Comparison';

    protected static ?string $navigationGroup = 'PDF Tests';

    protected static ?string $title = 'PDF Comparison';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.pdf-comparison';
}
