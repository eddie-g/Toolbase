<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class AutomatedTests extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationLabel = 'Automated Tests';

    protected static ?string $navigationGroup = 'PDF Tests';

    protected static ?string $title = 'Automated Tests';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.automated-tests';

    /**
     * Suites shown in the sidebar. Only the signature suite has a runner
     * today; add an entry here as each new tool gets automated.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSuites(): array
    {
        return [
            [
                'key' => 'signature-tool',
                'label' => 'Signature tool',
                'available' => true,
            ],
            [
                'key' => 'signature-modal-improvements',
                'label' => 'Signature modal (NK_Dev_5)',
                'available' => true,
            ],
        ];
    }
}
