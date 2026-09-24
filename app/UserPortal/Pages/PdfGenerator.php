<?php

namespace App\UserPortal\Pages;

use App\Models\Document;
use App\Models\UserActivity;
use App\Models\UserPdfMonthlyUsage;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * The account's PDFs: this month's usage, the documents (cards or list,
 * searchable) and the history of editor actions. Styled with the portal's
 * nk-* classes (user-portal.css).
 */
class PdfGenerator extends Page
{
    use WithPagination;

    protected static ?string $title = 'PDF editor';

    protected static ?string $navigationLabel = 'PDF editor';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'user-portal.pages.pdf-generator';

    /** What the page calls a save, split, conversion or export: one editor action. */
    private const EDITOR_ACTION_CATEGORIES = ['pdf_save', 'pdfa_export', 'word_export', 'excel_export', 'split_export', 'image_export'];

    public string $viewMode = 'cards';

    #[Url(as: 'q', except: '')]
    public string $term = '';

    public function setViewMode(string $mode): void
    {
        $this->viewMode = $mode === 'list' ? 'list' : 'cards';
    }

    public function updatedTerm(): void
    {
        $this->resetPage('docs');
    }

    public function openUrl(Document $document): string
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

    public function deleteDocument(int $documentId): void
    {
        $user = auth()->user();
        $record = $user ? Document::query()->find($documentId) : null;
        if (!$record) {
            return;
        }

        $canDelete = ((int) ($record->user_id ?? 0) === (int) $user->id)
            || UserActivity::query()
                ->where('user_id', $user->id)
                ->where('document_id', $record->id)
                ->exists();

        if (!$canDelete) {
            Notification::make()->title('You are not allowed to delete this PDF')->danger()->send();

            return;
        }

        DB::table('pdf_extractions_fitz')->where('document_id', $record->id)->delete();

        if ($record->path) {
            Storage::delete($record->path);
        }
        if ($record->original_backup_path) {
            Storage::delete($record->original_backup_path);
        }

        $record->delete();

        Notification::make()->title('PDF deleted')->success()->send();
    }

    public function getDocumentsProperty(): LengthAwarePaginator
    {
        return $this->documentsQuery()
            ->when($this->term !== '', fn (Builder $q) => $q->where('original_name', 'like', '%' . $this->term . '%'))
            ->latest('updated_at')
            ->paginate(12, pageName: 'docs');
    }

    public function getHistoryProperty(): LengthAwarePaginator
    {
        return UserActivity::query()
            ->with('document:id,original_name,mode')
            ->where('user_id', auth()->id())
            ->latest()
            ->paginate(10, pageName: 'history');
    }

    /** Documents the account owns, or has worked on (older rows had no owner). */
    private function documentsQuery(): Builder
    {
        $userId = auth()->id();

        $workedOn = UserActivity::query()
            ->where('user_id', $userId)
            ->whereNotNull('document_id')
            ->select('document_id');

        return Document::query()
            ->where(fn (Builder $q) => $q->where('user_id', $userId)->orWhereIn('id', $workedOn));
    }

    public function getUsageSummaryProperty(): array
    {
        $user = auth()->user();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthStartDt = now()->startOfMonth();

        $usage = UserPdfMonthlyUsage::query()
            ->where('user_id', $user?->id)
            ->where('month_start', $monthStart)
            ->first();

        $uploadsUsed = (int) ($usage?->uploads_count ?? 0);
        $actionsUsed = (int) ($usage?->actions_count ?? 0);
        $unlimitedActions = (bool) ($user?->hasActiveSubscription('pdf-editor') ?? false);

        // Keep stored counter aligned with command history shown on this page.
        $activityCount = UserActivity::query()
            ->where('user_id', $user?->id)
            ->where('status', 'success')
            ->where('created_at', '>=', $monthStartDt)
            ->where(function ($q) {
                $q->whereIn('category', self::EDITOR_ACTION_CATEGORIES)
                    ->orWhere('action', 'like', '%save%')
                    ->orWhere('action', 'like', '%split%')
                    ->orWhere('action', 'like', '%convert%')
                    ->orWhere('action', 'like', 'Export to%');
            })
            ->count();

        if ($activityCount > $actionsUsed && $usage) {
            $usage->actions_count = $activityCount;
            $usage->has_unlimited_actions = $unlimitedActions;
            $usage->save();
            $actionsUsed = $activityCount;
        }

        return [
            'month' => now()->format('F Y'),
            'uploads_used' => $uploadsUsed,
            'uploads_limit' => 100,
            'uploads_remaining' => max(0, 100 - $uploadsUsed),
            'actions_used' => $actionsUsed,
            'actions_limit' => 1000,
            'actions_remaining' => $unlimitedActions ? null : max(0, 1000 - $actionsUsed),
            'unlimited_actions' => $unlimitedActions,
        ];
    }
}
