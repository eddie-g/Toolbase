<?php

namespace App\Services;

/**
 * Builds structured Recraft logo prompts from a JSON template config.
 *
 * Edit  config/recraft_prompts.json  to change wording without touching PHP.
 *
 * Available template variables:
 *   {subject}   – user's image description
 *   {brand}     – brand name (uppercase)
 *   {colors}    – colour palette string
 *   {bg}        – background colour / hex
 *   {SHAPE}     – shape name in UPPERCASE  (e.g. CIRCLE)
 *   {Shape}     – shape name in Ucfirst    (e.g. Circle)
 *   {enclosure} – "Subject" (icon-only) or "Logo and text"
 */
class RecraftPromptBuilder
{
    /** @var array<string, mixed>|null */
    private static ?array $templates = null;

    /** Load (and cache) the JSON template file. */
    private static function templates(): array
    {
        if (self::$templates === null) {
            $path = config_path('recraft_prompts.json');
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
     * Pick a detail-keyed value from an array, falling back through max → default.
     *
     * @param array<string,string> $map
     * @param string               $detail  'min'|'medium'|'max'
     */
    private static function pick(array $map, string $detail): string
    {
        return $map[$detail] ?? $map['max'] ?? $map['default'] ?? '';
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return the default colour string for a style (from config/recraft_prompts.json).
     */
    public static function defaultColors(string $style): string
    {
        $tpl = self::templates();
        return $tpl['default_colors'][$style] ?? $tpl['default_colors']['minimalist'] ?? 'navy blue, gold';
    }

    /**
     * Build a Recraft logo prompt (≤ 1000 chars).
     *
     * @param string      $style       'fantasy'|'future'|'scifi'|'retro'|anything → minimalist
     * @param string      $logoDetail  'min'|'medium'|'max'
     * @param string|null $logoShape   'circle'|'hexagon'|'triangle'|'square'|'pentagon'|null
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
        string  $subject,
        string  $brandUpper,
        string  $colorDesc,
        string  $bgDesc,
    ): string {
        $tpl   = self::templates();
        $hasShape = $logoShape && $logoShape !== 'none';

        // Shared substitution map
        $vars = [
            'subject'   => $subject,
            'brand'     => $brandUpper,
            'colors'    => $colorDesc,
            'bg'        => $bgDesc,
            'SHAPE'     => $hasShape ? strtoupper($logoShape) : '',
            'Shape'     => $hasShape ? ucfirst($logoShape)    : '',
            'enclosure' => $iconOnly ? 'Subject' : 'Logo and text',
        ];

        $lines = match(true) {
            $style === 'fantasy'                      => self::fantasy($tpl, $vars, $logoDetail, $hasShape, $iconOnly),
            $style === 'future' || $style === 'scifi' => self::future($tpl, $vars, $logoDetail, $hasShape, $iconOnly),
            $style === 'retro'                        => self::retro($tpl, $vars, $logoDetail, $hasShape, $iconOnly),
            $style === 'greetingcard'                 => self::greetingcard($tpl, $vars, $logoDetail, $hasShape, $iconOnly),
            default                                   => self::minimalist($tpl, $vars, $logoDetail, $hasShape, $iconOnly, $logoShape),
        };

        $prompt = implode("\n", array_filter($lines, fn($l) => $l !== null && $l !== ''));

        // Recraft API hard limit: 1000 characters
        return mb_strlen($prompt) > 1000 ? mb_substr($prompt, 0, 1000) : $prompt;
    }

    // ── Style builders ────────────────────────────────────────────────────────

    private static function fantasy(array $tpl, array $vars, string $detail, bool $hasShape, bool $iconOnly): array
    {
        $t   = $tpl['styles']['fantasy'];
        $sub = fn(string $s) => self::sub($s, $vars);
        $lines = [];

        $lines[] = $sub(self::pick($t['style_line'], $detail));

        if ($hasShape) {
            $ws = $t['with_shape'];
            $lines[] = $sub($ws['primary']);

            $subjectMap = $iconOnly ? $ws['subject_icon'] : $ws['subject_text'];
            $lines[] = $sub(self::pick($subjectMap, $detail));

            if ($detail !== 'min') {
                $lines[] = $sub(self::pick($ws['detail'], $detail));
            }

            $bgKey = $detail === 'min' ? 'min' : 'max';
            $lines[] = $sub($ws['background'][$bgKey]);

            $colorsKey = $detail === 'max' ? 'max' : 'default';
            $lines[] = $sub($ws['colors'][$colorsKey]);

            $lines[] = $sub($iconOnly ? $ws['footer_icon'] : $ws['footer_text']);
        } else {
            $ns = $t['no_shape'];

            $subjectMap = $iconOnly ? $ns['subject_icon'] : $ns['subject_text'];
            $lines[] = $sub(self::pick($subjectMap, $detail));

            if ($detail !== 'min') {
                $lines[] = $sub(self::pick($ns['detail'], $detail));
            }

            $colorsKey = $detail === 'max' ? 'max' : 'default';
            $lines[] = $sub($ns['colors'][$colorsKey]);
            $lines[] = $sub($ns['background']);

            if ($detail !== 'min') {
                $lines[] = $sub(self::pick($ns['quality'], $detail));
            }

            $lines[] = $sub($iconOnly ? $ns['text_icon'] : $ns['text_brand']);
        }

        return $lines;
    }

    private static function future(array $tpl, array $vars, string $detail, bool $hasShape, bool $iconOnly): array
    {
        $t   = $tpl['styles']['future'];
        $sub = fn(string $s) => self::sub($s, $vars);
        $lines = [];

        $lines[] = $sub(self::pick($t['style_line'], $detail));

        if ($hasShape) {
            $lines[] = $sub($tpl['shape_block']);
        }

        $subjectMap = $iconOnly ? $t['subject_icon'] : $t['subject_text'];
        $lines[] = $sub(self::pick($subjectMap, $detail));

        $colorsKey = $detail === 'max' ? 'max' : 'default';
        $lines[] = $sub($t['colors'][$colorsKey]);
        $lines[] = $sub($t['background']);

        if ($detail !== 'min') {
            $lines[] = $sub($t['quality']);
        }

        $lines[] = $sub($iconOnly ? $t['text_icon'] : $t['text_brand']);

        return $lines;
    }

    private static function retro(array $tpl, array $vars, string $detail, bool $hasShape, bool $iconOnly): array
    {
        $t   = $tpl['styles']['retro'];
        $sub = fn(string $s) => self::sub($s, $vars);
        $lines = [];

        $lines[] = $sub(self::pick($t['style_line'], $detail));

        if ($hasShape) {
            $lines[] = $sub($tpl['shape_block']);
        }

        $subjectMap = $iconOnly ? $t['subject_icon'] : $t['subject_text'];
        $lines[] = $sub(self::pick($subjectMap, $detail));

        $colorsKey = $detail === 'max' ? 'max' : 'default';
        $lines[] = $sub($t['colors'][$colorsKey]);
        $lines[] = $sub($t['background']);

        if ($detail !== 'min') {
            $lines[] = $sub($t['quality']);
        }

        $lines[] = $sub($iconOnly ? $t['text_icon'] : $t['text_brand']);

        return $lines;
    }

    private static function greetingcard(array $tpl, array $vars, string $detail, bool $hasShape, bool $iconOnly): array
    {
        $t   = $tpl['styles']['greetingcard'];
        $sub = fn(string $s) => self::sub($s, $vars);
        $lines = [];

        $lines[] = $sub(self::pick($t['style_line'], $detail));

        if ($hasShape) {
            $lines[] = $sub($tpl['shape_block']);
        }

        $subjectMap = $iconOnly ? $t['subject_icon'] : $t['subject_text'];
        $lines[] = $sub(self::pick($subjectMap, $detail));

        $colorsKey = $detail === 'max' ? 'max' : 'default';
        $lines[] = $sub($t['colors'][$colorsKey]);
        $lines[] = $sub($t['background']);
        $lines[] = $sub(self::pick($t['quality'], $detail));
        $lines[] = $sub($iconOnly ? $t['text_icon'] : $t['text_brand']);

        return $lines;
    }

    private static function minimalist(array $tpl, array $vars, string $detail, bool $hasShape, bool $iconOnly, ?string $logoShape): array
    {
        $t   = $tpl['styles']['minimalist'];
        $sub = fn(string $s) => self::sub($s, $vars);
        $lines = [];

        $lines[] = $sub(self::pick($t['style_line'], $detail));

        if ($hasShape) {
            $lines[] = $sub($tpl['shape_block']);
        }

        if ($iconOnly) {
            $lines[] = $sub(self::pick($t['subject_icon'], $detail));
        } else {
            $lines[] = $sub($t['subject_text']);
        }

        // Shape rendering constraints
        if ($hasShape) {
            $lines[] = $sub($t['shape_quality']);
        } elseif ($detail !== 'min') {
            $lines[] = $sub(self::pick($t['quality'], $detail));
        }

        $lines[] = $sub($hasShape ? $t['colors_shaped'] : $t['colors']);
        $lines[] = $sub($t['background']);
        $lines[] = $sub($hasShape ? $t['comp_shaped'] : $t['comp']);

        if ($hasShape || $detail !== 'min') {
            $lines[] = $sub($t['flat_fills']);
        }

        $lines[] = $sub($iconOnly ? $t['text_icon'] : $t['text_brand']);

        return $lines;
    }
}
