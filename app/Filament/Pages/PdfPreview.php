<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class PdfPreview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-eye';

    protected static ?string $navigationLabel = 'PDF Preview';

    protected static ?string $navigationGroup = 'PDF Tests';

    protected static ?string $title = 'PDF Preview';

    protected static ?int $navigationSort = 4;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.pdf-preview';
}
