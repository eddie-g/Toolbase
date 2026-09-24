<?php

namespace App\Services;

use App\Models\AiLogoRequest;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The public logo showcase: every completed request marked for it, one
 * item per showcased image, with the filters the page offers.
 *
 * Lifted out of BrowseLogosController when the showcase became a tab of the
 * Logo Lab: the generator page now needs the same data, and a controller
 * calling another controller is the wrong shape for that. The same item
 * shape serves the signed-in account's own library on the Generate tab.
 */
class LogoShowcase
{
    /** @return array<string, mixed> the view data for the showcase tab */
    public function browse(Request $request): array
    {
        $search = (string) $request->input('search', '');
        $filterStyle = (string) $request->input('style', '');
        $filterModel = (string) $request->input('model', '');

        $query = AiLogoRequest::query()
            ->where('status', 'completed')
            ->where('is_showcase', true)
            ->whereNotNull('image_urls');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('domain', 'like', '%'.$search.'%')
                    ->orWhere('original_prompt', 'like', '%'.$search.'%')
                    ->orWhere('prompt', 'like', '%'.$search.'%');
            });
        }

        if ($filterStyle !== '') {
            $query->where('style', $filterStyle);
        }

        if ($filterModel !== '') {
            $query->where('model', $filterModel);
        }

        $logos = $query->orderByDesc('created_at')->paginate(24)->withQueryString();

        $items = collect();
        foreach ($logos as $logo) {
            $showcaseIndexes = $this->showcaseIndexesFor($logo, is_array($logo->image_urls) ? $logo->image_urls : []);
            foreach ($this->usableImageUrls($logo) as $idx => $url) {
                if (! in_array($idx, $showcaseIndexes, true)) {
                    continue;
                }
                $items->push($this->item($logo, $idx, $url));
            }
        }

        $styles = AiLogoRequest::where('is_showcase', true)
            ->whereNotNull('style')->where('style', '!=', '')
            ->distinct()->orderBy('style')->pluck('style');

        $models = AiLogoRequest::where('is_showcase', true)
            ->whereNotNull('model')->where('model', '!=', '')
            ->distinct()->orderBy('model')->pluck('model');

        return [
            'items' => $items,
            'logos' => $logos,
            'styles' => $styles,
            'models' => $models,
            'showcaseCount' => $this->showcaseImageCount(),
            'search' => $search,
            'filterStyle' => $filterStyle,
            'filterModel' => $filterModel,
        ];
    }

    /**
     * A signed-in account's own logos, newest first, one item per image: the
     * library on the Logo Lab's Generate tab. The account may come from the
     * web or the admin guard; logo requests key on its id either way. Paged on its own query key so
     * it does not fight the showcase's pages.
     *
     * @return array<string, mixed> the view data for the library
     */
    public function library(Authenticatable $user, Request $request): array
    {
        $logos = AiLogoRequest::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', 'completed')
            ->whereNotNull('image_urls')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(24, ['*'], 'logos')
            ->withQueryString();

        $items = collect();
        foreach ($logos as $logo) {
            foreach ($this->usableImageUrls($logo) as $idx => $url) {
                $item = $this->item($logo, $idx, $url);
                $item['preview_url'] = route('generatedImages.preview', ['logoRequest' => $logo->id, 'index' => $idx]);
                $item['original_url'] = route('generatedImages.original', ['logoRequest' => $logo->id, 'index' => $idx]);
                $items->push($item);
            }
        }

        return [
            'libraryItems' => $items,
            'libraryLogos' => $logos,
        ];
    }

    /**
     * The image urls of a request worth showing: blanks and omitted base64
     * skipped, absolute urls reduced to their path.
     *
     * @return array<int, string> image index => url
     */
    private function usableImageUrls(AiLogoRequest $logo): array
    {
        $out = [];
        foreach ((is_array($logo->image_urls) ? $logo->image_urls : []) as $idx => $url) {
            if (! is_string($url) || $url === '' || $url === '[base64-omitted]') {
                continue;
            }
            // In the owner's trash, or deleted for good.
            if ($logo->isImageHidden((int) $idx)) {
                continue;
            }
            $parsed = parse_url($url);
            if (isset($parsed['host'], $parsed['path'])) {
                $url = $parsed['path'];
            }
            $out[(int) $idx] = $url;
        }

        return $out;
    }

    /**
     * One showcase or library item: the image plus everything "Make your
     * own" needs to set the generator exactly as this logo was made.
     *
     * @return array<string, mixed>
     */
    public function item(AiLogoRequest $logo, int $idx, string $url): array
    {
        $resultData = is_string($logo->result_data)
            ? (json_decode($logo->result_data, true) ?: [])
            : (is_array($logo->result_data) ? $logo->result_data : []);
        $imageData = $resultData['images'][$idx] ?? [];
        $imageSeed = is_array($imageData) ? ($imageData['seed'] ?? null) : null;

        // The style column carries the pro flag as a suffix
        // ("fantasy_pro"); the style id the generator takes is in
        // result_data, or the column without the suffix.
        $storedStyle = (string) ($logo->style ?? '');
        $pro = str_ends_with($storedStyle, '_pro');
        $styleId = (string) ($resultData['style'] ?? preg_replace('/_pro$/', '', strtolower($storedStyle)));
        $outputFormat = (string) ($logo->output_format ?: (str_contains((string) $logo->model, 'vector') ? 'vector' : 'raster'));

        return [
            'logo_id' => $logo->id,
            'image_index' => $idx,
            'url' => $url,
            'model' => $logo->model ?? 'unknown',
            'model_name' => self::codeName($logo->model),
            'generator_model' => self::generatorModel($resultData['image_model'] ?? $logo->model),
            'style' => $logo->style,
            'style_id' => $styleId,
            'pro' => $pro,
            'output_format' => $outputFormat,
            // The words the maker typed, not the composed prompt the
            // generator builds around them on its own.
            'prompt' => (string) ($logo->original_prompt ?: ''),
            'domain' => $logo->domain,
            'seed_number' => $imageSeed ?? $logo->seed_number,
            'width' => $logo->width,
            'height' => $logo->height,
            'response_time_ms' => $logo->response_time_ms,
            'bg_color' => $resultData['bg_color'] ?? null,
            'image_model' => $resultData['image_model'] ?? null,
            'style_raw' => $resultData['style'] ?? null,
            'icon_only' => (bool) ($resultData['icon_only'] ?? false),
            'logo_shape' => $resultData['logo_shape'] ?? null,
            'logo_detail' => $resultData['logo_detail'] ?? null,
            'cost' => $resultData['cost'] ?? null,
            'created_at' => $logo->created_at?->format('M j, Y'),
            'created_diff' => $logo->created_at?->diffForHumans(),
        ];
    }

    /**
     * The name Netkit gives a model on its own pages: Luna, Ray or Cosmo.
     * The provider behind each is not something the page talks about.
     */
    public static function codeName(?string $model): string
    {
        $model = strtolower((string) $model);

        return match (true) {
            str_contains($model, 'recraft') => 'Ray',
            str_contains($model, 'flux'), str_contains($model, 'nano-banana') => 'Luna',
            default => 'Cosmo',
        };
    }

    /** The generator's own key for a stored model: what selectModel() takes. */
    public static function generatorModel(?string $model): string
    {
        $model = strtolower((string) $model);

        return match (true) {
            str_contains($model, 'recraft') => 'recraft',
            str_contains($model, 'flux'), str_contains($model, 'nano-banana') => 'flux',
            default => 'dalle',
        };
    }

    public static function modelLabel(string $model): string
    {
        return match (true) {
            str_contains($model, 'flux-pro') => 'Flux Pro',
            str_contains($model, 'flux/schnell'),
            str_contains($model, 'flux-schnell') => 'Flux Schnell',
            str_contains($model, 'flux') => 'Flux',
            str_contains($model, 'recraft') => 'Recraft',
            str_contains($model, 'gpt-image') => 'GPT Image',
            str_contains($model, 'dall-e') => 'DALL·E',
            default => $model,
        };
    }

    /**
     * New showcase records store exact selected image indexes. Legacy records
     * only have is_showcase=true, so keep showing all their images.
     *
     * @return list<int>
     */
    private function showcaseIndexesFor(AiLogoRequest $logo, array $urls): array
    {
        $indexes = $logo->showcase_image_indexes;

        if (! is_array($indexes) || $indexes === []) {
            return array_keys($urls);
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $indexes),
            fn (int $index): bool => array_key_exists($index, $urls)
        )));
    }

    private function showcaseImageCount(): int
    {
        return AiLogoRequest::query()
            ->where('status', 'completed')
            ->where('is_showcase', true)
            ->whereNotNull('image_urls')
            ->get(['image_urls', 'showcase_image_indexes'])
            ->sum(function (AiLogoRequest $logo): int {
                $urls = is_array($logo->image_urls) ? $logo->image_urls : [];

                return count($this->showcaseIndexesFor($logo, $urls));
            });
    }
}
