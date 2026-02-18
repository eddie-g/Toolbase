<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Convert absolute localhost URLs to relative paths in image_urls JSON
        $rows = DB::table('ai_logo_requests')
            ->whereNotNull('image_urls')
            ->where('image_urls', 'like', '%http://%')
            ->orWhere('image_urls', 'like', '%https://%')
            ->get(['id', 'image_urls']);

        foreach ($rows as $row) {
            $urls = json_decode($row->image_urls, true);
            if (!is_array($urls)) continue;

            $changed = false;
            foreach ($urls as &$url) {
                if (!is_string($url)) continue;
                $parsed = parse_url($url);
                if (isset($parsed['host']) && isset($parsed['path'])) {
                    $url = $parsed['path'];
                    $changed = true;
                }
            }
            unset($url);

            if ($changed) {
                DB::table('ai_logo_requests')
                    ->where('id', $row->id)
                    ->update(['image_urls' => json_encode(array_values($urls))]);
            }
        }
    }

    public function down(): void
    {
        // Cannot reverse — relative paths are the correct format
    }
};
