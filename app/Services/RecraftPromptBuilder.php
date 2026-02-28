<?php

namespace App\Services;

/**
 * Builds Recraft logo prompts from a JSON template config.
 *
 * Edit  config/recraft_prompts.json  to change wording without touching PHP.
 *
 * Available template variables:
 *   {subject}     – user's image description
 *   {brand}       – brand name (uppercase)
 *   {colors}      – colour palette string
 *   {bg}          – background colour / hex
 *   {shape_block} – shape constraint sentence, or empty string
 *   {no_text}     – text restriction clause
 */
class RecraftPromptBuilder
{
    /** @var array<string, mixed>|null */
    private static ?array $templates = null;

    private static function templates(): array
    {
        if (self::$templates === null) {
            $path = config_path('recraft_prompts.json');
            self::$templates = json_decode(file_get_contents($path), true);
        }
        return self::$templates;
    }

    /**
     * @param array<string, string> $vars
     */
    private static function sub(string $template, array $vars): string
    {
        $search  = array_map(fn($k) => "{{$k}}", array_keys($vars));
        $replace = array_values($vars);
        return str_replace($search, $replace, $template);
    }

    public static function defaultColors(string $style): string
    {
        $tpl = self::templates();
        return $tpl['default_colors'][$style] ?? $tpl['default_colors']['minimalist'] ?? 'navy blue, gold';
    }

    /**
     * Build a Recraft logo prompt (≤ 1000 chars).
     *
     * @param string      $style       'fantasy'|'future'|'retro'|'minimalist'|'greetingcard'|'professional'
     * @param string      $logoDetail  'min'|'medium'|'max'
     * @param string|null $logoShape   'circle'|'hexagon'|'triangle'|'square'|'pentagon'|'none'|null
     * @param bool        $iconOnly
     * @param string      $subject     User prompt (the image description)
     * @param string      $brandUpper  Brand name, already uppercased
     * @param string      $colorDesc   Comma-separated colour string
     * @param string      $bgDesc      Background hex or 'transparent'
     */
    public static function build(
        string  $style,
        string  $logoDetail,
        ?string $logoShape,
        bool    $iconOnly,
        bool    $textOnly,
        string  $subject,
        string  $brandUpper,
        string  $colorDesc,
        string  $bgDesc,
        string  $outputFormat = 'raster',
    ): string {
        $tpl  = self::templates();

        $format = $outputFormat === 'vector' ? 'vector' : 'raster';
        $formatTpl = $tpl[$format] ?? [];

        // Recraft vector path supports either logo OR text, never both in one generation.
        if ($format === 'vector' && !$iconOnly && !$textOnly) {
            throw new \InvalidArgumentException('Vector generation requires icon-only or text-only mode.');
        }

        $mode = $iconOnly ? 'icon_only' : 'with_brand';
        if ($textOnly) {
            $mode = 'text_only';
        }

        $subjectValue = trim($subject) !== ''
            ? trim($subject)
            : ($tpl['default_subject'][$mode] ?? 'logo symbol');

        $shapeBlock = '';
        if (!empty($logoShape) && $logoShape !== 'none') {
            $shapeBlock = self::sub($tpl['shape_block'] ?? '', [
                'shape' => strtolower($logoShape),
                'Shape' => ucfirst(strtolower($logoShape)),
                'SHAPE' => strtoupper($logoShape),
            ]);
        }

        $noTextTemplate = $tpl['no_text'][$mode] ?? '';
        $noText = self::sub($noTextTemplate, ['brand' => $brandUpper]);

        $modeKey = match($logoDetail) {
            'max'    => $mode . '_max',
            'medium' => $mode . '_medium',
            default  => $mode,
        };

        $template = $formatTpl[$modeKey][$style]
            ?? $formatTpl[$modeKey]['minimalist']
            ?? $formatTpl[$mode][$style]
            ?? $formatTpl[$mode]['minimalist']
            ?? '';

        $prompt = self::sub($template, [
            'brand'       => $brandUpper,
            'subject'     => $subjectValue,
            'colors'      => $colorDesc,
            'bg'          => $bgDesc,
            'shape_block' => $shapeBlock,
            'no_text'     => $noText,
        ]);

        // Recraft API hard limit: 1000 characters
        return mb_strlen($prompt) > 1000 ? mb_substr($prompt, 0, 1000) : $prompt;
    }
}
