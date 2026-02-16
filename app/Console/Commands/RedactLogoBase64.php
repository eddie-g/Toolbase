<?php

namespace App\Console\Commands;

use App\Models\AiLogoRequest;
use Illuminate\Console\Command;

class RedactLogoBase64 extends Command
{
    protected $signature = 'logos:redact-base64 {--dry-run : Show what would be redacted without modifying}';

    protected $description = 'Redact base64 data URIs from logo image_urls older than 24 hours';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE — no records will be modified');
        }

        // Only process requests older than 24 hours so fresh logos remain usable
        $cutoff = now()->subHours(24);

        $query = AiLogoRequest::where('created_at', '<', $cutoff)
            ->whereNotNull('image_urls');

        $total = 0;
        $redacted = 0;

        $query->chunkById(200, function ($requests) use ($dryRun, &$total, &$redacted) {
            foreach ($requests as $request) {
                $total++;
                $urls = (array) $request->image_urls;
                $changed = false;

                foreach ($urls as $i => $url) {
                    if (is_string($url) && str_starts_with($url, 'data:')) {
                        $urls[$i] = '[base64-omitted]';
                        $changed = true;
                    }
                }

                if ($changed) {
                    $redacted++;
                    if ($dryRun) {
                        $this->line("  Would redact logo request #{$request->id}");
                    } else {
                        $request->update(['image_urls' => $urls]);
                    }
                }
            }
        });

        $this->info("Scanned {$total} logo requests older than 24h. Redacted: {$redacted}.");

        return self::SUCCESS;
    }
}
