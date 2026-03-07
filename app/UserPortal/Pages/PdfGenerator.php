<?php

namespace App\UserPortal\Pages;

use App\Models\UserActivity;
use App\Models\UserPdfMonthlyUsage;
use Filament\Pages\Page;

class PdfGenerator extends Page
{
    protected static ?string $title = 'PDF Generator';

    protected static ?string $navigationLabel = 'PDF Generator';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'user-portal.pages.pdf-generator';

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
                $q->whereIn('category', ['pdf_save', 'pdfa_export', 'word_export', 'excel_export', 'split_export', 'image_export'])
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
