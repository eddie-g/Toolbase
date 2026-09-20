<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The documents of a visitor without an account. The visitor is a random
 * token in an encrypted cookie; guest_documents maps its hash to document
 * ids. Bounded (the most recent max_documents) and with a lifetime of its own
 * (lifetime_days since the visitor was last seen), where the old list of ids
 * in the session payload was neither.
 *
 * What it learns during a request (the token issued for a first upload,
 * before the cookie has gone out; the list of ids) is kept per Request object,
 * so nothing carries over to the next request even if the instance does.
 */
class GuestDocuments
{
    public const COOKIE = 'netkit_guest';

    /** @var \WeakMap<Request, object{token: ?string, ids: ?array}> */
    private \WeakMap $perRequest;

    public function __construct()
    {
        $this->perRequest = new \WeakMap();
    }

    private function state(Request $request): object
    {
        return $this->perRequest[$request] ??= (object) ['token' => null, 'ids' => null];
    }

    public function lifetimeDays(): int
    {
        return max(1, (int) config('pdf_editor.guests.lifetime_days', 7));
    }

    /** @return int[] ids this visitor may open, newest first */
    public function ids(Request $request): array
    {
        $state = $this->state($request);
        if ($state->ids !== null) {
            return $state->ids;
        }
        $token = $this->token($request);
        if ($token === null) {
            return $state->ids = [];
        }

        $rows = DB::table('guest_documents')
            ->where('guest_token_hash', $this->hash($token))
            ->where('last_seen_at', '>=', now()->subDays($this->lifetimeDays()))
            ->orderByDesc('id')
            ->limit($this->maxDocuments())
            ->get(['document_id', 'last_seen_at']);

        // Seen again: push the lifetime out, at most once an hour so reading
        // a page does not become a write on every request.
        $stale = $rows->first(fn ($row) => $row->last_seen_at < now()->subHour()->toDateTimeString());
        if ($stale !== null) {
            DB::table('guest_documents')->where('guest_token_hash', $this->hash($token))->update(['last_seen_at' => now()]);
            $this->queueCookie($token);
        }

        return $state->ids = $rows->pluck('document_id')->map(static fn ($id) => (int) $id)->all();
    }

    public function remember(Request $request, int $documentId): void
    {
        if ($documentId <= 0) {
            return;
        }
        $token = $this->token($request) ?? $this->issueToken($request);
        $hash = $this->hash($token);

        DB::table('guest_documents')->updateOrInsert(
            ['guest_token_hash' => $hash, 'document_id' => $documentId],
            ['last_seen_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );

        // Keep the most recent max_documents. What falls off is no longer
        // reachable and goes with the next documents:prune-guests.
        $keep = DB::table('guest_documents')->where('guest_token_hash', $hash)
            ->orderByDesc('id')->limit($this->maxDocuments())->pluck('id');
        DB::table('guest_documents')->where('guest_token_hash', $hash)->whereNotIn('id', $keep)->delete();

        $this->queueCookie($token);
        $this->state($request)->ids = null;
    }

    /** The visitor signed in and the account took these documents over. */
    public function forget(Request $request, array $documentIds): void
    {
        $token = $this->token($request);
        if ($token === null || $documentIds === []) {
            return;
        }
        DB::table('guest_documents')->where('guest_token_hash', $this->hash($token))->whereIn('document_id', $documentIds)->delete();
        $this->state($request)->ids = null;
    }

    private function token(Request $request): ?string
    {
        $state = $this->state($request);
        if ($state->token !== null) {
            return $state->token;
        }
        $cookie = $request->cookie(self::COOKIE);

        return is_string($cookie) && preg_match('/^[A-Za-z0-9]{40}$/', $cookie) ? ($state->token = $cookie) : null;
    }

    private function issueToken(Request $request): string
    {
        return $this->state($request)->token = Str::random(40);
    }

    private function queueCookie(string $token): void
    {
        Cookie::queue(Cookie::make(
            self::COOKIE,
            $token,
            $this->lifetimeDays() * 24 * 60,
            '/',
            config('session.domain'),
            config('session.secure'),
            true,
            false,
            config('session.same_site', 'lax')
        ));
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function maxDocuments(): int
    {
        return max(1, (int) config('pdf_editor.guests.max_documents', 50));
    }
}
