<x-filament-panels::page>
    <section class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 dark:border-emerald-800/60 dark:bg-emerald-950/40">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-base font-semibold text-emerald-950 dark:text-emerald-100">Create or edit a PDF</h2>
                <p class="mt-1 text-sm text-emerald-800 dark:text-emerald-200">Open the PDF generator workspace to upload, create, edit, split, and convert documents.</p>
            </div>
            <a
                href="{{ route('documents.index') }}"
                class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700"
            >
                Open PDF Generator
            </a>
        </div>
    </section>

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
