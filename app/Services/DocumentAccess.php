<?php

namespace App\Services;

use App\Models\AiDocument;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may open a document. The single place for the rule, so every
 * controller that takes a document id from a request body or query enforces
 * the same thing DocumentController's route binding does:
 *
 *  - a document with an owner is only open to that user (web guard) or
 *    that admin (admin guard);
 *  - an unowned document belongs to the browser session that created it,
 *    remembered as a list of ids in the session; signing in claims those
 *    documents for the account.
 *
 * Bound as a scoped singleton so the claim runs once per request.
 */
class DocumentAccess
{
    public const SESSION_KEY = 'pdf_editor_accessible_document_ids';

    private bool $claimed = false;

    public function webUserId(): ?int
    {
        $id = Auth::guard('web')->id();

        return $id !== null ? (int) $id : null;
    }

    public function adminId(): ?int
    {
        $id = Auth::guard('admin')->id();

        return $id !== null ? (int) $id : null;
    }

    /** @return array{user_id: int|null, admin_id: int|null} */
    public function ownership(): array
    {
        if (($webUserId = $this->webUserId()) !== null) {
            return ['user_id' => $webUserId, 'admin_id' => null];
        }
        if (($adminId = $this->adminId()) !== null) {
            return ['user_id' => null, 'admin_id' => $adminId];
        }

        return ['user_id' => null, 'admin_id' => null];
    }

    public function hasPersistentOwner(Document $document): bool
    {
        return $document->user_id !== null || $document->admin_id !== null;
    }

    /** @return int[] */
    public function sessionDocumentIds(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        return collect($request->session()->get(self::SESSION_KEY, []))
            ->map(static fn ($value) => (int) $value)
            ->filter(static fn (int $value) => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function remember(Request $request, Document $document): void
    {
        if ($document->id <= 0 || $this->hasPersistentOwner($document) || ! $request->hasSession()) {
            return;
        }

        $ids = collect($this->sessionDocumentIds($request))
            ->push((int) $document->id)
            ->unique()
            ->values()
            ->all();

        $request->session()->put(self::SESSION_KEY, $ids);
    }

    /**
     * Signing in takes over the unowned documents this session created,
     * along with their saved state and form values.
     */
    public function claim(Request $request): void
    {
        if ($this->claimed) {
            return;
        }
        $this->claimed = true;

        $ownership = $this->ownership();
        if ($ownership['user_id'] === null && $ownership['admin_id'] === null) {
            return;
        }

        $sessionIds = $this->sessionDocumentIds($request);
        if (empty($sessionIds)) {
            return;
        }

        $unowned = Document::query()
            ->whereIn('id', $sessionIds)
            ->whereNull('user_id')
            ->whereNull('admin_id')
            ->pluck('id')
            ->map(static fn ($value) => (int) $value)
            ->all();
        if (empty($unowned)) {
            return;
        }

        DB::table('documents')->whereIn('id', $unowned)->update($ownership);

        if (Schema::hasColumn('pdf_state', 'admin_id')) {
            DB::table('pdf_state')
                ->whereIn('document_id', $unowned)
                ->whereNull('user_id')
                ->whereNull('admin_id')
                ->update(array_merge($ownership, ['user_email' => null]));
        }

        if (Schema::hasColumn('pdf_acro_form', 'admin_id')) {
            DB::table('pdf_acro_form')
                ->whereIn('document_id', $unowned)
                ->whereNull('user_id')
                ->whereNull('admin_id')
                ->update($ownership);
        }
    }

    public function canAccess(Request $request, Document $document): bool
    {
        $this->claim($request);

        $webUserId = $this->webUserId();
        if ($webUserId !== null && (int) $document->user_id === $webUserId) {
            return true;
        }

        $adminId = $this->adminId();
        if ($adminId !== null && (int) $document->admin_id === $adminId) {
            return true;
        }

        if (! $this->hasPersistentOwner($document)) {
            return in_array((int) $document->id, $this->sessionDocumentIds($request), true);
        }

        return false;
    }

    /** 404, never 403: an outsider must not learn that the id exists. */
    public function authorize(Request $request, Document $document): void
    {
        abort_unless($this->canAccess($request, $document), 404);
    }

    /** The document behind an id from a request body or query, or 404. */
    public function authorizeId(Request $request, int|string|null $id): Document
    {
        $document = is_numeric($id) ? Document::query()->find((int) $id) : null;
        abort_unless($document instanceof Document, 404);
        $this->authorize($request, $document);

        return $document;
    }

    /**
     * The AI editor addresses documents by an AiDocument id or a plain
     * document id. When the identifier names a document, the visitor must
     * be allowed to open it; identifiers that name nothing stored are left
     * to the caller's own session scoping.
     */
    public function authorizeIdentifier(Request $request, ?string $identifier): ?Document
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return null;
        }

        $aiDocument = AiDocument::with('document')->find($identifier);
        if ($aiDocument instanceof AiDocument && $aiDocument->document instanceof Document) {
            $this->authorize($request, $aiDocument->document);

            return $aiDocument->document;
        }

        if (is_numeric($identifier)) {
            $document = Document::query()->find((int) $identifier);
            if ($document instanceof Document) {
                $this->authorize($request, $document);

                return $document;
            }
        }

        return null;
    }

    /** Restricts a documents query to what the visitor may open. */
    public function scope(Request $request, $query)
    {
        $this->claim($request);

        $webUserId = $this->webUserId();
        $adminId = $this->adminId();
        $sessionIds = $this->sessionDocumentIds($request);

        $query->where(function ($scoped) use ($webUserId, $adminId, $sessionIds) {
            if ($webUserId !== null) {
                $scoped->where('user_id', $webUserId);
            }
            if ($adminId !== null) {
                $scoped->{$webUserId !== null ? 'orWhere' : 'where'}('admin_id', $adminId);
            }
            if (! empty($sessionIds)) {
                $method = ($webUserId !== null || $adminId !== null) ? 'orWhere' : 'where';
                $scoped->{$method}(function ($sessionQuery) use ($sessionIds) {
                    $sessionQuery->whereNull('user_id')->whereNull('admin_id')->whereIn('id', $sessionIds);
                });
            }
            if ($webUserId === null && $adminId === null && empty($sessionIds)) {
                $scoped->whereRaw('1 = 0');
            }
        });

        return $query;
    }
}
