<x-filament-panels::page>
    {{-- Search & Filter Bar --}}
    <div class="flex flex-col sm:flex-row gap-3 mb-6">
        <div class="flex-1">
            <input
                type="text"
                wire:model.live.debounce.350ms="search"
                placeholder="Search logos by prompt, domain, or style..."
                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm px-4 py-2"
            />
        </div>
        <div class="flex gap-2">
            <button
                wire:click="$set('filterFavourites', '')"
                class="px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ $filterFavourites === '' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600' }}"
            >
                All
            </button>
            <button
                wire:click="$set('filterFavourites', 'favourites')"
                class="px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-1 {{ $filterFavourites === 'favourites' ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600' }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M11.645 20.91l-.007-.003-.022-.012a15.247 15.247 0 01-.383-.218 25.18 25.18 0 01-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0112 5.052 5.5 5.5 0 0116.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 01-4.244 3.17 15.247 15.247 0 01-.383.219l-.022.012-.007.004-.003.001a.752.752 0 01-.704 0l-.003-.001z"/></svg>
                Favourites
            </button>
        </div>
    </div>

    {{-- Logo Grid --}}
    @if($items->isEmpty())
        <div class="text-center py-16">
            <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No logos found</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                @if($search !== '')
                    No logos match "{{ $search }}". Try a different search.
                @else
                    Generate some logos to see them here.
                @endif
            </p>
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
            @foreach($items as $item)
                <div class="group relative rounded-xl overflow-hidden bg-white dark:bg-gray-800 shadow-sm hover:shadow-md transition-shadow border border-gray-200 dark:border-gray-700">
                    {{-- Square Image Container --}}
                    <div class="aspect-square bg-gray-50 dark:bg-gray-900 flex items-center justify-center overflow-hidden">
                        <img
                            src="{{ $item['url'] }}"
                            alt="{{ $item['prompt'] }}"
                            class="w-full h-full object-contain p-2"
                            loading="lazy"
                            onerror="this.parentElement.innerHTML='<div class=\'flex items-center justify-center w-full h-full text-gray-400 text-xs\'>Image unavailable</div>'"
                        />
                    </div>

                    {{-- Overlay Buttons (appear on hover) --}}
                    <div class="absolute top-2 right-2 flex flex-col gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        {{-- Heart / Favourite Button --}}
                        <button
                            wire:click="toggleFavourite({{ $item['logo_id'] }})"
                            class="w-8 h-8 rounded-full flex items-center justify-center transition-colors {{ $item['is_favourited'] ? 'bg-red-500 text-white' : 'bg-white/90 dark:bg-gray-700/90 text-gray-500 hover:text-red-500' }} shadow-sm"
                            title="{{ $item['is_favourited'] ? 'Remove from favourites' : 'Add to favourites' }}"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="{{ $item['is_favourited'] ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.645 20.91l-.007-.003-.022-.012a15.247 15.247 0 01-.383-.218 25.18 25.18 0 01-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0112 5.052 5.5 5.5 0 0116.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 01-4.244 3.17 15.247 15.247 0 01-.383.219l-.022.012-.007.004-.003.001a.752.752 0 01-.704 0l-.003-.001z"/>
                            </svg>
                        </button>

                        {{-- Download Button --}}
                        <a
                            href="{{ $item['url'] }}"
                            download
                            class="w-8 h-8 rounded-full bg-white/90 dark:bg-gray-700/90 flex items-center justify-center text-gray-500 hover:text-primary-500 shadow-sm transition-colors"
                            title="Download"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                        </a>
                    </div>

                    {{-- Favourited indicator (always visible when favourited) --}}
                    @if($item['is_favourited'])
                        <div class="absolute top-2 left-2 group-hover:opacity-0 transition-opacity">
                            <div class="w-6 h-6 rounded-full bg-red-500 flex items-center justify-center shadow-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-white" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M11.645 20.91l-.007-.003-.022-.012a15.247 15.247 0 01-.383-.218 25.18 25.18 0 01-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0112 5.052 5.5 5.5 0 0116.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 01-4.244 3.17 15.247 15.247 0 01-.383.219l-.022.012-.007.004-.003.001a.752.752 0 01-.704 0l-.003-.001z"/>
                                </svg>
                            </div>
                        </div>
                    @endif

                    {{-- Info Footer --}}
                    <div class="p-2.5 border-t border-gray-100 dark:border-gray-700">
                        <p class="text-xs font-medium text-gray-800 dark:text-gray-200 truncate" title="{{ $item['prompt'] }}">
                            {{ Str::limit($item['prompt'], 40) }}
                        </p>
                        <div class="flex items-center justify-between mt-1">
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">
                                {{ $item['style'] }}
                                @if($item['size_human'])
                                    · {{ $item['size_human'] }}
                                @endif
                            </span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500">
                                {{ $item['created_at']->diffForHumans() }}
                            </span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-6">
            {{ $logos->links() }}
        </div>
    @endif
</x-filament-panels::page>
