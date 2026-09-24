<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Upscales made before image_meta existed: their debit's metadata names the
 * new file, which replaced one entry of a request's image_urls. Marking them
 * stops the portal from offering (and charging for) a second upscale.
 */
return new class extends Migration
{
    public function up(): void
    {
        $debits = DB::table('credit_transactions')
            ->where('service', 'image_upscale')
            ->where('type', 'debit')
            ->get(['user_id', 'amount', 'metadata', 'created_at']);

        foreach ($debits as $debit) {
            $data = json_decode((string) $debit->metadata, true) ?: [];
            $upscaledUrl = $data['upscaled_url'] ?? null;
            if (!$upscaledUrl) {
                continue;
            }

            $logo = DB::table('ai_logo_requests')
                ->where('user_id', $debit->user_id)
                ->where(fn ($q) => $q
                    ->where('image_urls', 'like', '%' . $upscaledUrl . '%')
                    ->orWhere('image_urls', 'like', '%' . str_replace('/', '\\\\/', $upscaledUrl) . '%'))
                ->first(['id', 'image_urls', 'image_meta']);
            if (!$logo) {
                continue;
            }

            $index = array_search($upscaledUrl, array_values(json_decode($logo->image_urls, true) ?: []), true);
            if ($index === false) {
                continue;
            }

            $width = $data['width'] ?? null;
            $height = $data['height'] ?? null;
            $path = str_starts_with($upscaledUrl, '/storage/') ? substr($upscaledUrl, 9) : null;
            if ((!$width || !$height) && $path && Storage::disk('public')->exists($path)) {
                [$width, $height] = @getimagesize(Storage::disk('public')->path($path)) ?: [null, null];
            }

            $meta = json_decode((string) $logo->image_meta, true) ?: [];
            $meta[(string) $index] = array_merge($meta[(string) $index] ?? [], [
                'upscaled' => [
                    'factor' => (int) ($data['upscale_factor'] ?? 2),
                    'width' => $width ? (int) $width : null,
                    'height' => $height ? (int) $height : null,
                    'original_url' => $data['original_url'] ?? null,
                    'cost' => (float) $debit->amount,
                    'at' => (string) $debit->created_at,
                ],
            ]);

            DB::table('ai_logo_requests')->where('id', $logo->id)->update(['image_meta' => json_encode($meta)]);
        }
    }

    public function down(): void
    {
        // Leaves the markers: removing them would offer a second paid upscale.
    }
};
