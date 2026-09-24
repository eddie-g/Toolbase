<?php

namespace App\UserPortal\Support;

use App\Models\AiDomainRequest;
use App\Models\AiLogoPrice;
use App\Models\AiLogoRequest;
use App\Models\CreditTransaction;
use App\Models\Document;
use App\Models\PdfExport;
use App\Models\SavedDomain;
use App\Models\User;
use App\Models\UserActivity;
use App\UserPortal\Pages\AddCredits;
use App\UserPortal\Pages\Domains;
use App\UserPortal\Pages\ImageGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One newest-first feed of what a portal user has done, merged from the
 * tables each tool writes to. PDF downloads live in pdf_exports because the
 * queued export path never logs to user_activities; logo-generation debits are
 * left out because the generation itself is already in the feed.
 *
 * Each source is asked for at most $limit rows, so merging and cutting to
 * $limit is exact without paging through every table.
 */
class RecentActivity
{
    public const KINDS = ['pdf', 'images', 'domains', 'credits'];

    /** Credit debits for image tools that leave no row of their own. */
    private const IMAGE_SERVICES = [
        'logo_bg_removal' => 'Removed an image background',
        'image_upscale' => 'Upscaled an image',
    ];

    private const PDF_ACTIONS = [
        'pdf_save' => 'heroicon-o-document-check',
        'word_export' => 'heroicon-o-document-text',
        'excel_export' => 'heroicon-o-table-cells',
        'pdfa_export' => 'heroicon-o-archive-box',
        'split_export' => 'heroicon-o-scissors',
        'pdf_merge' => 'heroicon-o-square-2-stack',
        'pdf_password' => 'heroicon-o-lock-closed',
        'image_export' => 'heroicon-o-photo',
    ];

    public function __construct(private readonly User $user)
    {
    }

    /**
     * @return Collection<int, array{kind: string, icon: string, title: string, detail: ?string, failed: bool, at: Carbon, url: ?string, thumb: ?string, cost: ?float, credit: bool}>
     */
    public function items(string $kind = 'all', int $limit = 20): Collection
    {
        $sources = [
            'pdf' => [$this->uploads(...), $this->pdfActions(...), $this->downloads(...)],
            'images' => [$this->images(...), $this->imageTools(...)],
            'domains' => [$this->domainSearches(...), $this->savedDomains(...)],
            'credits' => [$this->credits(...)],
        ];

        $wanted = $kind === 'all' ? $sources : array_intersect_key($sources, [$kind => true]);
        $tz = $this->user->displayTimezone();

        return collect($wanted)
            ->flatten()
            ->flatMap(fn (callable $source) => $source($limit))
            ->sortByDesc(fn (array $item) => $item['at']->getTimestamp())
            ->take($limit)
            ->map(function (array $item) use ($tz) {
                $item['at'] = $item['at']->copy()->timezone($tz);

                return $item;
            })
            ->values();
    }

    /**
     * Headline numbers for the stat tiles, over the last 30 days.
     *
     * @return array{balance: float, spent7: float, pdf30: int, images30: int, domains30: int, activity30: int}
     */
    public function stats(): array
    {
        $since = now()->subDays(30);
        $userId = $this->user->id;

        $pdfCount = Document::query()->where('user_id', $userId)->where('created_at', '>=', $since)->count()
            + UserActivity::query()->where('user_id', $userId)->where('created_at', '>=', $since)->count()
            + PdfExport::query()->where('user_id', $userId)->where('created_at', '>=', $since)->count();

        $stats = [
            'balance' => (float) $this->user->credit_balance,
            'spent7' => (float) CreditTransaction::query()
                ->where('user_id', $userId)
                ->where('type', 'debit')
                ->where('created_at', '>=', now()->subDays(7))
                ->sum('amount'),
            'pdf30' => $pdfCount,
            'images30' => AiLogoRequest::query()
                ->where('user_id', $userId)
                ->where('status', 'completed')
                ->where('created_at', '>=', $since)
                ->count(),
            'domains30' => AiDomainRequest::query()
                ->where('user_id', $userId)
                ->where('created_at', '>=', $since)
                ->count(),
        ];
        $stats['activity30'] = $stats['pdf30'] + $stats['images30'] + $stats['domains30'];

        return $stats;
    }

    private function uploads(int $limit): Collection
    {
        return Document::query()
            ->where('user_id', $this->user->id)
            ->latest()
            ->limit($limit)
            ->get(['id', 'original_name', 'mode', 'size_bytes', 'created_at'])
            ->map(fn (Document $doc) => $this->item(
                kind: 'pdf',
                icon: 'heroicon-o-arrow-up-tray',
                title: 'Uploaded a PDF',
                detail: $this->joinDetail([$doc->original_name, $doc->size_bytes ? number_format($doc->size_bytes / 1024, 1) . ' KB' : null]),
                at: $doc->created_at,
                url: $this->documentUrl($doc),
            ));
    }

    private function pdfActions(int $limit): Collection
    {
        $activities = UserActivity::query()
            ->with('document:id,original_name,mode')
            ->where('user_id', $this->user->id)
            ->latest()
            ->limit($limit)
            ->get();
        // Word / Excel conversions are paid: show what was debited.
        $charges = UserActivity::chargesFor($activities);

        return $activities
            ->map(fn (UserActivity $activity) => $this->item(
                kind: 'pdf',
                icon: self::PDF_ACTIONS[$activity->category] ?? 'heroicon-o-document',
                title: (string) $activity->action,
                detail: $activity->document?->original_name,
                at: $activity->created_at,
                url: $activity->document ? $this->documentUrl($activity->document) : null,
                failed: $activity->status !== 'success',
                cost: $charges[$activity->id] ?? null,
            ));
    }

    private function downloads(int $limit): Collection
    {
        return PdfExport::query()
            ->with('document:id,original_name,mode')
            ->where('user_id', $this->user->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (PdfExport $export) => $this->item(
                kind: 'pdf',
                icon: 'heroicon-o-arrow-down-tray',
                title: match ($export->status) {
                    PdfExport::STATUS_FAILED => 'PDF download failed',
                    PdfExport::STATUS_COMPLETED => 'Downloaded a PDF',
                    default => 'Preparing a PDF download',
                },
                detail: $export->download_name ?: $export->document?->original_name,
                at: $export->created_at,
                url: $export->document ? $this->documentUrl($export->document) : null,
                failed: $export->status === PdfExport::STATUS_FAILED,
            ));
    }

    private function images(int $limit): Collection
    {
        // What the generation cost; failed and refunded ones cost nothing.
        $price = AiLogoPrice::query()
            ->selectRaw('COALESCE(actual_cost_usd, estimated_cost_usd)')
            ->whereColumn('ai_logo_request_id', 'ai_logo_requests.id')
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->limit(1);

        return AiLogoRequest::query()
            ->select(['id', 'domain', 'style', 'model', 'original_prompt', 'prompt', 'status', 'image_urls', 'image_meta', 'created_at'])
            ->selectSub($price, 'price_usd')
            ->where('user_id', $this->user->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function (AiLogoRequest $request) {
                // Images deleted for good have no file (and a 404 preview) left.
                $urls = array_filter(
                    is_array($request->image_urls) ? array_values($request->image_urls) : [],
                    fn ($url, $index) => is_string($url) && $url !== '' && $url !== '[base64-omitted]' && !$request->isImagePurged($index),
                    ARRAY_FILTER_USE_BOTH,
                );
                $completed = $request->status === 'completed';
                $count = count($urls);

                return $this->item(
                    kind: 'images',
                    icon: 'heroicon-o-sparkles',
                    title: match (true) {
                        $completed && $count > 1 => "Generated {$count} images",
                        $completed => 'Generated an image',
                        $request->status === 'pending' => 'Generating an image',
                        default => 'Image generation failed',
                    },
                    detail: $this->joinDetail([
                        $request->domain ?: Str::limit((string) ($request->original_prompt ?: $request->prompt), 70),
                        $request->style ? Str::headline($request->style) : null,
                        ImageGenerator::modelLabel($request->model),
                    ]),
                    at: $request->created_at,
                    url: ImageGenerator::getUrl(panel: 'user'),
                    failed: in_array($request->status, ['error', 'failed'], true),
                    cost: $completed && $request->price_usd !== null ? (float) $request->price_usd : null,
                    thumb: $completed && $count > 0
                        ? route('generatedImages.preview', ['logoRequest' => $request->id, 'index' => array_key_first($urls), 'v' => substr(md5(reset($urls)), 0, 8)])
                        : null,
                );
            });
    }

    private function imageTools(int $limit): Collection
    {
        return CreditTransaction::query()
            ->where('user_id', $this->user->id)
            ->where('type', 'debit')
            ->whereIn('service', array_keys(self::IMAGE_SERVICES))
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (CreditTransaction $tx) => $this->item(
                kind: 'images',
                icon: $tx->service === 'image_upscale' ? 'heroicon-o-arrows-pointing-out' : 'heroicon-o-scissors',
                title: self::IMAGE_SERVICES[$tx->service],
                detail: null,
                at: $tx->created_at,
                url: ImageGenerator::getUrl(panel: 'user'),
                cost: (float) $tx->amount,
            ));
    }

    private function domainSearches(int $limit): Collection
    {
        return AiDomainRequest::query()
            ->where('user_id', $this->user->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function (AiDomainRequest $request) {
                $results = $this->domainResultCount($request);

                return $this->item(
                    kind: 'domains',
                    icon: 'heroicon-o-magnifying-glass',
                    title: 'Searched for domains',
                    detail: $this->joinDetail([
                        $results === 1 ? '1 result' : "{$results} results",
                        '"' . Str::limit((string) $request->prompt, 70) . '"',
                    ]),
                    at: $request->created_at,
                    url: Domains::getUrl(['search' => $request->id], panel: 'user'),
                );
            });
    }

    private function savedDomains(int $limit): Collection
    {
        return SavedDomain::query()
            ->where('user_id', $this->user->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (SavedDomain $domain) => $this->item(
                kind: 'domains',
                icon: 'heroicon-o-star',
                title: 'Favourited a domain',
                detail: $this->joinDetail([
                    $domain->domain,
                    $domain->is_available === null ? null : ($domain->is_available ? 'available' : 'taken'),
                ]),
                at: $domain->created_at,
                url: Domains::getUrl(panel: 'user'),
            ));
    }

    private function credits(int $limit): Collection
    {
        return CreditTransaction::query()
            ->where('user_id', $this->user->id)
            ->where('type', '!=', 'debit')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (CreditTransaction $tx) => $this->item(
                kind: 'credits',
                icon: $tx->type === 'refund' ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-banknotes',
                title: match ($tx->type) {
                    'topup' => 'Added credits',
                    'refund' => 'Credits refunded',
                    default => 'Credits granted',
                },
                detail: 'Balance after: $' . number_format((float) $tx->balance_after, 2),
                at: $tx->created_at,
                url: AddCredits::getUrl(panel: 'user'),
                cost: (float) $tx->amount,
                credit: true,
            ));
    }

    /**
     * $cost is what the action cost (or, with $credit, what it added); null
     * for actions that are free, and the row then shows no amount.
     */
    private function item(string $kind, string $icon, string $title, ?string $detail, ?Carbon $at, ?string $url, bool $failed = false, ?string $thumb = null, ?float $cost = null, bool $credit = false): array
    {
        return [
            'cost' => $cost,
            'credit' => $credit,
            'kind' => $kind,
            'icon' => $icon,
            'title' => $title,
            'detail' => $detail,
            'failed' => $failed,
            'at' => $at ?? now(),
            'url' => $url,
            'thumb' => $thumb,
        ];
    }

    private function joinDetail(array $parts): ?string
    {
        $parts = array_filter($parts, fn ($part) => $part !== null && $part !== '');

        return $parts ? implode(' · ', $parts) : null;
    }

    private function documentUrl(Document $document): string
    {
        return $document->mode === 'guided'
            ? route('documents.guided', $document)
            : route('documents.editNew', [
                'document' => $document,
                'pdfjs' => 1,
                'from' => 'admin',
                'return_to' => route('filament.user.pages.pdf-generator'),
            ]);
    }

    private function domainResultCount(AiDomainRequest $request): int
    {
        $data = $request->result_data;
        if (is_string($data) && $data !== '') {
            $data = json_decode($data, true);
        }
        if (is_array($data) && is_array($data['results'] ?? null)) {
            return count(array_filter($data['results'], fn ($row) => is_array($row) && !empty($row['domain'])));
        }

        $domains = is_array($request->response) ? ($request->response['domains'] ?? null) : null;

        return is_array($domains) ? count(array_filter($domains)) : 0;
    }
}
