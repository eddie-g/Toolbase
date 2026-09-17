<?php

namespace App\Http\Controllers;

use App\Services\LogoShowcase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /browse-logos, kept for the links that already point at it: the showcase
 * is a tab of the Logo Lab now, so this sends the visitor there, filters
 * and page intact. The data itself lives in LogoShowcase.
 */
class BrowseLogosController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $query = array_filter(
            $request->only(['search', 'style', 'model', 'page']),
            fn ($value) => $value !== null && $value !== ''
        );

        return redirect()->route('domainSearch.logoGenerator', ['tab' => 'browse'] + $query, 301);
    }

    public static function modelLabel(string $model): string
    {
        return LogoShowcase::modelLabel($model);
    }
}
