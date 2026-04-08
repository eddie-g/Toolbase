<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class PdfReconstruction extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = 'PDF Reconstruction';

    protected static ?string $navigationGroup = 'PDF Tests';

    protected static ?string $title = 'PDF Reconstruction';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.pdf-reconstruction';
}
