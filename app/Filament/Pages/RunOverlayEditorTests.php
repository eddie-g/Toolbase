<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class RunOverlayEditorTests extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationLabel = 'Run Overlay Tests';

    protected static ?string $navigationGroup = 'Admin';

    protected static ?string $title = 'Overlay Editor Tests';

    protected static ?int $navigationSort = 31;

    protected static string $view = 'filament.pages.run-overlay-editor-tests';
}