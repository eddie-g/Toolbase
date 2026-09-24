<?php

namespace Tests\Feature;

use App\Models\AiLogoRequest;
use App\Models\User;
use App\UserPortal\Pages\ImageGenerator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The portal's Images page works on single images of a batch: names, trash,
 * permanent delete and upscales are per image (ai_logo_requests.image_meta).
 */
class PortalImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->user = User::factory()->create(['email_verified_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('user'));
    }

    private function batch(): AiLogoRequest
    {
        Storage::disk('public')->put('logos/1/a.png', 'a');
        Storage::disk('public')->put('logos/1/b.png', 'b');

        return AiLogoRequest::create([
            'user_id' => $this->user->id,
            'domain' => 'acme',
            'style' => 'professional',
            'prompt' => 'a bold acme mark',
            'model' => 'fal-ai/flux/schnell',
            'output_format' => 'raster',
            'original_prompt' => 'a bold acme mark',
            'status' => 'completed',
            'image_urls' => ['/storage/logos/1/a.png', '/storage/logos/1/b.png'],
            'is_showcase' => true,
            'showcase_image_indexes' => [0, 1],
        ]);
    }

    public function test_renaming_one_image_of_a_batch_leaves_the_other_alone(): void
    {
        $logo = $this->batch();

        Livewire::actingAs($this->user)
            ->test(ImageGenerator::class)
            ->call('startRename', $logo->id, 1)
            ->set('editingName', 'Second only')
            ->call('saveRename', $logo->id, 1);

        $logo->refresh();
        $this->assertSame('Second only', $logo->imageMeta(1)['name']);
        $this->assertArrayNotHasKey('name', $logo->imageMeta(0));
        $this->assertSame('acme', $logo->domain, 'the logo text the studio uses is untouched');
    }

    public function test_every_image_of_a_batch_is_its_own_item_in_both_views(): void
    {
        $logo = $this->batch();

        $page = Livewire::actingAs($this->user)->test(ImageGenerator::class);
        $this->assertSame(2, $page->instance()->images->total());

        $page->call('setViewMode', 'table');
        $this->assertSame(2, substr_count($page->html(), 'wire:key="row-' . $logo->id . '-'));
    }

    public function test_trash_restore_and_permanent_delete(): void
    {
        $logo = $this->batch();

        $page = Livewire::actingAs($this->user)->test(ImageGenerator::class)
            ->call('trashImage', $logo->id, 0);
        $this->assertSame(1, $page->instance()->images->total());
        $this->assertSame(1, $page->instance()->trashCount);

        $page->call('restoreImage', $logo->id, 0);
        $this->assertSame(0, $page->instance()->trashCount);

        $page->call('trashImage', $logo->id, 0)->call('purgeImage', $logo->id, 0);
        $logo->refresh();
        $this->assertTrue($logo->isImagePurged(0));
        Storage::disk('public')->assertMissing('logos/1/a.png');
        Storage::disk('public')->assertExists('logos/1/b.png');
        $this->assertSame([1], $logo->showcase_image_indexes, 'a deleted image leaves the showcase');

        $this->actingAs($this->user)
            ->get(route('generatedImages.original', ['logoRequest' => $logo->id, 'index' => 0]))
            ->assertNotFound();
    }

    public function test_trash_older_than_thirty_days_is_purged_by_the_schedule_command(): void
    {
        $logo = $this->batch();
        $logo->updateImageMeta(0, ['trashed_at' => now()->subDays(31)->toIso8601String()]);
        $logo->updateImageMeta(1, ['trashed_at' => now()->subDays(29)->toIso8601String()]);

        $this->artisan('logos:purge-trash')->assertSuccessful();

        $logo->refresh();
        $this->assertTrue($logo->isImagePurged(0));
        $this->assertFalse($logo->isImagePurged(1));
    }

    public function test_an_upscaled_image_is_not_upscaled_and_charged_again(): void
    {
        $logo = $this->batch();
        $logo->updateImageMeta(1, ['upscaled' => ['width' => 2048, 'height' => 2048]]);
        $this->user->forceFill(['credit_balance' => 5])->save();

        $this->actingAs($this->user)
            ->postJson(route('domainSearch.upscaleLogo'), [
                'image_url' => 'x',
                'logo_request_id' => $logo->id,
                'image_index' => 1,
            ])
            ->assertStatus(409);

        $this->assertEquals(5, (float) $this->user->fresh()->credit_balance);
    }

    public function test_other_accounts_cannot_touch_an_image(): void
    {
        $logo = $this->batch();
        $other = User::factory()->create(['email_verified_at' => now()]);

        Livewire::actingAs($other)
            ->test(ImageGenerator::class)
            ->call('trashImage', $logo->id, 0)
            ->call('purgeImage', $logo->id, 1);

        $this->assertSame([], $logo->fresh()->imageMeta(0));
        $this->assertFalse($logo->fresh()->isImagePurged(1));
    }

    public function test_open_in_editor_puts_the_image_on_a_pdf_and_opens_the_editor(): void
    {
        Storage::fake('local');
        $logo = $this->batch();
        $png = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($png);
        Storage::disk('public')->put('logos/1/b.png', ob_get_clean());
        $logo->updateImageMeta(1, ['name' => 'Wide mark']);

        $response = $this->actingAs($this->user)
            ->post(route('documents.createFromGeneratedImage', ['logoRequest' => $logo->id, 'index' => 1]));

        $document = \App\Models\Document::query()->where('user_id', $this->user->id)->latest('id')->first();
        $this->assertNotNull($document);
        $this->assertSame('Wide mark.pdf', $document->original_name);
        $response->assertRedirect(route('documents.editNew', [
            'document' => $document,
            'pdfjs' => 1,
            'from' => 'admin',
            'return_to' => route('filament.user.pages.image-generator'),
        ]));
        $this->assertStringStartsWith('%PDF', Storage::get($document->path));
    }

    public function test_open_in_editor_is_only_for_the_images_owner(): void
    {
        $logo = $this->batch();
        $other = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($other)
            ->post(route('documents.createFromGeneratedImage', ['logoRequest' => $logo->id, 'index' => 0]))
            ->assertForbidden();
    }
}
