<?php

namespace App\Services;

/**
 * Builds Recraft logo prompts from JSON template configs.
 *
 * Edit  config/recraft_raster_prompts.json  or  config/recraft_vector_prompts.json  to change wording without touching PHP.
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
    private static ?array $rasterTemplates = null;
    
    /** @var array<string, mixed>|null */
    private static ?array $vectorTemplates = null;

    private static function templates(string $format = 'raster'): array
    {
        if ($format === 'vector') {
            if (self::$vectorTemplates === null) {
                $path = config_path('recraft_vector_prompts.json');
                self::$vectorTemplates = json_decode(file_get_contents($path), true);
            }
            return self::$vectorTemplates;
        } else {
            if (self::$rasterTemplates === null) {
                $path = config_path('recraft_raster_prompts.json');
                self::$rasterTemplates = json_decode(file_get_contents($path), true);
            }
            return self::$rasterTemplates;
        }
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

    private static function normalizeSubject(string $subject): string
    {
        $subject = trim($subject);
        $subject = preg_replace('/\s+/', ' ', $subject) ?? $subject;
        $subject = preg_replace('/^(?:generate|create|make|design|draw)\s+(?:a\s+|an\s+|the\s+)?/i', '', $subject) ?? $subject;
        $subject = preg_replace('/^(?:[\w-]+\s+){0,6}logo\s+(?:for|of|featuring|with)\s+/i', '', $subject) ?? $subject;
        $subject = preg_replace('/^(?:a\s+|an\s+|the\s+)?(?:minimal\s+|geometric\s+|vector\s+|brand\s+|company\s+|business\s+|commercial\s+)*logo\s+(?:for|of|featuring)\s+/i', '', $subject) ?? $subject;

        return rtrim($subject, " \t\n\r\0\x0B.");
    }

    public static function defaultColors(string $style): string
    {
        $tpl = self::templates('raster'); // Both configs have same default_colors
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
        ?string $fontStyle = null,
    ): string {
        $format = $outputFormat === 'vector' ? 'vector' : 'raster';
        $tpl = self::templates($format);

        // Recraft vector path supports either logo OR text, never both in one generation.
        if ($format === 'vector' && !$iconOnly && !$textOnly) {
            throw new \InvalidArgumentException('Vector generation requires icon-only or text-only mode.');
        }

        $mode = $iconOnly ? 'icon_only' : 'with_brand';
        if ($textOnly) {
            $mode = 'text_only';
        }

        $subjectValue = trim($subject) !== ''
            ? self::normalizeSubject($subject)
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

        // Handle "AI Picks" color option
        if (stripos($colorDesc, 'AI Picks') !== false) {
            $colorDesc = 'AI picks best matching colors';
        }

        if ($format === 'vector') {
            $modeKey = $mode;
        } else {
            $modeKey = match($logoDetail) {
                'max'    => $mode . '_max',
                'medium' => $mode . '_medium',
                default  => $mode,
            };
        }

        $fallbackStyle = $mode === 'text_only' ? 'modern_sans' : 'minimal_geometric';
        $template = $tpl[$modeKey][$style]
            ?? $tpl[$modeKey][$fallbackStyle]
            ?? $tpl[$mode][$style]
            ?? $tpl[$mode][$fallbackStyle]
            ?? '';

        $prompt = self::sub($template, [
            'brand'       => $brandUpper,
            'subject'     => $subjectValue,
            'colors'      => $colorDesc,
            'bg'          => $bgDesc,
            'shape_block' => $shapeBlock,
            'no_text'     => ($format === 'vector' && $mode === 'icon_only') ? '' : $noText,
        ]);

        if ($format === 'vector' && $mode === 'icon_only' && $noText !== '') {
            $prompt = $noText . ' ' . $prompt;
        }

        // Clean up empty background directives
        $prompt = preg_replace('/Background:\s*\./', '', $prompt);
        $prompt = preg_replace('/\s+/', ' ', $prompt); // Normalize whitespace
        $prompt = trim($prompt);

        $detailDirective = match ($logoDetail) {
            'min' => 'Detail level: low. This overrides ornate, epic, cinematic, or high-detail wording. Keep it flat 2D and logo/icon friendly with simple shapes, bold readable silhouette, clean solid fills, minimal internal detail, minimal paths, no painterly texture, no cinematic lighting, no photorealism, no dense ornament, and no complex background.',
            'medium' => 'Detail level: medium. This overrides max-detail, epic, cinematic, or ornate wording. Use moderate detail only: simplified shapes, readable forms, controlled lighting, restrained texture, limited ornament, and clear icon/logo structure. Avoid ultra-detailed rendering, cinematic lighting, painterly complexity, dense micro-detail, and complex backgrounds.',
            default => '',
        };
        if ($detailDirective !== '') {
            $prompt = $detailDirective . ' ' . $prompt;
        }

        // Recraft API hard limit: 1000 characters
        return mb_strlen($prompt) > 1000 ? mb_substr($prompt, 0, 1000) : $prompt;
    }
}
