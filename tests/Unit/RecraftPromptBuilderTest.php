<?php

namespace Tests\Unit;

use App\Services\RecraftPromptBuilder;
use Tests\TestCase;

class RecraftPromptBuilderTest extends TestCase
{
    public function test_explicit_letter_inside_cube_outranks_abstract_style_boilerplate(): void
    {
        $prompt = $this->buildVectorIconPrompt(
            subject: 'make a N in the center of a cube',
            colors: '#1E3A5F, #000000, #E2621D',
        );

        $this->assertLessThanOrEqual(1000, mb_strlen($prompt));
        $this->assertStringContainsString(
            'USER REQUEST (highest priority): "N in the center of a cube".',
            $prompt,
        );
        $this->assertStringContainsString(
            'The requested character "N" is intentional and must remain clearly readable',
            $prompt,
        );
        $this->assertStringContainsString(
            'Make the cube unmistakable as a complete dimensional cube with visible outer faces and edges.',
            $prompt,
        );
        $this->assertStringContainsString(
            'Keep the requested central element visibly inside the cube',
            $prompt,
        );
        $this->assertStringContainsString(
            'Mandatory palette: use only #1E3A5F, #000000, #E2621D for the logo artwork.',
            $prompt,
        );
        $this->assertStringContainsString('Background: #FFFFFF.', $prompt);
        $this->assertStringContainsString(
            'Icon only: "N" is the only permitted character.',
            $prompt,
        );

        $this->assertStringNotContainsString('letters, numbers, initials', $prompt);
        $this->assertStringNotContainsString('perspective scenes', $prompt);
        $this->assertStringNotContainsString('overlapping geometric blocks', $prompt);
        $this->assertStringNotContainsString('cohesive flowing form', $prompt);
        $this->assertFalse(str_ends_with($prompt, '#'));
    }

    public function test_non_character_icon_request_keeps_the_no_text_constraint(): void
    {
        $prompt = $this->buildVectorIconPrompt(
            subject: 'mountain and river emblem',
            colors: 'forest green, navy blue',
        );

        $this->assertLessThanOrEqual(1000, mb_strlen($prompt));
        $this->assertStringContainsString(
            'Do not create company names, brand names, taglines, labels, signs, captions, letters, numbers, initials',
            $prompt,
        );
        $this->assertStringNotContainsString('is the only permitted character', $prompt);
    }

    public function test_plural_letter_request_keeps_each_requested_character_readable(): void
    {
        $prompt = $this->buildVectorIconPrompt(
            subject: 'make the letters NK',
            colors: '#1E3A5F, #000000, #E2621D',
        );

        $this->assertLessThanOrEqual(1000, mb_strlen($prompt));
        $this->assertStringContainsString(
            'USER REQUEST (highest priority): "letters NK".',
            $prompt,
        );
        $this->assertStringContainsString(
            'The requested characters "N" and "K" are intentional.',
            $prompt,
        );
        $this->assertStringContainsString(
            'Keep them as 2 distinct, complete, clearly readable letterforms in exactly that left-to-right order',
            $prompt,
        );
        $this->assertStringContainsString(
            'Icon only: the only permitted characters are exactly "NK", in that order.',
            $prompt,
        );
        $this->assertStringContainsString(
            'Mandatory palette: use only #1E3A5F, #000000, #E2621D for the logo artwork.',
            $prompt,
        );
        $this->assertStringNotContainsString('Do not create company names', $prompt);
        $this->assertStringNotContainsString('ONE single unified logo symbol', $prompt);
        $this->assertStringNotContainsString('one cohesive flowing form', $prompt);
    }

    public function test_required_constraints_are_kept_intact_when_the_prompt_is_long(): void
    {
        $subject = 'N in the center of a cube '
            .str_repeat('with carefully balanced architectural geometry and negative space ', 20);

        $prompt = $this->buildVectorIconPrompt(
            subject: $subject,
            colors: '#1E3A5F, #000000, #E2621D',
        );

        $this->assertLessThanOrEqual(1000, mb_strlen($prompt));
        $this->assertStringContainsString(
            'Mandatory palette: use only #1E3A5F, #000000, #E2621D for the logo artwork.',
            $prompt,
        );
        $this->assertStringContainsString('Background: #FFFFFF.', $prompt);
        $this->assertStringContainsString(
            'Icon only: "N" is the only permitted character.',
            $prompt,
        );
        $this->assertStringEndsWith(
            'Do not add any other letters, words, names, taglines, labels, captions, or fake text.',
            $prompt,
        );
    }

    private function buildVectorIconPrompt(string $subject, string $colors): string
    {
        return RecraftPromptBuilder::build(
            style: 'abstract',
            logoDetail: 'max',
            logoShape: 'none',
            iconOnly: true,
            textOnly: false,
            subject: $subject,
            brandUpper: '',
            colorDesc: $colors,
            bgDesc: '#FFFFFF',
            outputFormat: 'vector',
        );
    }
}
