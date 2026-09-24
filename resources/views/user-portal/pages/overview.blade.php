<x-filament-panels::page>
    {{-- Styles: resources/css/user-portal.css (nk-* classes). The feed comes
         from App\UserPortal\Support\RecentActivity. --}}
    @php
        $filters = ['all' => 'All', 'pdf' => 'PDF', 'images' => 'Images', 'domains' => 'Domains', 'credits' => 'Credits'];
        $shortcuts = [
            ['url' => \App\UserPortal\Pages\PdfGenerator::getUrl(panel: 'user'), 'icon' => 'heroicon-o-document-text', 'kind' => 'pdf', 'title' => 'Show PDFs', 'text' => 'Your uploaded PDFs. Open one to edit, fill or sign it.'],
            ['url' => \App\UserPortal\Pages\ImageGenerator::getUrl(panel: 'user'), 'icon' => 'heroicon-o-photo', 'kind' => 'images', 'title' => 'Show Images', 'text' => 'Every logo and image you have generated.'],
            ['url' => \App\UserPortal\Pages\Domains::getUrl(panel: 'user'), 'icon' => 'heroicon-o-globe-alt', 'kind' => 'domains', 'title' => 'My Domains', 'text' => 'Your favourited domains and recent searches.'],
        ];
    @endphp

    <div class="nk-page">

        {{-- Numbers --}}
        <section class="nk-stats">
            <div class="nk-card nk-stat">
                <p class="nk-muted">Credit balance</p>
                <p class="nk-amount nk-mt-1">${{ number_format($stats['balance'], 2) }}</p>
                <a class="nk-link nk-small nk-mt-2" href="{{ \App\UserPortal\Pages\AddCredits::getUrl(panel: 'user') }}">Add credits &rarr;</a>
            </div>
            <div class="nk-card nk-stat">
                <p class="nk-muted">Spent, last 7 days</p>
                <p class="nk-amount nk-mt-1">${{ number_format($stats['spent7'], 2) }}</p>
            </div>
            <div class="nk-card nk-stat">
                <p class="nk-muted">Activity, last 30 days</p>
                <p class="nk-amount nk-mt-1">{{ number_format($stats['activity30']) }}</p>
                <p class="nk-small nk-mt-2">
                    {{ number_format($stats['pdf30']) }} PDF {{ $stats['pdf30'] === 1 ? 'action' : 'actions' }}
                    · {{ number_format($stats['images30']) }} {{ $stats['images30'] === 1 ? 'image' : 'images' }}
                    · {{ number_format($stats['domains30']) }} domain {{ $stats['domains30'] === 1 ? 'search' : 'searches' }}
                </p>
            </div>
        </section>

        {{-- Shortcuts --}}
        <section class="nk-shortcuts">
            @foreach($shortcuts as $shortcut)
                <a href="{{ $shortcut['url'] }}" class="nk-card nk-shortcut">
                    <span class="nk-kind-icon nk-kind-{{ $shortcut['kind'] }}">
                        <x-filament::icon :icon="$shortcut['icon']" />
                    </span>
                    <span>
                        <span class="nk-heading nk-block">{{ $shortcut['title'] }}</span>
                        <span class="nk-small nk-block nk-mt-1">{{ $shortcut['text'] }}</span>
                    </span>
                </a>
            @endforeach
        </section>

        {{-- Recent activity --}}
        <section class="nk-card">
            <div class="nk-row">
                <div>
                    <h3 class="nk-heading">Recent activity</h3>
                    <p class="nk-muted nk-mt-1">Everything you've done across PDFs, images, domains and credits.</p>
                </div>
                <div class="nk-tabs" role="tablist" aria-label="Filter activity">
                    @foreach($filters as $key => $label)
                        <button
                            type="button"
                            role="tab"
                            aria-selected="{{ $kind === $key ? 'true' : 'false' }}"
                            @class(['nk-tab', 'is-active' => $kind === $key])
                            wire:click="setKind('{{ $key }}')"
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            <div class="nk-feed nk-mt-5" wire:loading.class="nk-busy" wire:target="setKind,showMore">
                @forelse($days as $date => $items)
                    @php
                        $day = $items->first()['at'];
                        $dayLabel = match (true) {
                            $day->isSameDay($today) => 'Today',
                            $day->isSameDay($today->copy()->subDay()) => 'Yesterday',
                            $day->year === $today->year => $day->format('l, M j'),
                            default => $day->format('M j, Y'),
                        };
                    @endphp
                    <div class="nk-feed-day" wire:key="day-{{ $date }}">
                        <p class="nk-feed-date">{{ $dayLabel }}</p>
                        <ul class="nk-feed-list">
                            @foreach($items as $item)
                                <li>
                                    <a
                                        @if($item['url']) href="{{ $item['url'] }}" @endif
                                        @if($item['kind'] === 'pdf' && $item['url']) target="_blank" rel="noopener" @endif
                                        @class(['nk-feed-item', 'is-static' => !$item['url']])
                                        title="{{ $item['at']->format('M j, Y g:i a') }}"
                                    >
                                        @if($item['thumb'])
                                            <img class="nk-feed-thumb" src="{{ $item['thumb'] }}" alt="" loading="lazy">
                                        @else
                                            <span class="nk-kind-icon nk-kind-{{ $item['kind'] }}">
                                                <x-filament::icon :icon="$item['icon']" />
                                            </span>
                                        @endif
                                        <span class="nk-feed-body">
                                            <span class="nk-feed-title">
                                                {{ $item['title'] }}
                                                @if($item['failed'])
                                                    <span class="nk-badge nk-badge-red nk-ml-2">Failed</span>
                                                @endif
                                            </span>
                                            @if($item['detail'])
                                                <span class="nk-feed-detail" title="{{ $item['detail'] }}">{{ $item['detail'] }}</span>
                                            @endif
                                        </span>
                                        @if ($item['cost'] !== null)
                                            <span @class(['nk-feed-cost', 'is-credit' => $item['credit']])>
                                                {{ $item['credit'] ? '+' : '' }}${{ number_format($item['cost'], $item['cost'] < 1 && !$item['credit'] ? 4 : 2) }}
                                            </span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <div class="nk-empty">
                        <x-filament::icon icon="heroicon-o-clock" class="nk-empty-icon" />
                        <p class="nk-strong">Nothing here yet</p>
                        <p class="nk-muted nk-mt-1">
                            {{ $kind === 'all' ? 'Edit a PDF, generate an image or search for a domain and it will show up here.' : 'No ' . strtolower($filters[$kind]) . ' activity yet.' }}
                        </p>
                    </div>
                @endforelse
            </div>

            @if($hasMore)
                <div class="nk-center nk-mt-4">
                    <button type="button" class="nk-btn nk-btn-outline nk-btn-auto" wire:click="showMore" wire:loading.attr="disabled" wire:target="showMore">
                        Show more
                    </button>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
