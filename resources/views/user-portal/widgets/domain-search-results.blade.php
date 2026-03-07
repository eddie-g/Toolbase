<div
    x-data="{
        rows: @js(array_values($rows ?? [])),
        page: 1,
        perPage: 10,
        get totalPages() { return Math.max(1, Math.ceil(this.rows.length / this.perPage)); },
        get pagedRows() {
            const start = (this.page - 1) * this.perPage;
            return this.rows.slice(start, start + this.perPage);
        },
        next() { if (this.page < this.totalPages) this.page++; },
        prev() { if (this.page > 1) this.page--; },
        statusLabel(row) {
            if ((row.available === true) && !row.for_sale) return 'Available';
            if (row.for_sale) return 'Premium';
            if (row.available === false) return 'Taken';
            return 'Unknown';
        }
    }"
    class="space-y-3"
>
    <template x-if="rows.length === 0">
        <p class="text-sm text-gray-500">No returned domains were stored for this request.</p>
    </template>

    <template x-if="rows.length > 0">
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="max-h-[420px] overflow-auto">
            <table class="min-w-full text-sm">
                <thead class="sticky top-0 z-10 bg-slate-900 text-slate-100">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold tracking-wide">Domain</th>
                        <th class="px-4 py-3 text-left font-semibold tracking-wide">Status</th>
                        <th class="px-4 py-3 text-left font-semibold tracking-wide">Checked At</th>
                        <th class="px-4 py-3 text-left font-semibold tracking-wide">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="row in pagedRows" :key="row.domain + '-' + (row.checked_at || '')">
                        <tr class="transition hover:bg-slate-50/80">
                            <td class="px-4 py-3 font-semibold text-slate-900" x-text="row.domain"></td>
                            <td class="px-4 py-3 text-slate-700">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="{
                                        'bg-emerald-100 text-emerald-700': statusLabel(row) === 'Available',
                                        'bg-amber-100 text-amber-700': statusLabel(row) === 'Premium',
                                        'bg-slate-200 text-slate-700': statusLabel(row) === 'Taken',
                                        'bg-slate-100 text-slate-600': statusLabel(row) === 'Unknown'
                                    }"
                                    x-text="statusLabel(row)"
                                ></span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <span x-text="row.checked_at ? new Date(row.checked_at).toLocaleString() : '—'"></span>
                            </td>
                            <td class="px-4 py-3">
                                <template x-if="row.available === true">
                                    <a
                                        :href="'https://www.namecheap.com/domains/registration/results/?domain=' + encodeURIComponent(row.domain || '')"
                                        target="_blank"
                                        class="inline-flex items-center rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-700"
                                    >
                                        Buy Now
                                    </a>
                                </template>
                                <template x-if="row.available !== true">
                                    <span class="text-xs text-slate-400">—</span>
                                </template>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            </div>
        </div>
    </template>

    <template x-if="rows.length > perPage">
        <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
            <span x-text="'Page ' + page + ' of ' + totalPages"></span>
            <div class="flex items-center gap-2">
                <button type="button" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 font-medium disabled:opacity-40" @click="prev()" :disabled="page === 1">Prev</button>
                <button type="button" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 font-medium disabled:opacity-40" @click="next()" :disabled="page >= totalPages">Next</button>
            </div>
        </div>
    </template>
</div>
