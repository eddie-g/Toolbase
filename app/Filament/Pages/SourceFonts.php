<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Livewire\WithFileUploads;
use Illuminate\Http\UploadedFile;

class SourceFonts extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Source Fonts';
    protected static ?string $title = 'Source Fonts (Full Glyph Coverage)';
    protected static ?string $navigationGroup = 'System';
    protected static ?int $navigationSort = 90;
    protected static string $view = 'filament.pages.source-fonts';

    /** Livewire file input (the OTF/TTF chosen by the admin). */
    public $upload = null;

    /** Optional PostScript-name override; if blank the filename stem is used. */
    public string $psnameOverride = '';

    /** Returns the absolute path to storage/app/fonts/full. */
    public static function fontDir(): string
    {
        return storage_path('app/fonts/full');
    }

    public function mount(): void
    {
        // Ensure the directory exists so the listing doesn't error on a
        // fresh install. Same path the Python writer reads.
        @mkdir(self::fontDir(), 0775, true);
    }

    public function save(): void
    {
        $this->validate([
            'upload' => ['required', 'file', 'max:20480'],
            'psnameOverride' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ]);

        /** @var UploadedFile $file */
        $file = $this->upload;
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['otf', 'ttf'], true)) {
            Notification::make()->title('Only .otf or .ttf files are accepted')->danger()->send();
            return;
        }

        $stem = trim($this->psnameOverride) !== ''
            ? trim($this->psnameOverride)
            : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        // Sanitize: keep only safe filename characters.
        $stem = preg_replace('/[^A-Za-z0-9_\-]/', '', $stem) ?: 'Font';

        $dir = self::fontDir();
        @mkdir($dir, 0775, true);
        $destination = $dir . '/' . $stem . '.' . $ext;
        $file->getRealPath()
            ? @copy($file->getRealPath(), $destination)
            : file_put_contents($destination, file_get_contents($file->getRealPath()));

        $this->upload = null;
        $this->psnameOverride = '';

        Notification::make()
            ->title('Uploaded')
            ->body(basename($destination))
            ->success()
            ->send();
    }

    public function delete(string $filename): void
    {
        // Guard against path traversal — strip any directory separators.
        $safe = basename($filename);
        $path = self::fontDir() . '/' . $safe;
        if (is_file($path)) {
            @unlink($path);
            Notification::make()->title('Removed ' . $safe)->success()->send();
        }
    }

    public function getViewData(): array
    {
        $dir = self::fontDir();
        $items = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                $full = $dir . '/' . $name;
                if (! is_file($full)) continue;
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (! in_array($ext, ['otf', 'ttf'], true)) continue;
                $items[] = [
                    'name' => $name,
                    'psname' => pathinfo($name, PATHINFO_FILENAME),
                    'size_kb' => (int) round(filesize($full) / 1024),
                    'mtime' => filemtime($full),
                ];
            }
            usort($items, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        }
        return [
            'items' => $items,
            'dir' => $dir,
        ];
    }
}
