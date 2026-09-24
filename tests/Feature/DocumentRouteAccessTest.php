<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every route that takes a {document} refuses a document that is not the
 * visitor's, before it does anything else. The routes are read from the
 * router, so a route added tomorrow is covered without touching this test.
 */
class DocumentRouteAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Refused means: not found, not allowed, or sent to sign in. Never a 2xx, never a validation or server error. */
    private const REFUSED = [401, 403, 404, 405];

    public function test_no_document_route_serves_another_accounts_document(): void
    {
        Queue::fake();
        config(['editor_limits.scale' => 100000]);
        $owner = User::factory()->create();
        $document = Document::query()->create([
            'user_id' => $owner->id,
            'original_name' => 'private.pdf',
            'path' => 'documents/private.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
        ]);
        $stranger = User::factory()->create();

        $routes = $this->documentRoutes();
        $this->assertGreaterThan(50, count($routes), 'the editor has far more than fifty document routes');

        $served = [];
        foreach ($routes as $route) {
            foreach (['another account' => $stranger, 'a guest' => null] as $who => $user) {
                $this->flushSession();
                $this->app['auth']->forgetGuards();
                $request = $user ? $this->actingAs($user) : $this;
                $method = collect($route->methods())->first(fn ($verb) => $verb !== 'HEAD');
                $response = $request->json($method, $this->urlFor($route, $document));

                $status = $response->getStatusCode();
                $toLogin = $status === 302 && str_contains((string) $response->headers->get('Location'), 'login');
                if (! in_array($status, self::REFUSED, true) && ! $toLogin) {
                    $served[] = sprintf('%s %s as %s: %d', $method, $route->uri(), $who, $status);
                }
            }
        }

        $this->assertSame([], $served, 'These routes answered for a document that is not the visitor\'s');
        $this->assertSame('private.pdf', $document->fresh()->original_name);
        $this->assertNull($document->fresh()->deleted_at);
    }

    public function test_every_route_points_at_an_action_that_exists(): void
    {
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            if (! method_exists($class, $method)) {
                $missing[] = $route->uri().' -> '.$action;
            }
        }

        $this->assertSame([], $missing, 'A request to these routes can only end in a 500');
    }

    /** @return RoutingRoute[] */
    private function documentRoutes(): array
    {
        return array_values(array_filter(
            iterator_to_array(Route::getRoutes()),
            static fn (RoutingRoute $route) => in_array('document', $route->parameterNames(), true)
        ));
    }

    private function urlFor(RoutingRoute $route, Document $document): string
    {
        $parameters = [];
        foreach ($route->parameterNames() as $name) {
            $parameters[$name] = $name === 'document' ? $document->id : 1;
        }

        return '/'.ltrim(preg_replace_callback('/\{(\w+)\??\}/', static fn ($match) => (string) $parameters[$match[1]], $route->uri()), '/');
    }
}
