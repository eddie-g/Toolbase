<?php

namespace App\Services;

/**
 * Builds Flux logo prompts from JSON template configs.
 *
 * Edit  config/flux_raster_prompts.json  or  config/flux_vector_prompts.json  to change wording without touching PHP.
 *
 * Available template variables:
 *   {brand}       – brand name (uppercase)
 *   {concept}     – visual concept element (pre-formatted with leading space, or empty)
 *   {colors}      – full color instruction string
 *   {bg}          – background instruction string
 *   {shape_block} – shape constraint sentence, or empty string
 *   {no_text}     – text constraint (pre-formatted with leading space)
 */
class FluxPromptBuilder
{
    /** @var array<string, mixed>|null */
    private static ?array $rasterTemplates = null;
    
    /** @var array<string, mixed>|null */
    private static ?array $vectorTemplates = null;

    /** Load (and cache) the JSON template files. */
    private static function templates(string $format = 'raster'): array
    {
        if ($format === 'vector') {
            if (self::$vectorTemplates === null) {
                $path = config_path('flux_vector_prompts.json');
                self::$vectorTemplates = json_decode(file_get_contents($path), true);
            }
            return self::$vectorTemplates;
        } else {
            if (self::$rasterTemplates === null) {
                $path = config_path('flux_raster_prompts.json');
                self::$rasterTemplates = json_decode(file_get_contents($path), true);
            }
            return self::$rasterTemplates;
        }
    }

    /**
     * Substitute {variable} placeholders in a template string.
     *
     * @param string               $template
     * @param array<string,string> $vars
     */
    private static function sub(string $template, array $vars): string
    {
        $search  = array_map(fn($k) => "{{$k}}", array_keys($vars));
        $replace = array_values($vars);
        return str_replace($search, $replace, $template);
    }

    /**
     * Return the default colour string for a style (from config/flux_*_prompts.json).
     * Note: Default colors are identical across raster and vector configs.
     */
    public static function defaultColors(string $style): string
    {
        $tpl = self::templates('raster'); // Both configs have same default_colors
        return $tpl['default_colors'][$style] ?? $tpl['default_colors']['professional'] ?? 'navy blue and gold color palette.';
    }

    /**
     * Build a Flux logo prompt string.
     *
     * @param string      $style            'professional'|'fantasy'|'future'|'retro'|'chrome'|'8bit'|'dotmatrix'|'lego'|'minimalist'
     * @param bool        $iconOnly         Icon-only (no brand text) when true
     * @param string      $concept          Pre-formatted visual concept element (leading space included), or empty string
     * @param string|null $colorInstruction Full color instruction string, or null to use style default
     * @param string      $bgInstruction    Background instruction string (e.g. "isolated on a solid white background")
     * @param string      $brandUpper       Brand name, already uppercased
     * @param string      $outputFormat     'raster'|'vector'
     * @param string|null $logoShape        'circle'|'hexagon'|'triangle'|'square'|'pentagon'|'none'|null
     */
    public static function build(
        string  $style,
        bool    $iconOnly,
        bool    $textOnly,
        string  $concept,
        ?string $colorInstruction,
        string  $bgInstruction,
        string  $brandUpper,
        string  $outputFormat = 'raster',
        ?string $detail = 'max',
        ?string $logoShape = null,
        ?string $fontStyle = null,
    ): string {
        $detail ??= 'max';
        $format = $outputFormat === 'vector' ? 'vector' : 'raster';
        $tpl    = self::templates($format);
        
        // Text-only mode uses JSON-driven vector templates keyed by font style.
        if ($textOnly) {
            $mode = 'text_only';
            $colorsValue = $colorInstruction ?? ($tpl['default_colors'][$style] ?? 'navy blue and gold color palette.');

            $shapeBlock = '';
            if (!empty($logoShape) && $logoShape !== 'none') {
                $shapeTemplate = $tpl['shape_block'] ?? '';
                $shapeBlock = str_replace(
                    ['{shape}', '{Shape}', '{SHAPE}'],
                    [strtolower($logoShape), ucfirst(strtolower($logoShape)), strtoupper($logoShape)],
                    $shapeTemplate
                );
            }

            $promptTemplate = $tpl[$mode][$style]
                ?? $tpl[$mode]['modern_sans']
                ?? '';

            $textOnlyPrompt = self::sub($promptTemplate, [
                'brand'       => $brandUpper,
                'concept'     => '',
                'colors'      => $colorsValue,
                'bg'          => $bgInstruction,
                'shape_block' => $shapeBlock,
                'no_text'     => '',
            ]);

            $qualityKey = $format === 'vector' ? 'quality_vector' : 'quality';
            $qualitySuffix = $tpl[$qualityKey][$detail] ?? $tpl[$qualityKey]['max'] ?? '';
            if ($qualitySuffix !== '') {
                $textOnlyPrompt = rtrim($textOnlyPrompt, '. ') . '.' . $qualitySuffix;
            }

            return $textOnlyPrompt;
        }
        
        $mode = $iconOnly ? 'icon_only' : 'with_brand';

        // Resolve concept: use provided value or fall back to the JSON default
        $conceptValue = $concept !== ''
            ? $concept
            : ($tpl['default_concept'][$mode] ?? '');

        // Resolve colors: use provided instruction or fall back to style default
        $colorsValue = $colorInstruction ?? self::defaultColors($style);

        // Resolve no_text constraint (substituting {brand} for the with_brand variant)
        $noTextTemplate = $tpl['no_text'][$mode] ?? '';
        $noTextValue    = str_replace('{brand}', $brandUpper, $noTextTemplate);

        // Build shape constraint text if a shape is specified
        $shapeBlock = '';
        if (!empty($logoShape) && $logoShape !== 'none') {
            $shapeTemplate = $tpl['shape_block'] ?? '';
            $shapeBlock = str_replace(
                ['{shape}', '{Shape}', '{SHAPE}'],
                [strtolower($logoShape), ucfirst(strtolower($logoShape)), strtoupper($logoShape)],
                $shapeTemplate
            );
        }

        // Pick the prompt template for the mode and style
        $promptTemplate = $tpl[$mode][$style]
            ?? $tpl[$mode]['professional']
            ?? '';

        $body = self::sub($promptTemplate, [
            'brand'       => $brandUpper,
            'concept'     => $conceptValue,
            'colors'      => $colorsValue,
            'bg'          => $bgInstruction,
            'shape_block' => $shapeBlock,
            'no_text'     => $noTextValue,
        ]);

        // For raster only, prepend custom colors to front-load palette guidance.
        // Vector templates already contain structured color instructions; avoid duplication.
        if ($colorInstruction !== null && $format === 'raster') {
            $body = $colorsValue . ' ' . $body;
        }

        // Append detail quality modifier (vector uses its own quality vocabulary).
        $qualityKey    = $format === 'vector' ? 'quality_vector' : 'quality';
        $qualitySuffix = $tpl[$qualityKey][$detail] ?? $tpl[$qualityKey]['max'] ?? '';
        if ($qualitySuffix !== '') {
            $body = rtrim($body, '. ') . '.' . $qualitySuffix;
        }

        return $body;
    }
}
