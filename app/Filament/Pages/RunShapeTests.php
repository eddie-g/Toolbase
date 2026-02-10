<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class RunShapeTests extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Run Shape Tests';

    protected static ?string $navigationGroup = 'Overlay Editor';

    protected static ?string $title = 'Shape Tool Tests';

    protected static ?int $navigationSort = 32;

    protected static string $view = 'filament.pages.run-shape-tests';
}
