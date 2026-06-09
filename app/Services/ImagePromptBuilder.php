<?php

namespace App\Services;

/**
 * Builds general-purpose "Image" mode prompts for the Logo Generator.
 *
 * Unlike the logo prompt builders, this produces prompts that generate a
 * normal image/illustration/photo (no logo, emblem, icon, or typography
 * language). Edit  config/image_prompts.json  to change wording.
 *
 * Variables: {style_direction} {subject} {size} {colors} {bg}
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
     */
    public static function build(
        string $style,
        string $subject,
        ?string $colorInstruction,
        string $bgInstruction,
        string $imageSize = '1:1',
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

        $colors = ($colorInstruction !== null && trim($colorInstruction) !== '')
            ? rtrim(trim($colorInstruction), '.') . '. '
            : '';

        $bg = trim($bgInstruction) !== ''
            ? rtrim(trim($bgInstruction), '.') . '. '
            : '';

        $template = $tpl['template']
            ?? 'A high-quality {style_direction} image of {subject}. {size}. {colors}{bg}';

        return str_replace(
            ['{style_direction}', '{subject}', '{size}', '{colors}', '{bg}'],
            [$styleDirection, $subject, $sizeDirection, $colors, $bg],
            $template
        );
    }
}
