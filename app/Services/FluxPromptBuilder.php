<?php

namespace App\Services;

/**
 * Builds Flux logo prompts from a JSON template config.
 *
 * Edit  config/flux_prompts.json  to change wording without touching PHP.
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
    private static ?array $templates = null;

    /** Load (and cache) the JSON template file. */
    private static function templates(): array
    {
        if (self::$templates === null) {
            $path = config_path('flux_prompts.json');
            self::$templates = json_decode(file_get_contents($path), true);
        }
        return self::$templates;
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
     * Return the default colour string for a style (from config/flux_prompts.json).
     */
    public static function defaultColors(string $style): string
    {
        $tpl = self::templates();
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
    ): string {
        $detail ??= 'max';
        $tpl    = self::templates();
        
        // Handle text-only mode: generate wordmark/text only, no icon
        if ($textOnly) {
            $textOnlyPrompt = "A clean professional wordmark logo design. The text \"{$brandUpper}\" is rendered in a custom typography with elegant letterforms. ";
            
            // Add color instruction
            if ($colorInstruction) {
                $textOnlyPrompt .= " {$colorInstruction} ";
            } else {
                $textOnlyPrompt .= " " . self::defaultColors($style) . " ";
            }
            
            // Add background
            $textOnlyPrompt .= " The design is {$bgInstruction}.";
            
            // Add shape constraint for text-only if specified
            if (!empty($logoShape) && $logoShape !== 'none') {
                $shapeTemplate = $tpl['shape_block'] ?? '';
                $shapeBlock = str_replace(
                    ['{shape}', '{Shape}', '{SHAPE}'],
                    [strtolower($logoShape), ucfirst(strtolower($logoShape)), strtoupper($logoShape)],
                    $shapeTemplate
                );
                $textOnlyPrompt .= " {$shapeBlock}";
            }
            
            // Add quality suffix
            $qualityKey = $outputFormat === 'vector' ? 'quality_vector' : 'quality';
            $qualitySuffix = $tpl[$qualityKey][$detail] ?? $tpl[$qualityKey]['max'] ?? '';
            if ($qualitySuffix !== '') {
                $textOnlyPrompt = rtrim($textOnlyPrompt, '. ') . '.' . $qualitySuffix;
            }
            
            return $textOnlyPrompt;
        }
        
        $mode   = $iconOnly ? 'icon_only' : 'with_brand';
        $format = $outputFormat === 'vector' ? 'vector' : 'raster';

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

        // Pick the prompt template by output format, then fall back to legacy flat keys.
        $promptTemplate = $tpl[$format][$mode][$style]
            ?? $tpl[$format][$mode]['professional']
            ?? $tpl[$mode][$style]
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
