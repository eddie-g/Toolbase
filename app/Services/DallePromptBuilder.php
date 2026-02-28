<?php

namespace App\Services;

class DallePromptBuilder
{
    /** @var array<string, mixed>|null */
    private static ?array $templates = null;

    private static function templates(): array
    {
        if (self::$templates === null) {
            $path = config_path('dalle_prompts.json');
            self::$templates = json_decode(file_get_contents($path), true);
        }

        return self::$templates;
    }

    /**
     * @param array<string, string> $vars
     */
    private static function sub(string $template, array $vars): string
    {
        $search = array_map(fn ($k) => "{{$k}}", array_keys($vars));
        $replace = array_values($vars);
        return str_replace($search, $replace, $template);
    }

    public static function defaultColors(string $style): string
    {
        $tpl = self::templates();
        return $tpl['default_colors'][$style] ?? $tpl['default_colors']['professional'] ?? 'navy blue and gold';
    }

    public static function build(
        string $style,
        bool $iconOnly,
        bool $textOnly,
        string $subject,
        string $brandUpper,
        string $colorList,
        string $bgInstruction,
        string $chromeBg,
        ?string $logoShape = null,
        string $detail = 'min',
    ): string {
        $tpl = self::templates();
        
        // Handle text-only mode: generate wordmark/text only, no icon
        if ($textOnly) {
            $textOnlyPrompt = "A professional wordmark logo design featuring the text \"{$brandUpper}\" in custom typography. ";
            $textOnlyPrompt .= "Colors: {$colorList}. ";
            $textOnlyPrompt .= "Background: {$bgInstruction}. ";
            
            // Add shape constraint if specified
            if (!empty($logoShape) && $logoShape !== 'none') {
                $shapeBlock = self::sub($tpl['shape_block'] ?? '', [
                    'shape' => strtolower($logoShape),
                    'Shape' => ucfirst(strtolower($logoShape)),
                    'SHAPE' => strtoupper($logoShape),
                ]);
                $textOnlyPrompt .= " {$shapeBlock}";
            }
            
            $textOnlyPrompt .= " Clean, professional, and suitable for vector conversion. No icons or symbols, text only.";
            return $textOnlyPrompt;
        }
        
        $mode = $iconOnly ? 'icon_only' : 'with_brand';

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

        // Pick the most specific template available for the requested detail level
        $modeKey = match($detail) {
            'max'    => $mode . '_max',
            'medium' => $mode . '_medium',
            default  => $mode,
        };
        $template = $tpl[$modeKey][$style]
            ?? $tpl[$modeKey]['professional']
            ?? $tpl[$mode][$style]
            ?? $tpl[$mode]['professional']
            ?? '';

        return self::sub($template, [
            'brand' => $brandUpper,
            'subject' => $subjectValue,
            'colors' => $colorList,
            'bg' => $bgInstruction,
            'chrome_bg' => $chromeBg,
            'shape_block' => $shapeBlock,
            'no_text' => $noText,
        ]);
    }
}
