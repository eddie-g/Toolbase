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
    private const MAX_PROMPT_LENGTH = 1000;

    private const MAX_PRIORITY_SUBJECT_LENGTH = 420;

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
     * @param  array<string, string>  $vars
     */
    private static function sub(string $template, array $vars): string
    {
        $search = array_map(fn ($k) => "{{$k}}", array_keys($vars));
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

    private static function requestedCharacters(string $subject): ?string
    {
        if (preg_match(
            '/\b(?:letters?|initials?)\s+["“”\']?([A-Za-z0-9])["“”\']?\s*(?:and|&|\+)\s*["“”\']?([A-Za-z0-9])["“”\']?\b/iu',
            $subject,
            $matches
        ) === 1) {
            return mb_strtoupper((string) $matches[1].(string) $matches[2]);
        }

        $patterns = [
            '/\b(?:letters?|initials?|monograms?)\s+["“”\']?([A-Za-z0-9]{1,4})["“”\']?\b/iu',
            '/^\s*["“”\']?([A-Z0-9]{1,4})["“”\']?\s+(?:in|inside|within|at|on)\b/u',
            '/\b([A-Z0-9]{1,4})[- ](?:shaped|monogram)\b/u',
            '/^\s*["“”\']?([A-Z0-9]{1,4})["“”\']?\s+(?:logo|lettermark|wordmark|monogram)\b/u',
            '/^\s*["“”\']?([A-Z0-9]{1,4})["“”\']?\s*$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $subject, $matches) === 1) {
                return mb_strtoupper((string) $matches[1]);
            }
        }

        return null;
    }

    private static function requestsDimensionalGeometry(string $subject): bool
    {
        return preg_match(
            '/\b(?:cube|cuboid|box|prism|pyramid|polyhedron|3[\s-]?d|three[\s-]?dimensional|isometric|perspective)\b/iu',
            $subject
        ) === 1;
    }

    private static function cleanPromptWhitespace(string $prompt): string
    {
        return trim(preg_replace('/\s+/', ' ', $prompt) ?? $prompt);
    }

    private static function truncateAtBoundary(string $value, int $maxLength): string
    {
        $value = self::cleanPromptWhitespace($value);
        if ($maxLength <= 0) {
            return '';
        }
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        $slice = rtrim(mb_substr($value, 0, $maxLength));
        $sentenceBoundary = -1;
        foreach (['.', '!', '?'] as $punctuation) {
            $position = mb_strrpos($slice, $punctuation);
            if ($position !== false) {
                $sentenceBoundary = max($sentenceBoundary, $position);
            }
        }
        if ($sentenceBoundary >= (int) floor($maxLength * 0.45)) {
            return rtrim(mb_substr($slice, 0, $sentenceBoundary + 1));
        }

        $wordBoundary = mb_strrpos($slice, ' ');
        if ($wordBoundary !== false && $wordBoundary > 0) {
            return rtrim(mb_substr($slice, 0, $wordBoundary));
        }

        return $slice;
    }

    private static function sanitizeTemplateForRequestedGeometry(
        string $template,
        bool $dimensionalGeometry
    ): string {
        if (! $dimensionalGeometry) {
            return $template;
        }

        $template = str_replace(
            [
                'Design ONE single unified logo symbol',
                'one cohesive flowing form built from smooth geometric curves',
                'one cohesive flowing form with smooth geometric curves',
                'scattered or overlapping geometric blocks, ',
                'layered foreground-background tiers, perspective scenes, ',
                'perspective scenes, ',
            ],
            [
                'Design one cohesive logo composition',
                'a cohesive arrangement built with geometry appropriate to the requested subject',
                'a cohesive arrangement with geometry appropriate to the requested subject',
                'unrelated scattered fragments, ',
                '',
                '',
            ],
            $template
        );

        return self::cleanPromptWhitespace($template);
    }

    private static function sanitizeTemplateForMultipleCharacters(string $template): string
    {
        $template = str_replace(
            [
                'Design ONE single unified logo symbol',
                'Design ONE single unified logo mark',
                'Design ONE single unified icon drawn as a single elegant continuous line of even, consistent stroke weight',
                'Design ONE single unified mark built from one solid continuous silhouette',
                'one cohesive flowing form built from smooth geometric curves',
                'one cohesive flowing form with smooth geometric curves',
                'Build it from one cohesive flowing form with smooth geometric curves',
                'Build the whole mark from one clean unbroken outline',
            ],
            [
                'Design one balanced lettermark composition',
                'Design one balanced lettermark composition',
                'Design a balanced lettermark using clean lines of even, consistent stroke weight',
                'Design a balanced lettermark built from clearly separated silhouettes',
                'distinct, well-proportioned letterforms built with controlled geometric curves',
                'distinct, well-proportioned letterforms with controlled geometric curves',
                'Build it from distinct, well-proportioned letterforms with controlled geometric curves',
                'Build each character from a clean readable outline',
            ],
            $template
        );

        return self::cleanPromptWhitespace($template);
    }

    private static function fitIntentFirstPrompt(
        string $priority,
        string $optionalStyle,
        string $requiredConstraints
    ): string {
        $priority = self::cleanPromptWhitespace($priority);
        $optionalStyle = self::cleanPromptWhitespace($optionalStyle);
        $requiredConstraints = self::cleanPromptWhitespace($requiredConstraints);

        $requiredLength = mb_strlen($priority)
            + ($requiredConstraints !== '' ? 1 + mb_strlen($requiredConstraints) : 0);
        $optionalBudget = self::MAX_PROMPT_LENGTH
            - $requiredLength
            - ($optionalStyle !== '' ? 1 : 0);
        $optionalStyle = self::truncateAtBoundary($optionalStyle, $optionalBudget);

        return self::cleanPromptWhitespace(implode(' ', array_filter([
            $priority,
            $optionalStyle,
            $requiredConstraints,
        ], static fn ($part) => $part !== '')));
    }

    private static function buildIntentFirstVectorIconPrompt(
        string $subject,
        string $styleTemplate,
        string $colorDesc,
        string $bgDesc,
        string $shapeBlock,
        string $noText
    ): string {
        $subject = self::cleanPromptWhitespace($subject);
        $requestedCharacters = self::requestedCharacters($subject);
        $multipleCharacters = $requestedCharacters !== null
            && mb_strlen($requestedCharacters) > 1;
        $dimensionalGeometry = self::requestsDimensionalGeometry($subject);

        $priorityDirectives = [
            'Depict this exact subject and spatial relationship; do not replace it with a generic abstract mark.',
        ];

        if ($requestedCharacters !== null) {
            if ($multipleCharacters) {
                $characters = preg_split('//u', $requestedCharacters, -1, PREG_SPLIT_NO_EMPTY);
                $quotedCharacters = array_map(
                    static fn (string $character) => '"'.$character.'"',
                    $characters ?: []
                );
                $characterList = count($quotedCharacters) === 2
                    ? implode(' and ', $quotedCharacters)
                    : implode(', ', $quotedCharacters);
                $priorityDirectives[] = sprintf(
                    'The requested characters %s are intentional. Keep them as %d distinct, complete, clearly readable letterforms in exactly that left-to-right order; do not merge, hide, crop, or replace any character.',
                    $characterList,
                    mb_strlen($requestedCharacters)
                );
                $noText = sprintf(
                    'Icon only: the only permitted characters are exactly "%s", in that order. Do not add any other letters, words, names, taglines, labels, captions, or fake text.',
                    $requestedCharacters
                );
            } else {
                $priorityDirectives[] = sprintf(
                    'The requested character "%s" is intentional and must remain clearly readable as part of the icon.',
                    $requestedCharacters
                );
                $noText = sprintf(
                    'Icon only: "%s" is the only permitted character. Do not add any other letters, words, names, taglines, labels, captions, or fake text.',
                    $requestedCharacters
                );
            }
        }

        if ($dimensionalGeometry) {
            if (preg_match('/\bcube\b/iu', $subject) === 1) {
                $priorityDirectives[] = 'Make the cube unmistakable as a complete dimensional cube with visible outer faces and edges. Keep the requested central element visibly inside the cube; do not replace the cube with a loop, knot, ribbon, hexagon, or generic badge.';
            } else {
                $priorityDirectives[] = 'Preserve the requested dimensional or perspective geometry with clearly readable faces, edges, depth, and containment.';
            }
        }

        $styleTemplate = str_replace(
            [
                'Colors: __RECRAFT_COLORS__.',
                'Background: __RECRAFT_BACKGROUND__.',
                '__RECRAFT_SHAPE_BLOCK__',
            ],
            '',
            $styleTemplate
        );
        $styleTemplate = self::sanitizeTemplateForRequestedGeometry(
            self::cleanPromptWhitespace($styleTemplate),
            $dimensionalGeometry
        );
        if ($multipleCharacters) {
            $styleTemplate = self::sanitizeTemplateForMultipleCharacters($styleTemplate);
        }

        $constraintParts = [];
        if (stripos($colorDesc, 'AI picks') !== false) {
            $constraintParts[] = 'Choose a restrained palette that supports the user request.';
        } else {
            $constraintParts[] = 'Mandatory palette: use only '.$colorDesc.' for the logo artwork.';
        }
        if ($bgDesc !== '') {
            $constraintParts[] = 'Background: '.$bgDesc.'.';
        }
        if ($shapeBlock !== '') {
            $constraintParts[] = $shapeBlock;
        }
        $constraintParts[] = 'Use crisp, editable SVG paths and a centered 1:1 composition.';
        if ($noText !== '') {
            $constraintParts[] = $noText;
        }

        $requiredConstraints = implode(' ', $constraintParts);
        $priorityPrefix = 'USER REQUEST (highest priority): "';
        $prioritySuffix = '". '.implode(' ', $priorityDirectives);
        $subjectBudget = self::MAX_PROMPT_LENGTH
            - mb_strlen($priorityPrefix)
            - mb_strlen($prioritySuffix)
            - 1
            - mb_strlen($requiredConstraints);
        $subject = self::truncateAtBoundary(
            $subject,
            min(self::MAX_PRIORITY_SUBJECT_LENGTH, max(0, $subjectBudget))
        );
        $quotedSubject = str_replace('"', "'", $subject);

        return self::fitIntentFirstPrompt(
            $priorityPrefix.$quotedSubject.$prioritySuffix,
            $styleTemplate,
            $requiredConstraints
        );
    }

    public static function defaultColors(string $style): string
    {
        $tpl = self::templates('raster'); // Both configs have same default_colors

        return $tpl['default_colors'][$style] ?? $tpl['default_colors']['minimalist'] ?? 'navy blue, gold';
    }

    /**
     * Build a Recraft logo prompt (≤ 1000 chars).
     *
     * @param  string  $style  'fantasy'|'future'|'retro'|'minimalist'|'greetingcard'|'professional'
     * @param  string  $logoDetail  'min'|'medium'|'max'
     * @param  string|null  $logoShape  'circle'|'hexagon'|'triangle'|'square'|'pentagon'|'none'|null
     * @param  string  $subject  User prompt (the image description)
     * @param  string  $brandUpper  Brand name, already uppercased
     * @param  string  $colorDesc  Comma-separated colour string
     * @param  string  $bgDesc  Background hex or 'transparent'
     */
    public static function build(
        string $style,
        string $logoDetail,
        ?string $logoShape,
        bool $iconOnly,
        bool $textOnly,
        string $subject,
        string $brandUpper,
        string $colorDesc,
        string $bgDesc,
        string $outputFormat = 'raster',
        ?string $fontStyle = null,
    ): string {
        $format = $outputFormat === 'vector' ? 'vector' : 'raster';
        $tpl = self::templates($format);

        // Recraft vector path supports either logo OR text, never both in one generation.
        if ($format === 'vector' && ! $iconOnly && ! $textOnly) {
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
        if (! empty($logoShape) && $logoShape !== 'none') {
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
            $modeKey = match ($logoDetail) {
                'max' => $mode.'_max',
                'medium' => $mode.'_medium',
                default => $mode,
            };
        }

        $fallbackStyle = $mode === 'text_only' ? 'modern_sans' : 'minimal_geometric';
        $template = $tpl[$modeKey][$style]
            ?? $tpl[$modeKey][$fallbackStyle]
            ?? $tpl[$mode][$style]
            ?? $tpl[$mode][$fallbackStyle]
            ?? '';

        if ($format === 'vector' && $mode === 'icon_only' && trim($subject) !== '') {
            $styleTemplate = self::sub($template, [
                'brand' => $brandUpper,
                'subject' => $subjectValue,
                'colors' => '__RECRAFT_COLORS__',
                'bg' => '__RECRAFT_BACKGROUND__',
                'shape_block' => '__RECRAFT_SHAPE_BLOCK__',
                'no_text' => '',
            ]);

            return self::buildIntentFirstVectorIconPrompt(
                subject: $subjectValue,
                styleTemplate: $styleTemplate,
                colorDesc: $colorDesc,
                bgDesc: $bgDesc,
                shapeBlock: $shapeBlock,
                noText: $noText,
            );
        }

        $prompt = self::sub($template, [
            'brand' => $brandUpper,
            'subject' => $subjectValue,
            'colors' => $colorDesc,
            'bg' => $bgDesc,
            'shape_block' => $shapeBlock,
            'no_text' => ($format === 'vector' && $mode === 'icon_only') ? '' : $noText,
        ]);

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
            $prompt .= ' '.$detailDirective;
        }
        if ($format === 'vector' && $mode === 'icon_only' && $noText !== '') {
            $prompt = $noText.' '.$prompt;
        }

        // Recraft API hard limit: 1000 characters
        return self::truncateAtBoundary($prompt, self::MAX_PROMPT_LENGTH);
    }
}
