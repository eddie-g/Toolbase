<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total Requests</p>
                <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($totalCount) }}</p>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Completed</p>
                <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($completedCount) }}</p>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Estimated Cost</p>
                <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">${{ number_format($estimatedCost, 6) }}</p>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Input Tokens</p>
                <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($inputTokens) }}</p>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Output Tokens</p>
                <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($outputTokens) }}</p>
            </div>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
