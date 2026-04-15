<?php

namespace App\Filament\Pages;

use App\Models\Document;
use Filament\Pages\Page;

class PdfReconstruction2 extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'PDF Reconstruction 2';

    protected static ?string $navigationGroup = 'PDF Tests';

    protected static ?string $title = 'PDF Reconstruction 2';

    protected static ?string $slug = 'pdf-reconstruction-2';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.pdf-reconstruction-2';

    public function getViewData(): array
    {
        $documents = Document::with('user')
            ->orderBy('id', 'desc')
            ->get(['id', 'user_id', 'original_name', 'created_at']);

        return ['documents' => $documents];
    }
}
