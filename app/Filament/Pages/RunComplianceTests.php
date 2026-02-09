<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class RunComplianceTests extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-play';

    protected static ?string $navigationLabel = 'Run Tests';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $title = 'Run Compliance Tests';

    protected static ?int $navigationSort = 21;

    protected static string $view = 'filament.pages.run-compliance-tests';
}