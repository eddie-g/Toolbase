<?php

namespace App\Console\Commands;

use App\Jobs\ImportCategoryWordsJob;
use Illuminate\Console\Command;

class ImportCategoryWords extends Command
{
    protected $signature = 'domains:import-words
                            {category? : Category slug (horror, fantasy, scifi, tech, mystery, thriller, romance, adventure). Omit to import all.}
                            {--sync : Run synchronously instead of dispatching to the queue}';

    protected $description = 'Import fastText word scores from JSON files into the dictionary table.';

    private const CATEGORIES = [
        'horror', 'fantasy', 'scifi', 'tech', 'romance',
    ];

    public function handle(): int
    {
        $category = $this->argument('category');

        $targets = $category
            ? [$category]
            : self::CATEGORIES;

        foreach ($targets as $cat) {
            $path = base_path("python/domain-search/fasttext_{$cat}.json");

            if (!file_exists($path)) {
                $this->warn("  [skip] {$cat} — file not found: {$path}");
                continue;
            }

            if ($this->option('sync')) {
                $this->info("  [sync] Importing {$cat}…");
                (new ImportCategoryWordsJob($cat))->handle();
                $this->info("  [done] {$cat}");
            } else {
                ImportCategoryWordsJob::dispatch($cat)->onQueue('default');
                $this->info("  [queued] {$cat}");
            }
        }

        return self::SUCCESS;
    }
}
