<?php

namespace Tests\Feature;

use App\Jobs\ProcessUploadedDocumentJob;
use App\Http\Controllers\PdfTestController;
use App\Models\Admin;
use App\Models\PdfExtractionFitz;
use App\Models\PdfState;
use App\Models\PdfUploadTest;
use App\Models\PdfUploadTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfUploadTestHarnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge();
        DB::setDefaultConnection('sqlite');

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 20)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('original_backup_path')->nullable();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mode')->nullable();
            $table->timestamp('deleted_at')->nullable(); // Document uses SoftDeletes (trash)
            $table->timestamps();
        });

        Schema::create('pdf_extractions_fitz', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->nullable()->constrained('documents')->cascadeOnDelete();
            $table->string('user_email')->nullable();
            $table->string('session_id')->nullable();
            $table->string('pdf_filename');
            $table->integer('total_pages');
            $table->integer('total_words');
            $table->longText('full_text');
            $table->json('extraction_data');
            $table->timestamps();
        });

        Schema::create('pdf_state', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('pdf_extraction_fitz_id')->nullable()->constrained('pdf_extractions_fitz')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('user_email')->nullable();
            $table->string('session_id');
            $table->integer('page_number')->nullable();
            $table->json('annotation_data');
            $table->string('state')->default('not_saved');
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_07_23_000000_create_pdf_upload_tests_table.php');
        $migration->up();
        $reviewFieldsMigration = require database_path('migrations/2026_07_23_000001_add_review_fields_to_pdf_upload_tests_table.php');
        $reviewFieldsMigration->up();
        $casesMigration = require database_path('migrations/2026_07_23_000002_create_pdf_upload_test_cases_table.php');
        $casesMigration->up();
        $testIdsMigration = require database_path('migrations/2026_07_23_000003_add_test_id_to_pdf_upload_test_cases_table.php');
        $testIdsMigration->up();
        $paragraphGroupingMigration = require database_path('migrations/2026_07_26_000000_add_paragraph_grouping_to_pdf_upload_tests_table.php');
        $paragraphGroupingMigration->up();
    }

    public function test_admin_can_upload_a_persistent_pdf_test_with_database_original(): void
    {
        Storage::fake();
        Queue::fake();
        $admin = $this->createAdmin('pdf-upload-admin@example.com');
        $pdfContents = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

        $response = $this->actingAs($admin, 'admin')->post(
            route('pdfTests.uploadTests.store'),
            [
                'pdf' => UploadedFile::fake()->createWithContent('stable-annotations.pdf', $pdfContents),
                'paragraph_grouping_enabled' => true,
            ],
            ['Accept' => 'application/json']
        );

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'message' => 'PDF uploaded. Opening the annotation review.',
            ])
            ->assertJsonPath('test.original_name', 'stable-annotations.pdf')
            ->assertJsonPath('test.paragraph_grouping_enabled', true)
            ->assertJsonPath('test.review_url', fn ($url) => is_string($url) && str_contains($url, '/review'));

        $fixture = PdfUploadTest::query()->firstOrFail();
        $document = $fixture->document()->firstOrFail();

        $this->assertSame($admin->id, $fixture->admin_id);
        $this->assertTrue($fixture->paragraph_grouping_enabled);
        $this->assertSame($admin->id, $document->admin_id);
        $this->assertSame($document->id, $response->json('test.document_id'));
        $this->assertSame('regression', $document->mode);
        $this->assertSame($pdfContents, $fixture->pdfContents());
        $this->assertSame(hash('sha256', $pdfContents), $fixture->sha256);
        Storage::assertExists($document->path);
        Storage::assertExists($document->original_backup_path);
        $this->assertSame($pdfContents, Storage::get($document->path));
        $this->assertSame($pdfContents, Storage::get($document->original_backup_path));
        Queue::assertPushed(ProcessUploadedDocumentJob::class, function (ProcessUploadedDocumentJob $job) use ($document) {
            return $job->documentId === $document->id;
        });

        $this->actingAs($admin, 'admin')
            ->get(route('pdfTests.uploadTests.original', $fixture))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertContent($pdfContents);
    }

    public function test_admin_runner_renders_the_pdf_upload_tests_tab(): void
    {
        $admin = $this->createAdmin('pdf-tab-admin@example.com');

        $this->actingAs($admin, 'admin')
            ->get(route('filament.admin.pages.run-pdf-tests'))
            ->assertOk()
            ->assertSee('PDF upload tests')
            ->assertSee('Upload and review')
            ->assertSee('Apply paragraph grouping')
            ->assertSee('Select annotation')
            ->assertSee('Testing…')
            ->assertSee('Run all tests for PDF')
            ->assertSee('Run ALL uploaded PDF tests')
            ->assertSee("activeTab: 'upload-tests'", false)
            ->assertDontSee('Old editor')
            ->assertSee('Delete');
    }

    public function test_admin_can_toggle_paragraph_grouping_for_an_uploaded_pdf(): void
    {
        Storage::fake();
        Queue::fake();
        $admin = $this->createAdmin('pdf-grouping-admin@example.com');

        $this->actingAs($admin, 'admin')->post(
            route('pdfTests.uploadTests.store'),
            [
                'pdf' => UploadedFile::fake()->createWithContent(
                    'paragraph-toggle.pdf',
                    "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"
                ),
            ],
            ['Accept' => 'application/json']
        )->assertCreated()
            ->assertJsonPath('test.paragraph_grouping_enabled', false);

        $fixture = PdfUploadTest::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->patchJson(route('pdfTests.uploadTests.paragraphGrouping', $fixture), [
                'paragraph_grouping_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('test.paragraph_grouping_enabled', true)
            ->assertJsonPath('message', 'Paragraph grouping enabled.');

        $this->assertTrue($fixture->fresh()->paragraph_grouping_enabled);

        $this->actingAs($admin, 'admin')
            ->getJson(route('pdfTests.uploadTests.index'))
            ->assertJsonPath('tests.0.paragraph_grouping_enabled', true)
            ->assertJsonPath(
                'tests.0.paragraph_grouping_url',
                route('pdfTests.uploadTests.paragraphGrouping', $fixture)
            );
    }

    public function test_saved_ss5_instructions_map_to_their_javascript_scenarios(): void
    {
        $method = new \ReflectionMethod(PdfTestController::class, 'resolveUploadTestScenario');
        $controller = app(PdfTestController::class);

        $underline = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4471_3_3:19',
            'runtime_annotation_id' => 'pdfjs_4471_3_3:19',
            'page_index' => 3,
            'target_text' => 'Paperwork Reduction Act Statement -This information collection meets the requirements of 44 U.S.C. § 3507, as',
            'test_comment' => 'Delete this item and its underline. Keep :19 and :21.',
        ]));
        $swapReplacement = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4471_0_0:11',
            'runtime_annotation_id' => 'pdfjs_4471_0_0:11',
            'page_index' => 0,
            'target_text' => 'Apply for a replacement Social Security card',
            'test_comment' => 'switch places with pdfjs_4471_0_0:9',
        ]));
        $swapOriginal = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4471_0_0:9',
            'runtime_annotation_id' => 'pdfjs_4471_0_0:9',
            'page_index' => 0,
            'target_text' => 'Apply for an original Social Security card',
            'test_comment' => 'switch places with pdfjs_4471_0_0:11',
        ]));
        $sentence = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'promoted_1_5',
            'runtime_annotation_id' => 'promoted_1_5',
            'page_index' => 0,
            'target_text' => 'IMPORTANT: You MUST provide a properly completed application.',
            'test_comment' => 'Delete the sentance "For assistance call us at 1-800-772-1213 or visit our". Run save PDF check output.',
        ]));

        $this->assertSame('ss5_page4_delete_underlined_neighbor', $underline['scenario']);
        $this->assertSame('ss5_page1_swap_annotations', $swapReplacement['scenario']);
        $this->assertSame('0_0:11', $swapReplacement['swap_primary_suffix']);
        $this->assertSame('0_0:9', $swapReplacement['swap_partner_suffix']);
        $this->assertSame('ss5_page1_swap_annotations', $swapOriginal['scenario']);
        $this->assertSame('0_0:9', $swapOriginal['swap_primary_suffix']);
        $this->assertSame('0_0:11', $swapOriginal['swap_partner_suffix']);
        $this->assertSame('ss5_page1_delete_paragraph_sentence', $sentence['scenario']);
    }

    public function test_saved_f1040s3_instructions_map_to_their_javascript_scenarios(): void
    {
        $method = new \ReflectionMethod(PdfTestController::class, 'resolveUploadTestScenario');
        $controller = app(PdfTestController::class);

        $boundingBoxSnapping = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:113',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:113',
            'page_index' => 0,
            'target_text' => 'Total other payments or refundable credits. Add lines 13a through 13z . . . 14',
            'test_comment' => 'Bounding box snapping: click Edit, type one character, and verify that the glyph origin, box geometry, and font size do not change.',
        ]));
        $dragPreviewSpacing = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:71',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:71',
            'page_index' => 0,
            'target_text' => 'Credit for previously owned clean vehicles. Attach Form 8936',
            'test_comment' => 'Drag preview spacing: hold the pointer down while dragging and preserve the exact source glyph spacing.',
        ]));
        $movePreservesGlyphInset = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:38',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:38',
            'page_index' => 0,
            'target_text' => 'Credit for prior year minimum tax. Attach Form 8801 . . . . . . . .',
            'test_comment' => 'Move this annotation and verify that the text does not snap to the top of its bounding box.',
        ]));
        $mixedStyleEditResize = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:95',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:95',
            'page_index' => 0,
            'target_text' => '13 Other payments or refundable credits:',
            'test_comment' => 'Edit the non-bold text, keep the bold 13, then resize with the drag handle and preserve the space after 13.',
        ]));
        $resizePreservesSourceSpacing = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:101',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:101',
            'page_index' => 0,
            'target_text' => 'years . . . . . . . . . . . . . . . . . . . . . . . .',
            'test_comment' => 'Resize this annotation with the drag handle. Preserve the exact source spacing between years and every leader dot; the spacing must never collapse.',
        ]));
        $scrollPreservesEditedSpacing = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:23',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:23',
            'page_index' => 0,
            'target_text' => 'Education credits from Form 8863, line 19 . . . . . . . . . . . . . . . . . . . .',
            'test_comment' => 'Edit 8863 to 8763, then scroll repeatedly. The horizontal spacing must remain stable and the annotation must never jump.',
        ]));
        $dateEditPreservesMixedWeight = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:119',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:119',
            'page_index' => 0,
            'target_text' => 'Schedule 3 (Form 1040) 2025 Created 11/17/25',
            'test_comment' => 'Edit 11/17/25 to 11/17/26. Keep the title bold and keep Created 11/17/26 regular, not bold, after commit and scroll.',
        ]));
        $scrollPreservesUserSizedGeometry = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:34',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:34',
            'page_index' => 0,
            'target_text' => 'a',
            'test_comment' => 'Edit and resize this annotation, then scroll repeatedly. The bounding box geometry must never grow, scrolling must avoid rebuilds, and performance must not lag.',
        ]));
        $titleUnderlineExport = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_5304_0_0:2',
            'runtime_annotation_id' => 'pdfjs_5304_0_0:2',
            'page_index' => 0,
            'target_text' => 'Additional Income and Adjustments to Income',
            'test_comment' => 'Apply underline to the title, download the PDF, and verify the underline is visible in the exported PDF.',
        ]));
        $moveWithoutUnderline = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:115',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:115',
            'page_index' => 0,
            'target_text' => '15 Add lines 9 through 12 and 14. Enter here and on Form 1040, 1040-SR, or 1040-NR, line 31',
            'test_comment' => 'Drag this annotation and verify that no underline is added.',
        ]));
        $deleteNamePreservesFormArtwork = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:12',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:12',
            'page_index' => 0,
            'target_text' => 'Name(s) shown on Form 1040, 1040-SR, or 1040-NR',
            'test_comment' => 'Delete this annotation, run downloadAnnotatedPdf(), and preserve the surrounding form rules, vertical divider, and entry-field backgrounds.',
        ]));
        $movePartHeaderPreservesSourceTile = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:14',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:14',
            'page_index' => 0,
            'target_text' => 'Part I',
            'test_comment' => 'Move this annotation with the real drag control. Keep the black tile at its source, render the moved Part I text black and visible on the white destination, and run downloadAnnotatedPdf().',
        ]));
        $dragDown600 = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:87',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:87',
            'page_index' => 0,
            'target_text' => 'Amount paid with request for extension to file (see instructions)',
            'test_comment' => 'drag down 600 pixels make sure the font size doesnt change',
        ]));
        $dragDown400 = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4506_0_0:100',
            'runtime_annotation_id' => 'pdfjs_4506_0_0:100',
            'page_index' => 0,
            'target_text' => 'Section 1341 credit for repayment of amounts included in income from earlier',
            'test_comment' => 'drag down 400px make sure the font size does not change',
        ]));

        $this->assertSame(
            'f1040s3_page1_bounding_box_snapping',
            $boundingBoxSnapping['scenario']
        );
        $this->assertSame(
            'f1040s3_page1_drag_preview_spacing',
            $dragPreviewSpacing['scenario']
        );
        $this->assertSame(
            'f1040s3_page1_move_preserves_glyph_inset',
            $movePreservesGlyphInset['scenario']
        );
        $this->assertSame(
            'f1040s3_page1_mixed_style_edit_resize',
            $mixedStyleEditResize['scenario']
        );
        $this->assertSame(
            'f1040s3_page1_resize_preserves_source_spacing',
            $resizePreservesSourceSpacing['scenario']
        );
        $this->assertSame(
            'f1040s3_page1_scroll_preserves_edited_spacing',
            $scrollPreservesEditedSpacing['scenario']
        );
        $this->assertSame(
            'f1040s3_page1_date_edit_preserves_mixed_weight',
            $dateEditPreservesMixedWeight['scenario']
        );
        $this->assertSame(
            'f1040s3_page1_scroll_preserves_user_sized_geometry',
            $scrollPreservesUserSizedGeometry['scenario']
        );
        $this->assertSame(
            'f1040s3_page1_title_underline_export',
            $titleUnderlineExport['scenario']
        );
        $this->assertSame(
            'f1040s3_page1_move_without_false_underline',
            $moveWithoutUnderline['scenario']
        );
        $this->assertSame(
            'f1040s3_page1_delete_name_preserves_form_artwork',
            $deleteNamePreservesFormArtwork['scenario']
        );
        $this->assertSame(
            'f1040s3_page1_move_part_header_preserves_source_tile',
            $movePartHeaderPreservesSourceTile['scenario']
        );
        $this->assertSame(
            'f1040s3_page1_move_down_preserves_font_size',
            $dragDown600['scenario']
        );
        $this->assertSame('0_0:87', $dragDown600['move_down_suffix']);
        $this->assertSame(600, $dragDown600['move_down_pixels']);
        $this->assertSame(
            'f1040s3_page1_move_down_preserves_font_size',
            $dragDown400['scenario']
        );
        $this->assertSame('0_0:100', $dragDown400['move_down_suffix']);
        $this->assertSame(400, $dragDown400['move_down_pixels']);
    }

    public function test_saved_drylab_table_edit_and_ss5_resize_instructions_map_to_javascript_scenarios(): void
    {
        $method = new \ReflectionMethod(PdfTestController::class, 'resolveUploadTestScenario');
        $controller = app(PdfTestController::class);

        $drylab = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4579_0_0:0',
            'runtime_annotation_id' => 'pdfjs_4579_0_0:0',
            'page_index' => 0,
            'target_text' => 'DrylabNews',
            'test_comment' => 'pdfjs_4579_0_0:0 move this header annotation down 400px then run the download PDF. The resulting PDF should leave no fragments of "Drylab News" behind and the bottom header "for investors & friends * May 2017" should remain there and not be redacted.',
        ]));
        $drylabSelection = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'promoted_1_7',
            'runtime_annotation_id' => 'promoted_1_7',
            'page_index' => 0,
            'target_text' => 'the 2.05 MNOK loan from Innovation Norway. Including the development agreement with Filmlance International, the total new capital is 5 MNOK, partly tied to the successful completion of milestones. All formalities associated with this process are now finalized.',
            'test_comment' => 'Select promoted_1_7 and press Ctrl+A. The selected text must remain aligned with the original Drylab paragraph on every line, including "now finalized."; selection must not show detached blue bars at line breaks.',
        ]));
        $drylabParagraphAppend = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'promoted_1_1',
            'runtime_annotation_id' => 'promoted_1_1',
            'page_index' => 0,
            'target_text' => "Welcome to our first newsletter of 2017! It's been a while since the last one, and a lot has happened. We promise to keep them coming every two months hereafter, and permit ourselves to make this one rather long. The big news is the beginnings of our launch in the American market, but there are also interesting updates on sales, development, mentors and (of course) the investment round that closed in January.",
            'test_comment' => 'add "123" to the end of this paragraph then deselect it the paragraph completely reflows and losses the indention and spacing we need to keep the same formatting after edit. Likely an issue is with the bounding box size or font glyphs.',
        ]));
        $paragraph = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'promoted_3_9',
            'runtime_annotation_id' => 'promoted_3_9',
            'page_index' => 2,
            'target_text' => 'In most cases, you can take or mail this signed application with your documents to any Social Security office.',
            'test_comment' => 'shrink the bounding box by 50%. make sure the text is resized to the edge of the bounding box then confirm on the downloaded PDF',
        ]));
        $table = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4698_0_0:6',
            'runtime_annotation_id' => 'pdfjs_4698_0_0:6',
            'page_index' => 0,
            'target_text' => 'Header 2',
            'test_comment' => 'move this annotation up 200px make sure it does not effect the table in the editor so the background table is not deleted. This is only an editor issue it is NOT a download issue.',
        ]));
        $tableEdit = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4727_1_1:31',
            'runtime_annotation_id' => 'pdfjs_4727_1_1:31',
            'page_index' => 1,
            'target_text' => 'Best Practices: Separate two tables with header rows',
            'test_comment' => 'edit this line and add a 1 to the end, compare the bounding box to the annotation, then deselect it; the text cannot jump or shift on deselection',
        ]));
        $tableExactFontEdit = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4799_2_2:35',
            'runtime_annotation_id' => 'pdfjs_4799_2_2:35',
            'page_index' => 2,
            'target_text' => 'Project 1',
            'test_comment' => 'When I edit and type, the sans-serif font jumps. Use the exact PDF font from the document fonts list so Calibri stays consistent through deselection.',
        ]));
        $tableEdgeTightHeaderExport = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'pdfjs_4799_0_0:6',
            'runtime_annotation_id' => 'pdfjs_4799_0_0:6',
            'page_index' => 0,
            'target_text' => 'Header 3',
            'test_comment' => 'The bounding box is tight against the text edge with no spacing. Change the trailing glyph font and export; Header 3 must not overflow or wrap onto the next line in the downloaded PDF.',
        ]));
        $tablePromotedEditEntry = $method->invoke($controller, new PdfUploadTestCase([
            'annotation_id' => 'promoted_1_0',
            'runtime_annotation_id' => 'promoted_1_0',
            'page_index' => 0,
            'target_text' => 'Use tables to organize data not format information. Never use table for layout. Avoid merging cells.',
            'test_comment' => 'Click Edit text on promoted_1_0. Edit mode must not add top padding or cause a vertical jump from the initial annotation position.',
        ]));

        $this->assertSame('drylab_page1_move_title_preserves_footer', $drylab['scenario']);
        $this->assertSame(400, $drylab['move_down_pixels']);
        $this->assertSame(
            'drylab_page1_select_paragraph_matches_source',
            $drylabSelection['scenario']
        );
        $this->assertSame(
            'drylab_page1_append_paragraph_preserves_layout',
            $drylabParagraphAppend['scenario']
        );
        $this->assertSame('123', $drylabParagraphAppend['paragraph_append_text']);
        $this->assertSame(
            'table_examples_page1_move_header_preserves_editor_table',
            $table['scenario']
        );
        $this->assertSame(
            'table_examples_page2_edit_text_preserves_geometry',
            $tableEdit['scenario']
        );
        $this->assertSame(
            'table_examples_page3_exact_font_edit_preserves_geometry',
            $tableExactFontEdit['scenario']
        );
        $this->assertSame(3, $tableExactFontEdit['table_edit_page_number']);
        $this->assertSame('2_2:35', $tableExactFontEdit['table_edit_suffix']);
        $this->assertTrue($tableExactFontEdit['require_exact_document_font']);
        $this->assertSame(
            'table_examples_page1_edge_tight_header_export',
            $tableEdgeTightHeaderExport['scenario']
        );
        $this->assertSame(1, $tableEdgeTightHeaderExport['table_export_page_number']);
        $this->assertSame('0_0:6', $tableEdgeTightHeaderExport['table_export_suffix']);
        $this->assertSame('Header 3', $tableEdgeTightHeaderExport['table_export_expected_text']);
        $this->assertSame(
            'table_examples_page1_promoted_edit_entry_stable',
            $tablePromotedEditEntry['scenario']
        );
        $this->assertSame('promoted_1_0', $tablePromotedEditEntry['promoted_edit_annotation_id']);
        $this->assertSame('ss5_page3_shrink_paragraph_and_export', $paragraph['scenario']);
        $this->assertSame(0.5, $paragraph['paragraph_shrink_ratio']);
    }

    public function test_admin_can_review_the_actual_pdf_and_save_an_annotation_test_comment(): void
    {
        Storage::fake();
        Queue::fake();
        $admin = $this->createAdmin('pdf-review-admin@example.com');

        $this->actingAs($admin, 'admin')->post(
            route('pdfTests.uploadTests.store'),
            [
                'pdf' => UploadedFile::fake()->createWithContent(
                    'review-target.pdf',
                    "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"
                ),
            ],
            ['Accept' => 'application/json']
        )->assertCreated();

        $fixture = PdfUploadTest::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->get(route('pdfTests.uploadTests.review', $fixture))
            ->assertOk()
            ->assertSee('Annotation test')
            ->assertSee('Select a blue box')
            ->assertSee('data-upload-test-review="1"', false)
            ->assertSee(route('pdfTests.uploadTests.original', $fixture), false);

        $this->actingAs($admin, 'admin')
            ->patchJson(route('pdfTests.uploadTests.update', $fixture), [
                'annotation_id' => 'promoted_1_25',
                'runtime_annotation_id' => 'pdfjs_'.$fixture->document_id.'_0_0:25',
                'page_index' => 0,
                'target_text' => 'Security deposit total',
                'test_comment' => 'Move this item below the total and change it to red.',
            ])
            ->assertOk()
            ->assertJsonPath('case.annotation_id', 'promoted_1_25')
            ->assertJsonPath('case.page_index', 0)
            ->assertJsonPath('case.test_comment', 'Move this item below the total and change it to red.');

        $firstCase = PdfUploadTestCase::query()->firstOrFail();
        $this->assertSame('promoted_1_25', $firstCase->annotation_id);
        $this->assertSame('pdfjs_'.$fixture->document_id.'_0_0:25', $firstCase->runtime_annotation_id);
        $this->assertSame(0, $firstCase->page_index);
        $this->assertSame('Security deposit total', $firstCase->target_text);
        $this->assertSame('Move this item below the total and change it to red.', $firstCase->test_comment);
        $this->assertNotNull($firstCase->test_saved_at);
        $this->assertNotEmpty($firstCase->test_id);

        $this->actingAs($admin, 'admin')
            ->patchJson(route('pdfTests.uploadTests.update', $fixture), [
                'annotation_id' => 'promoted_3_8',
                'runtime_annotation_id' => 'pdfjs_'.$fixture->document_id.'_2_2:8',
                'page_index' => 2,
                'target_text' => 'Who can sign the application?',
                'test_comment' => 'Rotate this annotation 90 degrees.',
            ])
            ->assertOk()
            ->assertJsonPath('case.annotation_id', 'promoted_3_8')
            ->assertJsonPath('case.test_comment', 'Rotate this annotation 90 degrees.');

        $this->actingAs($admin, 'admin')
            ->patchJson(route('pdfTests.uploadTests.update', $fixture), [
                'annotation_id' => 'promoted_1_25',
                'runtime_annotation_id' => 'pdfjs_'.$fixture->document_id.'_0_0:25',
                'page_index' => 0,
                'target_text' => 'Security deposit total',
                'test_comment' => 'Move this item below the total and make it blue.',
            ])
            ->assertOk();

        $this->assertSame(2, PdfUploadTestCase::query()->count());
        $this->assertSame(2, PdfUploadTestCase::query()->pluck('test_id')->unique()->count());
        $this->assertDatabaseHas('pdf_upload_test_cases', [
            'pdf_upload_test_id' => $fixture->id,
            'annotation_id' => 'promoted_1_25',
            'test_comment' => 'Move this item below the total and make it blue.',
        ]);
        $this->assertDatabaseHas('pdf_upload_test_cases', [
            'pdf_upload_test_id' => $fixture->id,
            'annotation_id' => 'promoted_3_8',
            'test_comment' => 'Rotate this annotation 90 degrees.',
        ]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('pdfTests.uploadTests.index'))
            ->assertJsonPath('tests.0.case_count', 2)
            ->assertJsonPath('tests.0.cases.0.test_id', $firstCase->test_id)
            ->assertJsonPath('tests.0.cases.0.annotation_id', 'promoted_1_25')
            ->assertJsonPath('tests.0.cases.0.test_comment', 'Move this item below the total and make it blue.')
            ->assertJsonPath('tests.0.cases.1.annotation_id', 'promoted_3_8')
            ->assertJsonPath('tests.0.cases.1.test_comment', 'Rotate this annotation 90 degrees.')
            ->assertJsonPath('tests.0.review_url', route('pdfTests.uploadTests.review', $fixture))
            ->assertJsonPath('tests.0.delete_url', route('pdfTests.uploadTests.destroy', $fixture));
    }

    public function test_upload_test_list_stays_lightweight_when_document_diagnostics_exist(): void
    {
        Storage::fake();
        Queue::fake();
        $admin = $this->createAdmin('stable-ids@example.com');

        $this->actingAs($admin, 'admin')->post(
            route('pdfTests.uploadTests.store'),
            [
                'pdf' => UploadedFile::fake()->createWithContent(
                    'id-fixture.pdf',
                    "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"
                ),
            ],
            ['Accept' => 'application/json']
        )->assertCreated();

        $fixture = PdfUploadTest::query()->firstOrFail();
        $extraction = PdfExtractionFitz::query()->create([
            'document_id' => $fixture->document_id,
            'session_id' => 'upload-test-extraction',
            'pdf_filename' => 'id-fixture.pdf',
            'total_pages' => 1,
            'total_words' => 1,
            'full_text' => 'Stable',
            'extraction_data' => [],
        ]);
        $state = PdfState::query()->create([
            'document_id' => $fixture->document_id,
            'pdf_extraction_fitz_id' => $extraction->id,
            'admin_id' => $admin->id,
            'session_id' => 'upload-test-state',
            'page_number' => 0,
            'annotation_data' => [
                'id' => 'stable-annotation-id',
                'type' => 'text',
                'pageIndex' => 0,
            ],
            'state' => 'saved',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson(route('pdfTests.uploadTests.index'));

        $response->assertOk()
            ->assertJsonPath('tests.0.id', $fixture->id)
            ->assertJsonPath('tests.0.document_id', $fixture->document_id)
            ->assertJsonPath('tests.0.original_name', 'id-fixture.pdf')
            ->assertJsonMissingPath('tests.0.extraction_ids')
            ->assertJsonMissingPath('tests.0.annotations')
            ->assertJsonMissingPath('tests.0.active_annotation_count')
            ->assertJsonMissingPath('tests.0.deleted_annotation_count');

        $state->update(['state' => 'deleted']);

        $this->actingAs($admin, 'admin')
            ->getJson(route('pdfTests.uploadTests.index'))
            ->assertJsonPath('tests.0.id', $fixture->id)
            ->assertJsonPath('tests.0.document_id', $fixture->document_id)
            ->assertJsonMissingPath('tests.0.annotations');
    }

    public function test_admin_can_delete_an_uploaded_pdf_and_its_dedicated_document_files(): void
    {
        Storage::fake();
        Queue::fake();
        $admin = $this->createAdmin('pdf-delete-admin@example.com');

        $this->actingAs($admin, 'admin')->post(
            route('pdfTests.uploadTests.store'),
            [
                'pdf' => UploadedFile::fake()->createWithContent(
                    'delete-me.pdf',
                    "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"
                ),
            ],
            ['Accept' => 'application/json']
        )->assertCreated();

        $fixture = PdfUploadTest::query()->firstOrFail();
        $document = $fixture->document()->firstOrFail();
        $documentPath = $document->path;
        $originalPath = $document->original_backup_path;
        $testCase = $fixture->cases()->create([
            'annotation_id' => 'pdfjs_'.$document->id.'_0_0:1',
            'runtime_annotation_id' => 'pdfjs_'.$document->id.'_0_0:1',
            'page_index' => 0,
            'target_text' => 'Delete this test fixture.',
            'test_comment' => 'Delete this annotation.',
            'test_saved_at' => now(),
        ]);

        Storage::assertExists($documentPath);
        Storage::assertExists($originalPath);

        $this->actingAs($admin, 'admin')
            ->deleteJson(route('pdfTests.uploadTests.destroy', $fixture))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Uploaded PDF deleted.',
                'deleted_test_id' => $fixture->id,
                'deleted_document_id' => $document->id,
                'files_deleted' => true,
            ]);

        $this->assertDatabaseMissing('pdf_upload_tests', ['id' => $fixture->id]);
        $this->assertDatabaseMissing('pdf_upload_test_cases', ['id' => $testCase->id]);
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        Storage::assertMissing($documentPath);
        Storage::assertMissing($originalPath);
    }

    public function test_upload_test_endpoints_are_admin_only_and_scoped_to_the_owner(): void
    {
        Storage::fake();
        $owner = $this->createAdmin('fixture-owner@example.com');
        $otherAdmin = $this->createAdmin('fixture-other@example.com');

        $fixture = PdfUploadTest::query()->create([
            'uuid' => 'ee696829-26a1-4c85-a3a9-b64aeb467089',
            'admin_id' => $owner->id,
            'document_id' => \App\Models\Document::query()->create([
                'admin_id' => $owner->id,
                'original_name' => 'owner.pdf',
                'path' => 'pdf-upload-tests/owner/current.pdf',
                'original_backup_path' => 'pdf-upload-tests/owner/original.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 9,
                'mode' => 'regression',
            ])->id,
            'original_name' => 'owner.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
            'sha256' => hash('sha256', '%PDF-test'),
            'pdf_base64' => base64_encode('%PDF-test'),
        ]);
        $testCase = $fixture->cases()->create([
            'annotation_id' => 'pdfjs_'.$fixture->document_id.'_0_0:1',
            'runtime_annotation_id' => 'pdfjs_'.$fixture->document_id.'_0_0:1',
            'page_index' => 0,
            'target_text' => 'Owner-only target.',
            'test_comment' => 'Delete this annotation.',
            'test_saved_at' => now(),
        ]);

        $this->getJson(route('pdfTests.uploadTests.index'))->assertUnauthorized();

        $this->actingAs($otherAdmin, 'admin')
            ->getJson(route('pdfTests.uploadTests.index'))
            ->assertOk()
            ->assertJsonCount(0, 'tests');

        $this->actingAs($otherAdmin, 'admin')
            ->get(route('pdfTests.uploadTests.original', $fixture))
            ->assertNotFound();

        $this->actingAs($otherAdmin, 'admin')
            ->get(route('pdfTests.uploadTests.review', $fixture))
            ->assertNotFound();

        $this->actingAs($otherAdmin, 'admin')
            ->patchJson(route('pdfTests.uploadTests.update', $fixture), [
                'annotation_id' => 'not-owned',
                'page_index' => 0,
                'test_comment' => 'Delete this.',
            ])
            ->assertNotFound();

        $this->actingAs($otherAdmin, 'admin')
            ->patchJson(route('pdfTests.uploadTests.paragraphGrouping', $fixture), [
                'paragraph_grouping_enabled' => true,
            ])
            ->assertNotFound();

        $this->actingAs($otherAdmin, 'admin')
            ->deleteJson(route('pdfTests.uploadTests.destroy', $fixture))
            ->assertNotFound();

        $this->actingAs($otherAdmin, 'admin')
            ->postJson(route('pdfTests.runSingleTest'), [
                'test_key' => 'pdf_upload_saved_test',
                'upload_test_id' => $fixture->id,
                'upload_test_case_id' => $testCase->id,
                'run_id' => 'owner-scope-regression',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('pdf_upload_tests', ['id' => $fixture->id]);
    }

    private function createAdmin(string $email): Admin
    {
        return Admin::query()->create([
            'name' => 'PDF Test Admin',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => Admin::ROLE_ADMIN,
        ]);
    }
}
