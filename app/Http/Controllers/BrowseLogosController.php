<?php

namespace App\Http\Controllers;

use App\Models\AiLogoRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrowseLogosController extends Controller
{
    public function index(Request $request)
    {
        $search      = $request->input('search', '');
        $filterStyle = $request->input('style', '');
        $filterModel = $request->input('model', '');

        $query = AiLogoRequest::query()
            ->where('status', 'completed')
            ->where('is_showcase', true)
            ->whereNotNull('image_urls');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('domain', 'like', '%' . $search . '%')
                  ->orWhere('original_prompt', 'like', '%' . $search . '%')
                  ->orWhere('prompt', 'like', '%' . $search . '%');
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
            $urls = is_array($logo->image_urls) ? $logo->image_urls : [];
            foreach ($urls as $idx => $url) {
                if (!is_string($url) || $url === '' || $url === '[base64-omitted]') continue;

                $parsed = parse_url($url);
                if (isset($parsed['host'], $parsed['path'])) {
                    $url = $parsed['path'];
                }

                $resultData = is_string($logo->result_data)
                    ? json_decode($logo->result_data, true)
                    : (is_array($logo->result_data) ? $logo->result_data : []);

                $items->push([
                    'logo_id'          => $logo->id,
                    'image_index'      => $idx,
                    'url'              => $url,
                    'model'            => $logo->model ?? 'unknown',
                    'style'            => $logo->style,
                    'domain'           => $logo->domain,
                    'seed_number'      => $logo->seed_number,
                    'width'            => $logo->width,
                    'height'           => $logo->height,
                    'response_time_ms' => $logo->response_time_ms,
                    'bg_color'         => $resultData['bg_color'] ?? null,
                    'image_model'      => $resultData['image_model'] ?? null,
                    'style_raw'        => $resultData['style'] ?? null,
                    'icon_only'        => $resultData['icon_only'] ?? false,
                    'logo_shape'       => $resultData['logo_shape'] ?? null,
                    'logo_detail'      => $resultData['logo_detail'] ?? null,
                    'cost'             => $resultData['cost'] ?? null,
                    'created_at'       => $logo->created_at?->format('M j, Y'),
                    'created_diff'     => $logo->created_at?->diffForHumans(),
                ]);
            }
        }

        $styles = AiLogoRequest::where('is_showcase', true)
            ->whereNotNull('style')->where('style', '!=', '')
            ->distinct()->orderBy('style')->pluck('style');

        $models = AiLogoRequest::where('is_showcase', true)
            ->whereNotNull('model')->where('model', '!=', '')
            ->distinct()->orderBy('model')->pluck('model');

        $showcaseCount = AiLogoRequest::where('is_showcase', true)
            ->where('status', 'completed')->count();

        return view('browse-logos', [
            'items'         => $items,
            'logos'         => $logos,
            'styles'        => $styles,
            'models'        => $models,
            'showcaseCount' => $showcaseCount,
            'search'        => $search,
            'filterStyle'   => $filterStyle,
            'filterModel'   => $filterModel,
        ]);
    }

    public static function modelLabel(string $model): string
    {
        return match (true) {
            str_contains($model, 'flux-pro')             => 'Flux Pro',
            str_contains($model, 'flux/schnell'),
            str_contains($model, 'flux-schnell')         => 'Flux Schnell',
            str_contains($model, 'flux')                 => 'Flux',
            str_contains($model, 'recraft')              => 'Recraft',
            str_contains($model, 'gpt-image')            => 'GPT Image',
            str_contains($model, 'dall-e')               => 'DALL·E',
            default                                      => $model,
        };
    }
}
