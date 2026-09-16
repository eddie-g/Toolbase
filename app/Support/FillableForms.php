<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The catalogue of official fillable forms in config/fillable_forms.php.
 */
class FillableForms
{
    /** Every form keyed by slug, with the slug copied into each entry. */
    public static function all(): Collection
    {
        return collect(config('fillable_forms.forms', []))
            ->map(fn (array $form, string $slug) => self::hydrate($slug, $form));
    }

    public static function find(string $slug): ?array
    {
        $form = config('fillable_forms.forms.' . $slug);

        return is_array($form) ? self::hydrate($slug, $form) : null;
    }

    /** Absolute path of the form's PDF, or null when it is missing on disk. */
    public static function filePath(array $form): ?string
    {
        $file = (string) ($form['file'] ?? '');
        if ($file === '' || str_contains($file, '..') || str_contains($file, '/')) {
            return null;
        }

        $path = resource_path('forms/' . $file);

        return is_file($path) ? $path : null;
    }

    private static function hydrate(string $slug, array $form): array
    {
        $form['slug'] = $slug;
        $form['previews'] = array_values(array_filter((array) ($form['previews'] ?? [])));
        $form['preview'] = $form['previews'][0] ?? null;
        $form['document_name'] = (string) ($form['document_name'] ?? ($form['title'] . '.pdf'));

        return $form;
    }
}
