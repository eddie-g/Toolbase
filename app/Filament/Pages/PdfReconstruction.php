<?php

namespace App\Filament\Pages;

use App\Models\Document;
use Filament\Pages\Page;

class PdfReconstruction extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = 'PDF Reconstruction';

    protected static ?string $navigationGroup = 'PDF Tests';

    protected static ?string $title = 'PDF Reconstruction';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.pdf-reconstruction';

    public function getViewData(): array
    {
        $documents = Document::with('user')
            ->orderBy('id', 'desc')
            ->get(['id', 'user_id', 'original_name', 'created_at']);

        return ['documents' => $documents];
    }
}
