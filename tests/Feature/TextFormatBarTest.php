<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The "Text Options" side panel (NK_7).
 *
 * The panel is one Blade partial shared by both editors, so its layout and the
 * admin gating are asserted directly against the rendered markup rather than
 * through a full editor page — the editor route needs a real PDF on disk and
 * would drown these assertions in unrelated markup.
 */
class TextFormatBarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Format Bar Admin',
            'email' => 'format-bar@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function renderPanel(bool $pdfjs = true): string
    {
        $url = $pdfjs ? '/documents/1/edit-new?pdfjs=1' : '/documents/1/edit-new';
        $this->app->instance('request', Request::create($url));

        return view('documents.edit-new._format-bar')->render();
    }

    private function positionOf(string $haystack, string $needle): int
    {
        $position = strpos($haystack, $needle);
        $this->assertNotFalse($position, "Expected to find {$needle} in the panel.");

        return $position;
    }

    // ---- Subtask: only show the debug button for admins, same with the ID ----

    public function test_debug_button_and_annotation_id_are_hidden_from_visitors(): void
    {
        $html = $this->renderPanel();

        $this->assertStringNotContainsString('id="afb-debug"', $html);
        $this->assertStringNotContainsString('id="afb-annotation-id"', $html);

        // The rest of the pdf.js-only row is still there for everyone.
        $this->assertStringContainsString('id="afb-uppercase"', $html);
        $this->assertStringContainsString('id="afb-lowercase"', $html);
    }

    public function test_debug_button_and_annotation_id_are_shown_to_admins(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $html = $this->renderPanel();

        $this->assertStringContainsString('id="afb-debug"', $html);
        $this->assertStringContainsString('id="afb-annotation-id"', $html);
    }

    public function test_debug_button_stays_hidden_for_a_signed_in_non_admin_user(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $html = $this->renderPanel();

        $this->assertStringNotContainsString('id="afb-debug"', $html);
        $this->assertStringNotContainsString('id="afb-annotation-id"', $html);
    }

    // ---- Subtask: move copy and delete onto the text tools row --------------

    public function test_duplicate_and_delete_sit_on_the_text_tools_row(): void
    {
        $html = $this->renderPanel();

        $this->assertStringNotContainsString('afb-action-group', $html);

        $groupStart = $this->positionOf($html, 'afb-text-tools-group');
        $rowEnd = strpos($html, '</div>', $this->positionOf($html, 'id="afb-delete"'));

        // Uppercase, lowercase, duplicate and delete all live in the one row.
        $row = substr($html, $groupStart, $rowEnd - $groupStart);
        $this->assertSame(1, substr_count($row, '<div class="afb-control-row">'));
        foreach (['afb-uppercase', 'afb-lowercase', 'afb-copy', 'afb-delete'] as $control) {
            $this->assertStringContainsString("id=\"{$control}\"", $row);
        }
    }

    public function test_duplicate_and_delete_survive_in_the_legacy_editor(): void
    {
        $html = $this->renderPanel(pdfjs: false);

        // The case transforms are pdf.js-only, but the actions must not vanish
        // just because they were moved into that group.
        $this->assertStringNotContainsString('id="afb-uppercase"', $html);
        $this->assertStringContainsString('id="afb-copy"', $html);
        $this->assertStringContainsString('id="afb-delete"', $html);
        $this->assertStringContainsString('>Actions</span>', $html);
    }

    // ---- Subtask: make the background colour division obvious --------------

    public function test_colour_swatches_are_captioned_and_stay_on_one_row(): void
    {
        $html = $this->renderPanel();

        $this->assertStringContainsString('id="afb-text-color-caption">Text<', $html);
        $this->assertStringContainsString('id="afb-bg-color-caption">Background<', $html);
        $this->assertStringContainsString('aria-labelledby="afb-text-color-caption"', $html);
        $this->assertStringContainsString('aria-labelledby="afb-bg-color-caption"', $html);

        // Both fields belong to the same control row.
        $rowStart = $this->positionOf($html, 'afb-color-group');
        $rowEnd = strpos($html, '</div>', $this->positionOf($html, 'id="afb-bg-color-caption"'));
        $row = substr($html, $rowStart, $rowEnd - $rowStart);
        $this->assertSame(1, substr_count($row, '<div class="afb-control-row">'));
        $this->assertSame(2, substr_count($row, 'afb-color-field'));
    }

    // ---- Subtask: move the hyperlink section to the bottom -----------------

    public function test_hyperlink_group_is_the_last_group_in_the_panel(): void
    {
        $html = $this->renderPanel();

        $link = $this->positionOf($html, 'afb-link-group');

        foreach (['afb-font-group', 'afb-color-group', 'afb-style-group', 'afb-align-group', 'afb-text-tools-group'] as $group) {
            $this->assertLessThan(
                $link,
                $this->positionOf($html, $group),
                "{$group} should come before the hyperlink group."
            );
        }
    }

    public function test_hyperlink_group_is_absent_from_the_legacy_editor(): void
    {
        $this->assertStringNotContainsString('afb-link-group', $this->renderPanel(pdfjs: false));
    }

    // ---- Subtask: add a strikeout style ------------------------------------

    public function test_strikeout_joins_the_other_style_toggles(): void
    {
        $html = $this->renderPanel();

        $styleGroup = $this->positionOf($html, 'afb-style-group');
        $alignGroup = $this->positionOf($html, 'afb-align-group');
        $strikeout = $this->positionOf($html, 'id="afb-strikeout"');

        $this->assertGreaterThan($styleGroup, $strikeout);
        $this->assertLessThan($alignGroup, $strikeout);
        $this->assertStringContainsString('aria-pressed="false"', substr($html, $strikeout - 120, 240));
    }

    public function test_strikeout_is_offered_in_the_legacy_editor_too(): void
    {
        $this->assertStringContainsString('id="afb-strikeout"', $this->renderPanel(pdfjs: false));
    }
}
