<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deleting a document for good: the row (its state, forms, exports and guest
 * links go with it through their foreign keys) and every file made for it.
 * Used by "delete permanently", "empty trash" and documents:prune-guests.
 */
class DocumentRemoval
{
    /** @return int bytes freed */
    public function purge(Document $document): int
    {
        $freed = 0;
        foreach (array_filter([$document->path, $document->original_backup_path, $document->preview_path ?? null]) as $path) {
            if (Storage::exists($path)) {
                $freed += (int) Storage::size($path);
                Storage::delete($path);
            }
        }

        // Images and signatures placed on its pages.
        $assets = 'annotation-assets/documents/'.$document->id;
        if (Storage::disk('public')->exists($assets)) {
            foreach (Storage::disk('public')->allFiles($assets) as $file) {
                $freed += (int) Storage::disk('public')->size($file);
            }
            Storage::disk('public')->deleteDirectory($assets);
        }

        // The cached clean copy the exporter works from.
        Storage::delete('temp/clean_'.$document->id.'.pdf');

        DB::table('pdf_extractions_fitz')->where('document_id', $document->id)->delete();
        $document->forceDelete();

        return $freed;
    }
}
