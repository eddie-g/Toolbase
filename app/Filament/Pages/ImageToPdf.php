<?php

namespace App\Filament\Pages;

use App\Services\ImageToEditablePdfService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Livewire\WithFileUploads;

class ImageToPdf extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-photo';
    protected static ?string $navigationLabel = 'Image to PDF';
    protected static ?string $navigationGroup = 'Admin';
    protected static ?string $title = 'Image to PDF';
    protected static ?int $navigationSort = 32;
    protected static string $view = 'filament.pages.image-to-pdf';

    public $upload = null;

    public string $pageSize = 'letter';

    public string $mode = 'reconstruct';

    public int $maxShapes = 260;

    public function convert()
    {
        $this->validate([
            'upload' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,bmp,tif,tiff', 'max:20480'],
            'pageSize' => ['required', 'string', 'in:letter,a4,legal,source'],
            'mode' => ['required', 'string', 'in:reconstruct,image-backed'],
            'maxShapes' => ['required', 'integer', 'min:0', 'max:800'],
        ]);

        try {
            $result = app(ImageToEditablePdfService::class)->convert(
                $this->upload->getRealPath(),
                $this->upload->getClientOriginalName(),
                [
                    'page_size' => $this->pageSize,
                    'mode' => $this->mode,
                    'max_shapes' => $this->maxShapes,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('Image to PDF admin conversion failed', [
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Image conversion failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        }

        $warningText = implode("\n", array_slice($result['warnings'], 0, 3));
        Notification::make()
            ->title('Editable PDF created')
            ->body(trim(sprintf(
                '%d text boxes and %d shapes were created.%s',
                $result['text_count'],
                $result['shape_count'],
                $warningText !== '' ? "\n" . $warningText : '',
            )))
            ->success()
            ->send();

        return redirect()->to(route('documents.editNew', [
            'document' => $result['document']->id,
            'pdfjs' => 1,
        ]));
    }
}
