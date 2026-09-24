<x-filament-panels::page>
    {{-- Styles: resources/css/user-portal.css (nk-* classes, "Domains" block). --}}
    @php
        $tz = auth()->user()?->displayTimezone() ?? config('app.timezone');
        $selectedSearch = $this->selectedSearchRecord();
        $statusLabels = ['available' => 'Available', 'premium' => 'Premium', 'taken' => 'Taken', 'unknown' => 'Not checked'];
        $buyUrl = fn (string $domain) => 'https://www.namecheap.com/domains/registration/results/?domain=' . urlencode($domain);
    @endphp

    @if ($selectedSearch)
        {{-- One search's results --}}
        @php
            $rows = collect($this->resultRowsFor($selectedSearch))->map(function (array $row) use ($tz, $statusLabels) {
                $status = \App\UserPortal\Pages\Domains::statusOf($row);
                $checked = !empty($row['checked_at']) ? \Illuminate\Support\Carbon::parse($row['checked_at'])->timezone($tz) : null;

                return [
                    'domain' => strtolower((string) $row['domain']),
                    'status' => $status,
                    'label' => $statusLabels[$status],
                    'checked' => $checked?->format('M j, Y g:i a'),
                ];
            })->values();
            $counts = $rows->countBy('status');
        @endphp

        <div class="nk-page"
            x-data="{
                rows: @js($rows),
                filter: 'all',
                search: '',
                page: 1,
                perPage: 25,
                get filtered() {
                    const needle = this.search.trim().toLowerCase();
                    return this.rows.filter((row) => (this.filter === 'all' || row.status === this.filter)
                        && (!needle || row.domain.includes(needle)));
                },
                get pages() { return Math.max(1, Math.ceil(this.filtered.length / this.perPage)); },
                get paged() { return this.filtered.slice((this.page - 1) * this.perPage, this.page * this.perPage); },
                get first() { return this.filtered.length ? (this.page - 1) * this.perPage + 1 : 0; },
                get last() { return Math.min(this.page * this.perPage, this.filtered.length); },
            }"
            x-init="$watch('search', () => page = 1); $watch('filter', () => page = 1); $watch('perPage', () => page = 1)"
        >
            <section class="nk-card">
                <a href="{{ \App\UserPortal\Pages\Domains::getUrl(panel: 'user') . '?tab=searches' }}" class="nk-back">
                    <x-filament::icon icon="heroicon-m-arrow-left" />
                    Recent searches
                </a>
                <h3 class="nk-heading nk-mt-2">“{{ $selectedSearch->prompt }}”</h3>
                <p class="nk-muted nk-mt-1">
                    Searched {{ $selectedSearch->created_at?->copy()->timezone($tz)->format('M j, Y g:i a') }}
                    · {{ $rows->count() }} {{ $rows->count() === 1 ? 'domain' : 'domains' }}
                </p>
                <div class="nk-chips nk-mt-4">
                    <span class="nk-badge nk-badge-green">{{ $counts->get('available', 0) }} available</span>
                    <span class="nk-badge nk-badge-amber">{{ $counts->get('premium', 0) }} premium</span>
                    <span class="nk-badge nk-badge-zinc">{{ $counts->get('taken', 0) }} taken</span>
                    @if ($counts->get('unknown', 0) > 0)
                        <span class="nk-badge nk-badge-zinc">{{ $counts->get('unknown') }} not checked</span>
                    @endif
                </div>
            </section>

            @if ($rows->isEmpty())
                <section class="nk-card nk-empty">
                    <x-filament::icon icon="heroicon-o-globe-alt" class="nk-empty-icon" />
                    <p class="nk-strong">No domains were stored for this search</p>
                </section>
            @else
                <div class="nk-row">
                    <div class="nk-tabs" role="tablist" aria-label="Filter by status">
                        @foreach (['all' => 'All', 'available' => 'Available', 'premium' => 'Premium', 'taken' => 'Taken'] as $key => $label)
                            <button type="button" role="tab" class="nk-tab" :class="{ 'is-active': filter === '{{ $key }}' }" @click="filter = '{{ $key }}'">
                                {{ $label }}
                                <span class="nk-tab-count">{{ $key === 'all' ? $rows->count() : $counts->get($key, 0) }}</span>
                            </button>
                        @endforeach
                    </div>
                    <label class="nk-search">
                        <x-filament::icon icon="heroicon-m-magnifying-glass" />
                        <input type="search" class="nk-input" placeholder="Search domains" x-model.debounce.150ms="search" aria-label="Search domains">
                    </label>
                </div>

                <section class="nk-card nk-card-flush nk-scroll">
                    <table class="nk-table nk-table-padded">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Status</th>
                                <th>Checked</th>
                                <th class="nk-right"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in paged" :key="row.domain">
                                <tr>
                                    <td class="nk-strong nk-domain" x-text="row.domain"></td>
                                    <td><span class="nk-badge" :class="'nk-status-' + row.status" x-text="row.label"></span></td>
                                    <td class="nk-nowrap" x-text="row.checked || '—'"></td>
                                    <td class="nk-right">
                                        <template x-if="row.status === 'available' || row.status === 'premium'">
                                            <a class="nk-btn nk-btn-outline nk-btn-sm nk-btn-auto" target="_blank" rel="noopener"
                                                :href="'https://www.namecheap.com/domains/registration/results/?domain=' + encodeURIComponent(row.domain)">
                                                Buy
                                                <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="nk-btn-icon" />
                                            </a>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div x-show="filtered.length === 0" x-cloak class="nk-empty">
                        <p class="nk-muted">No domains match.</p>
                    </div>
                    <div class="nk-table-foot" x-show="filtered.length > 0">
                        <span class="nk-small" x-text="`${first}–${last} of ${filtered.length}`"></span>
                        <div class="nk-toolbar">
                            <select class="nk-input nk-select nk-input-sm nk-per-page" x-model.number="perPage" aria-label="Per page">
                                <option value="10">10 per page</option>
                                <option value="25">25 per page</option>
                                <option value="50">50 per page</option>
                            </select>
                            <button type="button" class="nk-btn nk-btn-outline nk-btn-sm nk-btn-auto" @click="page--" :disabled="page <= 1">Previous</button>
                            <button type="button" class="nk-btn nk-btn-outline nk-btn-sm nk-btn-auto" @click="page++" :disabled="page >= pages">Next</button>
                        </div>
                    </div>
                </section>
            @endif
        </div>
    @else
        {{-- Favourites and recent searches --}}
        @php
            $counts = $this->counts;
            $refreshDisabled = $this->refreshDisabled();
        @endphp

        <div class="nk-page">
            <section class="nk-card nk-row">
                <div>
                    <h3 class="nk-heading">Find more domains</h3>
                    <p class="nk-muted nk-mt-1">Describe your idea in the Domain Generator and it checks which names are free to register.</p>
                </div>
                <div class="nk-toolbar">
                    <button
                        type="button"
                        class="nk-btn nk-btn-outline nk-btn-auto"
                        wire:click="refreshDomains"
                        wire:loading.attr="disabled"
                        wire:target="refreshDomains"
                        @disabled($refreshDisabled)
                        title="Re-check availability of your favourites and recent results (once an hour)"
                    >
                        <x-filament::icon icon="heroicon-m-arrow-path" class="nk-btn-icon" wire:loading.class="nk-spin" wire:target="refreshDomains" />
                        {{ $this->refreshLabel() }}
                    </button>
                    <a href="{{ route('domainSearch.index') }}" class="nk-btn nk-btn-primary nk-btn-auto">
                        <x-filament::icon icon="heroicon-m-sparkles" class="nk-btn-icon" />
                        Open Domain Generator
                    </a>
                </div>
            </section>

            <div class="nk-row">
                <div class="nk-tabs" role="tablist" aria-label="Show">
                    <button type="button" role="tab" wire:click="setTab('favorites')" aria-selected="{{ $tab === 'favorites' ? 'true' : 'false' }}" @class(['nk-tab', 'nk-tab-with-count', 'is-active' => $tab === 'favorites'])>
                        Favourites <span class="nk-tab-count">{{ $counts['favorites'] }}</span>
                    </button>
                    <button type="button" role="tab" wire:click="setTab('searches')" aria-selected="{{ $tab === 'searches' ? 'true' : 'false' }}" @class(['nk-tab', 'nk-tab-with-count', 'is-active' => $tab === 'searches'])>
                        Recent searches <span class="nk-tab-count">{{ $counts['searches'] }}</span>
                    </button>
                </div>
                <label class="nk-search">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" />
                    <input type="search" class="nk-input" wire:model.live.debounce.300ms="term" placeholder="{{ $tab === 'favorites' ? 'Search favourites' : 'Search your searches' }}" aria-label="Search">
                </label>
            </div>

            @if ($tab === 'favorites')
                @php $favorites = $this->favorites; @endphp
                @if ($favorites->isEmpty())
                    <section class="nk-card nk-empty">
                        <x-filament::icon icon="heroicon-o-star" class="nk-empty-icon" />
                        @if ($term !== '')
                            <p class="nk-strong">No favourites match “{{ $term }}”</p>
                        @else
                            <p class="nk-strong">No favourite domains yet</p>
                            <p class="nk-muted nk-mt-1">Star a domain in the Domain Generator and it will be kept here.</p>
                        @endif
                    </section>
                @else
                    <section class="nk-card nk-card-flush nk-scroll">
                        <table class="nk-table nk-table-padded">
                            <thead>
                                <tr>
                                    <th>Domain</th>
                                    <th>Status</th>
                                    <th>Last checked</th>
                                    <th>Saved</th>
                                    <th class="nk-right"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($favorites as $favorite)
                                    @php
                                        $status = $favorite->is_premium ? 'premium' : ($favorite->is_available === null ? 'unknown' : ($favorite->is_available ? 'available' : 'taken'));
                                    @endphp
                                    <tr wire:key="fav-{{ $favorite->id }}">
                                        <td class="nk-strong nk-domain">{{ $favorite->domain }}</td>
                                        <td><span class="nk-badge nk-status-{{ $status }}">{{ $statusLabels[$status] }}</span></td>
                                        <td class="nk-nowrap" title="{{ $favorite->checked_at?->copy()->timezone($tz)->format('M j, Y g:i a') }}">{{ $favorite->checked_at?->diffForHumans() ?? '—' }}</td>
                                        <td class="nk-nowrap">{{ $favorite->created_at?->copy()->timezone($tz)->format('M j, Y') }}</td>
                                        <td>
                                            <div class="nk-row-actions">
                                                @if ($status !== 'taken')
                                                    <a href="{{ $buyUrl($favorite->domain) }}" target="_blank" rel="noopener" class="nk-btn nk-btn-outline nk-btn-sm nk-btn-auto">
                                                        Buy
                                                        <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="nk-btn-icon" />
                                                    </a>
                                                @endif
                                                <button type="button" class="nk-icon-btn nk-icon-btn-danger" wire:click="removeFavorite({{ $favorite->id }})" wire:confirm="Remove {{ $favorite->domain }} from your favourites?" aria-label="Remove from favourites" title="Remove from favourites">
                                                    <x-filament::icon icon="heroicon-m-x-mark" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                    @if ($favorites->hasPages())
                        <x-filament::pagination :paginator="$favorites" />
                    @endif
                @endif
            @else
                @php $searches = $this->searches; @endphp
                @if ($searches->isEmpty())
                    <section class="nk-card nk-empty">
                        <x-filament::icon icon="heroicon-o-magnifying-glass" class="nk-empty-icon" />
                        @if ($term !== '')
                            <p class="nk-strong">No searches match “{{ $term }}”</p>
                        @else
                            <p class="nk-strong">No domain searches yet</p>
                            <p class="nk-muted nk-mt-1">Open the Domain Generator to run your first one.</p>
                        @endif
                    </section>
                @else
                    <section class="nk-card nk-card-flush">
                        <ul class="nk-list">
                            @foreach ($searches as $search)
                                @php
                                    $resultRows = $this->resultRowsFor($search);
                                    $available = collect($resultRows)->filter(fn ($row) => \App\UserPortal\Pages\Domains::statusOf($row) === 'available')->count();
                                    $url = \App\UserPortal\Pages\Domains::getUrl(['search' => $search->id], panel: 'user');
                                @endphp
                                <li wire:key="search-{{ $search->id }}" class="nk-list-item">
                                    <a href="{{ $url }}" class="nk-list-main">
                                        <span class="nk-kind-icon nk-kind-domains"><x-filament::icon icon="heroicon-o-magnifying-glass" /></span>
                                        <span class="nk-feed-body">
                                            <span class="nk-feed-title nk-truncate-line" title="{{ $search->prompt }}">{{ $search->prompt }}</span>
                                            <span class="nk-feed-detail">
                                                {{ count($resultRows) }} {{ count($resultRows) === 1 ? 'domain' : 'domains' }}
                                                @if ($available > 0) · <span class="nk-pos">{{ $available }} available</span> @endif
                                                · {{ $search->created_at?->copy()->timezone($tz)->format('M j, Y g:i a') }}
                                            </span>
                                        </span>
                                    </a>
                                    <div class="nk-row-actions">
                                        <a href="{{ $url }}" class="nk-btn nk-btn-outline nk-btn-sm nk-btn-auto">View results</a>
                                        <button type="button" class="nk-icon-btn nk-icon-btn-danger" wire:click="deleteSearch({{ $search->id }})" wire:confirm="Delete this search and its stored results?" aria-label="Delete search" title="Delete search">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                    @if ($searches->hasPages())
                        <x-filament::pagination :paginator="$searches" />
                    @endif
                @endif
            @endif
        </div>
    @endif
</x-filament-panels::page>
