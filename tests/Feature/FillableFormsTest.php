<?php

namespace Tests\Feature;

use App\Jobs\ProcessUploadedDocumentJob;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FillableFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_forms_index_lists_form_1040(): void
    {
        $this->get(route('forms.index'))
            ->assertOk()
            ->assertSee('Form 1040')
            ->assertSee('U.S. Individual Income Tax Return')
            ->assertSee(route('forms.show', 'irs-form-1040'), false);
    }

    public function test_form_detail_page_shows_the_form_and_its_fill_action(): void
    {
        $this->get(route('forms.show', 'irs-form-1040'))
            ->assertOk()
            ->assertSee('Form 1040')
            ->assertSee('199')
            ->assertSee('Fill out now')
            ->assertSee(route('forms.fill', 'irs-form-1040'), false);
    }

    public function test_unknown_form_is_not_found(): void
    {
        $this->get('/forms/not-a-form')->assertNotFound();
        $this->post('/forms/not-a-form/fill')->assertNotFound();
    }

    public function test_fill_out_now_creates_a_document_from_the_form_and_opens_the_editor(): void
    {
        Storage::fake('local');
        Bus::fake();

        $response = $this->post(route('forms.fill', 'irs-form-1040'));

        $document = Document::query()->latest('id')->first();
        $this->assertNotNull($document);
        $this->assertSame('Form 1040 (2025).pdf', $document->original_name);
        $this->assertSame('editor', $document->mode);
        $this->assertTrue(Storage::disk('local')->exists($document->path));
        $this->assertGreaterThan(100_000, $document->size_bytes);
        $this->assertSame(
            file_get_contents(resource_path('forms/f1040.pdf')),
            Storage::disk('local')->get($document->path),
        );

        $response->assertRedirect(route('documents.editPdfjs', $document));
        Bus::assertDispatched(ProcessUploadedDocumentJob::class, fn ($job) => $job->documentId === $document->id);

        // The guest who filled it can open it (the editor route redirects to
        // the pdf.js editor page); a stranger cannot.
        $this->followingRedirects()->get(route('documents.editPdfjs', $document))->assertOk();
        $this->flushSession();
        $this->followingRedirects()->get(route('documents.editPdfjs', $document))->assertNotFound();
    }
}
