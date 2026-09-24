<?php

namespace App\Console\Commands;

use App\Models\AiLogoRequest;
use App\Services\GeneratedImageTrash;
use Illuminate\Console\Command;

class PurgeTrashedLogoImages extends Command
{
    protected $signature = 'logos:purge-trash';

    protected $description = 'Permanently delete generated images that have been in the trash for ' . AiLogoRequest::TRASH_DAYS . ' days';

    public function handle(GeneratedImageTrash $trash): int
    {
        $count = $trash->purgeExpired();
        $this->info("Permanently deleted {$count} trashed image(s).");

        return self::SUCCESS;
    }
}
