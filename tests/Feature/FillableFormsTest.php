<?php

namespace Tests\Feature;

use App\Jobs\ProcessUploadedDocumentJob;
use App\Models\Document;
use App\Support\FillableForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FillableFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalogue_has_the_ten_requested_forms_and_their_files(): void
    {
        $slugs = FillableForms::all()->keys()->all();
        foreach ([
            'irs-form-w9', 'irs-form-w2', 'irs-form-1099-nec', 'lease-agreement', 'invoice',
            'bill-of-sale', 'nda', 'power-of-attorney', 'liability-waiver', 'employment-contract',
            'irs-form-1040',
        ] as $slug) {
            $this->assertContains($slug, $slugs);
            $form = FillableForms::find($slug);
            $this->assertNotNull(FillableForms::filePath($form), "$slug has no PDF on disk");
            $this->assertGreaterThan(0, $form['fields'], "$slug lists no fields");
            $this->assertFileExists(public_path($form['preview']), "$slug has no preview");
        }
    }

    public function test_the_pdf_editor_page_offers_every_form_with_fill_out_now(): void
    {
        $response = $this->get(route('documents.index'))->assertOk()->assertSee('Fillable forms');
        foreach (FillableForms::all() as $form) {
            $response->assertSee($form['title'])
                ->assertSee(route('forms.fill', $form['slug']), false)
                ->assertSee(route('forms.show', $form['slug']), false);
        }
        // Popular forms come first.
        $body = $response->getContent();
        $this->assertLessThan(strpos($body, 'Form 1040 <small>'), strpos($body, 'Form W-9 <small>'));
    }

    public function test_there_is_no_separate_forms_listing_page(): void
    {
        $this->get('/forms')->assertNotFound();
    }

    public function test_each_form_has_a_detail_page(): void
    {
        foreach (FillableForms::all() as $form) {
            $this->get(route('forms.show', $form['slug']))
                ->assertOk()
                ->assertSee($form['title'])
                ->assertSee('Fill out now')
                ->assertSee(route('forms.fill', $form['slug']), false)
                ->assertSee(route('documents.index') . '#fillable-forms', false);
        }
    }

    public function test_unknown_form_is_not_found(): void
    {
        $this->get('/forms/not-a-form')->assertNotFound();
        $this->post('/forms/not-a-form/fill')->assertNotFound();
    }

    public function test_fill_out_now_creates_a_document_from_every_form_and_opens_the_editor(): void
    {
        Storage::fake('local');
        Bus::fake();

        foreach (FillableForms::all() as $form) {
            $response = $this->post(route('forms.fill', $form['slug']));

            $document = Document::query()->latest('id')->first();
            $this->assertNotNull($document, $form['slug']);
            $this->assertSame($form['document_name'], $document->original_name);
            $this->assertSame('editor', $document->mode);
            $this->assertSame(
                file_get_contents(FillableForms::filePath($form)),
                Storage::disk('local')->get($document->path),
                $form['slug'] . ' was not copied byte for byte',
            );
            $response->assertRedirect(route('documents.editPdfjs', $document));
            Bus::assertDispatched(ProcessUploadedDocumentJob::class, fn ($job) => $job->documentId === $document->id);
        }

        $this->assertSame(FillableForms::all()->count(), Document::query()->count());
    }

    public function test_the_visitor_who_filled_a_form_can_open_it_and_a_stranger_cannot(): void
    {
        Storage::fake('local');
        Bus::fake();

        $filled = $this->post(route('forms.fill', 'irs-form-w9'));
        $document = Document::query()->latest('id')->first();

        // A guest owns a document through the guest cookie the response set
        // (App\Services\GuestDocuments); the test client does not keep cookies by itself.
        $guest = $filled->getCookie(\App\Services\GuestDocuments::COOKIE, decrypt: true)->getValue();
        $this->withCookie(\App\Services\GuestDocuments::COOKIE, $guest)
            ->followingRedirects()->get(route('documents.editPdfjs', $document))->assertOk();

        $this->flushSession();
        $this->defaultCookies = [];
        $this->followingRedirects()->get(route('documents.editPdfjs', $document))->assertNotFound();
    }
}
