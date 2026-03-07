<x-filament-panels::page>
    <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Uploads ({{ $this->usageSummary['month'] }})</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">
                {{ $this->usageSummary['uploads_used'] }} / {{ $this->usageSummary['uploads_limit'] }}
            </div>
            <div class="mt-1 text-sm text-gray-600">
                Remaining: {{ $this->usageSummary['uploads_remaining'] }}
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Save / Split / Convert Actions</div>
            @if ($this->usageSummary['unlimited_actions'])
                <div class="mt-1 text-2xl font-bold text-emerald-700">Unlimited</div>
                <div class="mt-1 text-sm text-emerald-600">Active subscription: unlimited PDF actions</div>
            @else
                <div class="mt-1 text-2xl font-bold text-gray-900">
                    {{ $this->usageSummary['actions_used'] }} / {{ $this->usageSummary['actions_limit'] }}
                </div>
                <div class="mt-1 text-sm text-gray-600">
                    Remaining: {{ $this->usageSummary['actions_remaining'] }}
                </div>
            @endif
        </div>
    </div>

    @livewire(\App\UserPortal\Widgets\UserUploadedPdfsWidget::class)

    <div class="mt-6">
        @livewire(\App\UserPortal\Widgets\UserPdfCommandsWidget::class)
    </div>
</x-filament-panels::page>
