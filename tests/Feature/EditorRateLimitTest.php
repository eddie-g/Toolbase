<?php

namespace Tests\Feature;

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\PdfTestController;
use App\Http\Controllers\SavedSignatureController;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every editor route that forks Python or writes state is rate limited
 * (config/editor_limits.php, ThrottleEditorRoutes), per account or per guest,
 * and a refusal says what was limited and when to come back.
 */
class EditorRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_state_changing_editor_route_is_classified(): void
    {
        $classified = array_keys(config('editor_limits.routes'));
        $ownLimiter = config('editor_limits.own_limiter');
        $classes = array_keys(config('editor_limits.classes'));
        $unclassified = [];
        $named = [];

        foreach (Route::getRoutes() as $route) {
            $named[] = (string) $route->getName();
            $controller = ltrim((string) strtok((string) $route->getActionName(), '@'), '\\');
            if (! in_array($controller, [DocumentController::class, PdfTestController::class, SavedSignatureController::class], true)) {
                continue;
            }
            if (array_diff($route->methods(), ['GET', 'HEAD']) === []) {
                continue;
            }
            $name = (string) $route->getName();
            if (in_array($name, $ownLimiter, true)) {
                $this->assertNotEmpty(
                    array_filter($route->gatherMiddleware(), fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:')),
                    "{$name} is listed as having its own limiter but has none"
                );

                continue;
            }
            if (! in_array($name, $classified, true)) {
                $unclassified[] = $name ?: $route->uri();
            }
        }

        $this->assertSame([], $unclassified, 'Add these routes to config/editor_limits.php');
        $this->assertSame([], array_values(array_diff($classified, $named)), 'config/editor_limits.php names routes that do not exist');
        $this->assertSame([], array_values(array_diff(array_unique(config('editor_limits.routes')), $classes)), 'unknown limit class');
    }

    public function test_an_account_is_limited_per_class_with_a_clear_refusal(): void
    {
        config(['editor_limits.scale' => 1, 'editor_limits.classes.write.per_minute' => 3]);
        $user = User::factory()->create();
        $document = $this->documentFor($user);

        foreach (range(1, 3) as $attempt) {
            $this->actingAs($user)->postJson(route('documents.rename', $document), ['name' => "Name {$attempt}"])->assertOk();
        }
        $refused = $this->actingAs($user)->postJson(route('documents.rename', $document), ['name' => 'One too many']);
        $refused->assertStatus(429)->assertJson(['success' => false, 'code' => 'rate_limited']);
        $this->assertMatchesRegularExpression('/^Too many changes in a short time\. Try again in \d+ seconds\.$/', $refused->json('message'));
        $this->assertGreaterThan(0, (int) $refused->headers->get('Retry-After'));
        $this->assertStringStartsWith('Name 3', $document->fresh()->original_name, 'the refused request changed nothing');

        // The class is shared by its routes, not counted per route...
        $this->actingAs($user)->postJson(route('documents.trash', $document))->assertStatus(429);
        // ...another class has its own allowance...
        $this->actingAs($user)->getJson(route('documents.processing.status', $document))->assertOk();
        // ...and so has another account.
        $colleague = User::factory()->create();
        $theirs = $this->documentFor($colleague);
        $this->actingAs($colleague)->postJson(route('documents.rename', $theirs), ['name' => 'Theirs'])->assertOk();

        $this->travel(61)->seconds();
        $this->actingAs($user)->postJson(route('documents.rename', $document), ['name' => 'Later'])->assertOk();
    }

    public function test_a_guest_who_drops_cookies_still_runs_into_the_address_limit(): void
    {
        config(['editor_limits.scale' => 1, 'editor_limits.guest_ip_factor' => 2, 'editor_limits.classes.listing.per_minute' => 2]);

        // Same session: two page loads, then refused.
        $sameSession = fn () => $this->withCookie(config('session.cookie'), str_repeat('a', 40))->get(route('documents.index'));
        $sameSession()->assertOk();
        $sameSession()->assertOk();
        $sameSession()->assertStatus(429);

        // A new session each time gets round the per-session limit, not the per-address one (2 x 2).
        // The address has two counted so far (the refused request stopped at the session limit).
        $this->withCookie(config('session.cookie'), str_repeat('b', 40))->get(route('documents.index'))->assertOk();
        $this->withCookie(config('session.cookie'), str_repeat('c', 40))->get(route('documents.index'))->assertOk();
        $refused = $this->withCookie(config('session.cookie'), str_repeat('e', 40))->get(route('documents.index'));
        $refused->assertStatus(429);
        $this->assertStringContainsString('Too many page loads', $refused->getContent());
        $this->assertGreaterThan(0, (int) $refused->headers->get('Retry-After'));

        // Someone at another address is unaffected.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withCookie(config('session.cookie'), str_repeat('d', 40))
            ->get(route('documents.index'))->assertOk();
    }

    public function test_uploads_have_a_daily_allowance_too(): void
    {
        config(['editor_limits.scale' => 1, 'editor_limits.classes.upload' => ['per_minute' => 100, 'per_day' => 2, 'what' => 'new documents']]);
        $user = User::factory()->create();

        // Rejected by validation (no file), but each attempt is counted before it gets that far.
        $this->actingAs($user)->postJson(route('documents.store'), [])->assertStatus(422);
        $this->actingAs($user)->postJson(route('documents.store'), [])->assertStatus(422);
        $refused = $this->actingAs($user)->postJson(route('documents.store'), []);
        $refused->assertStatus(429);
        $this->assertStringContainsString('Too many new documents', $refused->json('message'));

        // A plain form post from one of the app's pages goes back there with the message.
        $form = $this->actingAs($user)->from(route('documents.index'))->post(route('documents.createBlank'), ['page_size' => 'A4']);
        $form->assertRedirect(route('documents.index'))->assertSessionHasErrors('rate_limit');
        $this->assertGreaterThan(0, (int) $form->headers->get('Retry-After'));

        $this->travel(2)->hours();
        $this->actingAs($user)->postJson(route('documents.store'), [])->assertStatus(429);
        $this->travel(23)->hours();
        $this->actingAs($user)->postJson(route('documents.store'), [])->assertStatus(422);
    }

    public function test_local_development_runs_far_above_the_production_numbers(): void
    {
        config(['editor_limits.scale' => 20, 'editor_limits.classes.write.per_minute' => 1]);
        $user = User::factory()->create();
        $document = $this->documentFor($user);

        foreach (range(1, 20) as $attempt) {
            $this->actingAs($user)->postJson(route('documents.rename', $document), ['name' => "Name {$attempt}"])->assertOk();
        }
        $this->actingAs($user)->postJson(route('documents.rename', $document), ['name' => 'Name 21'])->assertStatus(429);
    }

    private function documentFor(User $user): Document
    {
        return Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'limits.pdf',
            'path' => 'documents/limits_'.bin2hex(random_bytes(4)).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);
    }
}
