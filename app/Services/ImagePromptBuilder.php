<?php

namespace App\Services;

/**
 * Builds general-purpose "Image" mode prompts for the Logo Generator.
 *
 * Unlike the logo prompt builders, this produces prompts that generate a
 * normal image/illustration/photo (no logo, emblem, icon, or typography
 * language). Edit  config/image_prompts.json  to change wording.
 *
 * Variables: {style_direction} {subject} {size} {detail} {colors} {bg} {shape}
 */
class ImagePromptBuilder
{
    /** @var array<string, mixed>|null */
    private static ?array $templates = null;

    private static function templates(): array
    {
        if (self::$templates === null) {
            $path = config_path('image_prompts.json');
            self::$templates = json_decode(file_get_contents($path), true) ?: [];
        }
        return self::$templates;
    }

    /**
     * Build an image-mode prompt string.
     *
     * @param string      $style            Selected style id (professional, fantasy, …)
     * @param string      $subject          User description of the desired image
     * @param string|null $colorInstruction Color instruction, or null/empty for AI's choice
     * @param string      $bgInstruction    Background phrase (may be empty)
     * @param string      $imageSize        Aspect ratio: '1:1' | '16:9' | '9:16'
     * @param string      $detail           Detail level: 'min' | 'medium' | 'max'
     * @param string|null $logoShape        Optional shape container
     */
    public static function build(
        string $style,
        string $subject,
        ?string $colorInstruction,
        string $bgInstruction,
        string $imageSize = '1:1',
        string $detail = 'medium',
        ?string $logoShape = null,
    ): string {
        $tpl = self::templates();

        $subject = trim($subject) !== ''
            ? trim($subject)
            : ($tpl['default_subject'] ?? 'an evocative scene');

        $styleDirection = $tpl['style_direction'][$style]
            ?? $tpl['style_direction']['professional']
            ?? 'clean, modern, high-quality';

        $sizeDirection = $tpl['size_direction'][$imageSize]
            ?? $tpl['size_direction']['1:1']
            ?? 'Square 1:1 composition';

        $detailKey = in_array($detail, ['min', 'medium', 'max'], true) ? $detail : 'medium';
        $transparentCutout = str_contains(strtolower($bgInstruction), 'transparent')
            || str_contains(strtolower($bgInstruction), 'no-background');
        $detailGroup = $transparentCutout
            ? ($tpl['transparent_detail_direction'] ?? [])
            : ($tpl['detail_direction'] ?? []);
        $detailDirection = $detailGroup[$style][$detailKey]
            ?? $detailGroup['default'][$detailKey]
            ?? $tpl['detail_direction'][$style][$detailKey]
            ?? $tpl['detail_direction']['default'][$detailKey]
            ?? '';

        $colors = ($colorInstruction !== null && trim($colorInstruction) !== '')
            ? rtrim(trim($colorInstruction), '.') . '. '
            : '';

        $bg = trim($bgInstruction) !== ''
            ? rtrim(trim($bgInstruction), '.') . '. '
            : '';

        $shape = '';
        if (!empty($logoShape) && $logoShape !== 'none') {
            $shapeName = strtolower($logoShape);
            $shape = "Hard shape constraint: the entire image must be fully enclosed inside one clean {$shapeName} container/badge. The {$shapeName} is the outer boundary of the artwork, not just a background object. Nothing may extend outside the {$shapeName}. Keep all subjects, effects, scenery, silhouettes, and color fields clipped inside the {$shapeName}. ";
        }

        $template = $tpl['template']
            ?? 'A high-quality {style_direction} image of {subject}. {size}. {colors}{bg}';

        return str_replace(
            ['{style_direction}', '{subject}', '{size}', '{detail}', '{colors}', '{bg}', '{shape}'],
            [$styleDirection, $subject, $sizeDirection, $detailDirection, $colors, $bg, $shape],
            $template
        );
    }
}
