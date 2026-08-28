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
     * Suites shown as tabs on the page. Add an entry here as each new tool
     * gets a catalogue and a runner.
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
                'key' => 'text-tool',
                'label' => 'Text tool',
                'available' => true,
            ],
            [
                'key' => 'shapes-tool',
                'label' => 'Shapes tool',
                'available' => true,
            ],
            [
                'key' => 'draw-tool',
                'label' => 'Draw tool',
                'available' => true,
            ],
        ];
    }
}
