<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Support\EditorSwitches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The editor and the exports can be turned off at once, from configuration
 * or with `editor:switch`, and what is not editing keeps working.
 */
class EditorKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['editor_limits.scale' => 1000]);
        $this->user = User::factory()->create();
        $this->document = Document::query()->create([
            'user_id' => $this->user->id,
            'original_name' => 'contract.pdf',
            'path' => 'documents/contract.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
        ]);
    }

    public function test_the_editor_switch_closes_the_editor_and_leaves_the_rest_open(): void
    {
        $this->artisan('editor:switch', ['switch' => 'editor', 'state' => 'off'])->assertExitCode(0);

        // The page says so, the API says so, and neither reaches a controller.
        $page = $this->actingAs($this->user)->get(route('documents.editPdfjs', $this->document));
        $page->assertStatus(503)->assertHeader('Retry-After', '300')
            ->assertSee('The editor is temporarily unavailable')->assertSee('Your documents are safe');
        foreach (['documents.saveAnnotationState', 'documents.editPdfjsRewriteTj', 'documents.addBlankPage'] as $route) {
            $this->actingAs($this->user)->postJson(route($route, $this->document), [])
                ->assertStatus(503)->assertJson(['success' => false, 'code' => 'editor_disabled']);
        }
        $this->actingAs($this->user)->post(route('documents.createBlank'), ['page_size' => 'A4'])
            ->assertStatus(503)->assertJsonPath('code', 'editor_disabled');
        $this->assertSame(1, Document::count(), 'nothing was created');

        // Still open: the list, renaming, the trash, and the export switch is its own.
        $this->actingAs($this->user)->get(route('documents.index'))->assertOk();
        $this->actingAs($this->user)->postJson(route('documents.rename', $this->document), ['name' => 'renamed'])->assertOk();
        $this->assertTrue(app(EditorSwitches::class)->enabled('export'));

        $this->artisan('editor:switch', ['switch' => 'editor', 'state' => 'on'])->assertExitCode(0);
        $this->actingAs($this->user)->postJson(route('documents.saveAnnotationState', $this->document), ['annotations' => []])->assertOk();
    }

    public function test_the_export_switch_stops_downloads_and_conversions_only(): void
    {
        config(['pdf_editor.switches.export.enabled' => false]);   // PDF_EXPORT_ENABLED=false

        foreach (['documents.downloadAnnotatedPdf', 'documents.convertToWord', 'documents.convertToPdfA'] as $route) {
            $response = $this->actingAs($this->user)->postJson(route($route, $this->document), []);
            $response->assertStatus(503)->assertJsonPath('code', 'export_disabled');
            $this->assertStringContainsString('temporarily unavailable', $response->json('message'));
        }
        $this->actingAs($this->user)->postJson(route('documents.saveAnnotationState', $this->document), ['annotations' => []])->assertOk();

        // The command wins over the configuration, in both directions, until reset.
        $switches = app(EditorSwitches::class);
        $switches->set('export', true);
        $this->assertTrue($switches->enabled('export'));
        $this->artisan('editor:switch', ['switch' => 'export', 'state' => 'reset'])->assertExitCode(0);
        $this->assertFalse($switches->enabled('export'));
        $this->assertNull($switches->override('export'));

        $this->artisan('editor:switch', ['switch' => 'everything', 'state' => 'off'])->assertExitCode(2);
    }

    public function test_every_route_that_forks_python_or_opens_the_editor_is_behind_a_switch(): void
    {
        $switches = app(EditorSwitches::class);
        $switches->set('editor', false);
        $switches->set('export', false);

        $open = [];
        foreach (config('editor_limits.routes') as $route => $class) {
            if (in_array($class, ['upload', 'export', 'process', 'edit', 'render'], true) && $switches->blocking($route) === null) {
                $open[] = "{$route} ({$class})";
            }
        }
        $this->assertSame([], $open);

        foreach ((array) config('pdf_editor.switches.editor.routes') as $route) {
            $this->assertTrue(Route::has($route), "{$route} is named in pdf_editor.switches but is not a route");
        }
        // A cache that is down must not take the editor down with it.
        \Illuminate\Support\Facades\Cache::shouldReceive('get')->andThrow(new \RuntimeException('redis is away'));
        $this->assertTrue($switches->enabled('editor'));
    }
}
