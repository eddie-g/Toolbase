<?php

namespace App\UserPortal\Pages;

use Filament\Pages\Page;

class Domains extends Page
{
    protected static ?string $title = 'Domains';

    protected static ?string $navigationLabel = 'Domains';

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'user-portal.pages.domains';
}
