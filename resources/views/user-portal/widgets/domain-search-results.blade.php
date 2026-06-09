<div
    x-data="{
        rows: @js(array_values($rows ?? [])),
        search: '',
        page: 1,
        perPage: 10,
        get filteredRows() {
            const needle = this.search.trim().toLowerCase();

            if (!needle) {
                return this.rows;
            }

            return this.rows.filter((row) => {
                return [
                    row.domain || '',
                    this.statusLabel(row),
                    row.checked_at ? this.formatCheckedAt(row.checked_at) : '',
                ].join(' ').toLowerCase().includes(needle);
            });
        },
        get totalPages() {
            return Math.max(1, Math.ceil(this.filteredRows.length / this.perPage));
        },
        get pagedRows() {
            const start = (this.page - 1) * this.perPage;
            return this.filteredRows.slice(start, start + this.perPage);
        },
        get firstResult() {
            return this.filteredRows.length === 0 ? 0 : ((this.page - 1) * this.perPage) + 1;
        },
        get lastResult() {
            return Math.min(this.page * this.perPage, this.filteredRows.length);
        },
        next() {
            if (this.page < this.totalPages) {
                this.page++;
            }
        },
        prev() {
            if (this.page > 1) {
                this.page--;
            }
        },
        resetPage() {
            this.page = 1;
        },
        statusLabel(row) {
            if ((row.available === true || row.is_available === true) && !row.for_sale && !row.premium && !row.is_premium) {
                return 'Available';
            }

            if (row.for_sale || row.premium || row.is_premium) {
                return 'Premium';
            }

            if (row.available === false || row.is_available === false) {
                return 'Taken';
            }

            return 'Unknown';
        },
        statusClasses(row) {
            const status = this.statusLabel(row);

            if (status === 'Available') {
                return 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30';
            }

            if (status === 'Premium') {
                return 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30';
            }

            if (status === 'Taken') {
                return 'bg-gray-100 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-300 dark:ring-gray-400/20';
            }

            return 'bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20';
        },
        formatCheckedAt(value) {
            const date = new Date(value);

            if (Number.isNaN(date.getTime())) {
                return value;
            }

            return date.toLocaleString();
        },
    }"
    x-init="$watch('search', () => resetPage()); $watch('perPage', () => resetPage())"
    class="fi-ta-ctn overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
>
    <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-4 dark:border-white/10 sm:flex-row sm:items-center sm:justify-end">
        <label class="relative w-full sm:max-w-xs">
            <span class="sr-only">Search results</span>
            <x-filament::icon icon="heroicon-m-magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
            <input
                type="search"
                x-model.debounce.200ms="search"
                placeholder="Search"
                class="block w-full rounded-lg border-gray-300 bg-white py-2 pl-10 pr-3 text-sm text-gray-950 shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
            />
        </label>

    </div>

    <template x-if="rows.length === 0">
        <div class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
            No returned domains were stored for this request.
        </div>
    </template>

    <template x-if="rows.length > 0">
        <div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] divide-y divide-gray-200 text-start dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">Domain</th>
                            <th class="px-3 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">Status</th>
                            <th class="px-3 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">Checked At</th>
                            <th class="px-3 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/10">
                        <template x-for="row in pagedRows" :key="row.domain + '-' + (row.checked_at || '')">
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-4 py-4 text-sm font-semibold text-gray-950 dark:text-white" x-text="row.domain"></td>
                                <td class="px-3 py-4 text-sm">
                                    <span
                                        class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset"
                                        :class="statusClasses(row)"
                                        x-text="statusLabel(row)"
                                    ></span>
                                </td>
                                <td class="px-3 py-4 text-sm text-gray-700 dark:text-gray-300" x-text="row.checked_at ? formatCheckedAt(row.checked_at) : '-'"></td>
                                <td class="px-3 py-4 text-sm">
                                    <template x-if="row.available === true || row.is_available === true">
                                        <a
                                            :href="'https://www.namecheap.com/domains/registration/results/?domain=' + encodeURIComponent(row.domain || '')"
                                            target="_blank"
                                            class="font-semibold text-primary-600 transition hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                                        >
                                            Buy Now
                                        </a>
                                    </template>
                                    <template x-if="row.available !== true && row.is_available !== true">
                                        <span class="text-gray-400">-</span>
                                    </template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <template x-if="filteredRows.length === 0">
                <div class="border-t border-gray-200 px-6 py-12 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                    No domains match your search.
                </div>
            </template>

            <div class="flex flex-col gap-3 border-t border-gray-200 px-4 py-3 text-sm text-gray-700 dark:border-white/10 dark:text-gray-300 sm:flex-row sm:items-center sm:justify-between">
                <span x-text="'Showing ' + firstResult + ' to ' + lastResult + ' of ' + filteredRows.length + ' results'"></span>

                <div class="flex items-center justify-between gap-3 sm:justify-end">
                    <label class="flex items-center overflow-hidden rounded-lg border border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5">
                        <span class="border-r border-gray-300 px-3 py-2 text-gray-500 dark:border-white/10 dark:text-gray-400">Per page</span>
                        <select x-model.number="perPage" class="border-0 bg-transparent py-2 pl-3 pr-8 text-sm text-gray-950 focus:ring-0 dark:text-white">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </label>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10"
                            @click="prev()"
                            :disabled="page === 1"
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10"
                            @click="next()"
                            :disabled="page >= totalPages"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
