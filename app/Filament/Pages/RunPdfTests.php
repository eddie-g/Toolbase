<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class RunPdfTests extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Run PDF Tests';

    protected static ?string $navigationGroup = 'PDF Tests';

    protected static ?string $title = 'PDF Flow Tests';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.run-pdf-tests';
}
