<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentRemoval;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneGuestDocuments extends Command
{
    protected $signature = 'documents:prune-guests
        {--dry-run : Count what would be removed without removing anything}
        {--limit=500 : Documents per run}';

    protected $description = 'Remove documents of visitors without an account once nobody can open them any more';

    public function handle(DocumentRemoval $removal): int
    {
        $days = max(1, (int) config('pdf_editor.guests.lifetime_days', 7));
        $cutoff = now()->subDays($days);

        // No owner, older than the guest lifetime, and no visitor seen within
        // it who may still open it. Regression fixtures are kept: the PDF
        // test tooling creates them without an owner on purpose.
        $query = Document::withTrashed()
            ->whereNull('user_id')
            ->whereNull('admin_id')
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNull('mode')->orWhere('mode', '!=', 'regression'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('guest_documents')
                ->whereColumn('guest_documents.document_id', 'documents.id')
                ->where('guest_documents.last_seen_at', '>=', $cutoff));

        $total = (clone $query)->count();
        $this->info("{$total} guest documents are past their {$days} days and can no longer be opened by anyone.");
        if ($this->option('dry-run') || $total === 0) {
            return self::SUCCESS;
        }

        $removed = 0;
        $freed = 0;
        (clone $query)->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get()
            ->each(function (Document $document) use ($removal, &$removed, &$freed) {
                $freed += $removal->purge($document);
                $removed++;
            });
        DB::table('guest_documents')->where('last_seen_at', '<', $cutoff)->delete();

        $this->info(sprintf('Removed %d documents, %.1f MB of files.%s', $removed, $freed / 1048576, $removed < $total ? ' The rest goes with the next run.' : ''));

        return self::SUCCESS;
    }
}
