<x-filament-panels::page>
    <div x-data="logoGallery()" class="space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex-1">
                <input
                    type="text"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Search logos by prompt, domain, or style..."
                    class="w-full rounded-lg border-gray-300 px-4 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                />
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    wire:click="$set('filterFavourites', '')"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors {{ $filterFavourites === '' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' }}"
                >
                    All
                </button>
                <button
                    wire:click="$set('filterFavourites', 'favourites')"
                    class="flex items-center gap-1 rounded-lg px-4 py-2 text-sm font-medium transition-colors {{ $filterFavourites === 'favourites' ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M11.645 20.91l-.007-.003-.022-.012a15.247 15.247 0 01-.383-.218 25.18 25.18 0 01-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0112 5.052 5.5 5.5 0 0116.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 01-4.244 3.17 15.247 15.247 0 01-.383.219l-.022.012-.007.004-.003.001a.752.752 0 01-.704 0l-.003-.001z"/></svg>
                    Favourites
                </button>
                <button
                    wire:click="$set('filterFavourites', 'showcase')"
                    class="flex items-center gap-1 rounded-lg px-4 py-2 text-sm font-medium transition-colors {{ $filterFavourites === 'showcase' ? 'bg-amber-500 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                    Showcase
                </button>
            </div>
        </div>

        <div x-show="upscaleError" x-cloak class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            <div class="flex items-start justify-between gap-3">
                <span x-text="upscaleError"></span>
                <button type="button" class="text-red-500 hover:text-red-700 dark:text-red-300" @click="upscaleError = null" aria-label="Dismiss error">&times;</button>
            </div>
        </div>

        @if($items->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-16 text-center dark:border-gray-700 dark:bg-gray-800">
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
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                @foreach($items as $item)
                    @php
                        $imageKey = 'admin-' . $item['logo_id'] . '-' . $item['image_index'];
                        $title = $item['domain'] ?: 'Untitled';
                    @endphp

                    <article class="flex h-full min-h-[27rem] flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm transition hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
                        <div class="relative aspect-square bg-gray-50 dark:bg-gray-900">
                            <button
                                type="button"
                                class="h-full w-full"
                                @click="openPreview(imageUrl('{{ $imageKey }}', @js($item['url'])), @js($title))"
                            >
                                <img
                                    :src="imageUrl('{{ $imageKey }}', @js($item['url']))"
                                    alt="{{ $title }}"
                                    class="h-full w-full object-contain p-3"
                                    loading="lazy"
                                    onerror="this.parentElement.innerHTML='<div class=\'flex h-full w-full items-center justify-center text-xs text-gray-400\'>Image unavailable</div>'"
                                />
                            </button>

                            <div class="absolute left-2 top-2 flex flex-wrap gap-1.5">
                                <span class="rounded-full bg-blue-600 px-2 py-0.5 text-[11px] font-semibold text-white">{{ $item['generator'] }}</span>
                                <span class="rounded-full bg-gray-900 px-2 py-0.5 text-[11px] font-semibold text-white">{{ strtoupper((string) ($item['output_format'] ?? 'image')) }}</span>
                            </div>

                            @if($item['is_favourited'] || $item['is_showcase'])
                                <div class="absolute right-2 top-2 flex gap-1">
                                    @if($item['is_favourited'])
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-red-500 text-white shadow-sm" title="Favourited">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M11.645 20.91l-.007-.003-.022-.012a15.247 15.247 0 01-.383-.218 25.18 25.18 0 01-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0112 5.052 5.5 5.5 0 0116.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 01-4.244 3.17 15.247 15.247 0 01-.383.219l-.022.012-.007.004-.003.001a.752.752 0 01-.704 0l-.003-.001z"/></svg>
                                        </span>
                                    @endif
                                    @if($item['is_showcase'])
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-amber-500 text-white shadow-sm" title="Showcase">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-1 flex-col gap-3 p-3">
                            <div class="min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100" title="{{ $title }}">{{ $title }}</p>
                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $item['user_name'] }} · {{ optional($item['created_at'])->format('M d, Y H:i') }}</p>
                                    </div>
                                    @if($item['size_human'])
                                        <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ $item['size_human'] }}</span>
                                    @endif
                                </div>
                                <p class="mt-2 line-clamp-3 min-h-[3rem] text-xs leading-5 text-gray-500 dark:text-gray-400" title="{{ $item['prompt'] }}">
                                    {{ $item['prompt'] ?: 'Prompt hidden' }}
                                </p>
                            </div>

                            <div class="mt-auto grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    wire:click="toggleFavourite({{ $item['logo_id'] }})"
                                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                                >
                                    {{ $item['is_favourited'] ? 'Unfavourite' : 'Favourite' }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="toggleShowcase({{ $item['logo_id'] }})"
                                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                                >
                                    {{ $item['is_showcase'] ? 'Unshowcase' : 'Showcase' }}
                                </button>
                                <a
                                    :href="imageUrl('{{ $imageKey }}', @js($item['url']))"
                                    download
                                    class="inline-flex items-center justify-center rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white"
                                >
                                    Download
                                </a>
                                @if($item['is_vector'])
                                    <span class="inline-flex items-center justify-center rounded-lg bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-300">Vector</span>
                                @else
                                    <button
                                        type="button"
                                        class="inline-flex items-center justify-center rounded-lg bg-sky-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-sky-700 disabled:cursor-wait disabled:bg-sky-400"
                                        :disabled="isUpscaling('{{ $imageKey }}')"
                                        @click="upsizeImage('{{ $imageKey }}', imageUrl('{{ $imageKey }}', @js($item['url'])), {{ (int) $item['logo_id'] }}, {{ (int) $item['image_index'] }})"
                                        x-text="isUpscaling('{{ $imageKey }}') ? 'Upsizing...' : 'Upsize'"
                                    ></button>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $logos->links() }}
            </div>
        @endif

        <div
            x-show="previewUrl"
            x-cloak
            x-transition.opacity
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4"
            @click.self="closePreview()"
            @keydown.escape.window="closePreview()"
        >
            <div class="max-h-full w-full max-w-6xl">
                <div class="mb-3 flex items-center justify-between gap-3 text-white">
                    <p class="truncate text-sm font-semibold" x-text="previewAlt"></p>
                    <button type="button" class="rounded-lg bg-white/10 px-3 py-1.5 text-sm font-semibold hover:bg-white/20" @click="closePreview()">Close</button>
                </div>
                <img :src="previewUrl" :alt="previewAlt" class="mx-auto max-h-[82vh] max-w-full rounded-lg bg-white object-contain shadow-2xl" />
            </div>
        </div>
    </div>

    <script>
        function logoGallery() {
            return {
                previewUrl: null,
                previewAlt: '',
                upscaleError: null,
                upscaling: {},
                replacementUrls: {},

                imageUrl(key, fallbackUrl) {
                    return this.replacementUrls[key] || fallbackUrl;
                },

                isUpscaling(key) {
                    return Boolean(this.upscaling[key]);
                },

                openPreview(url, alt) {
                    this.previewUrl = url;
                    this.previewAlt = alt || 'Generated image';
                },

                closePreview() {
                    this.previewUrl = null;
                    this.previewAlt = '';
                },

                async upsizeImage(key, imageUrl, logoRequestId, imageIndex) {
                    if (this.isUpscaling(key)) return;

                    this.upscaleError = null;
                    this.upscaling = { ...this.upscaling, [key]: true };
                    const abortController = new AbortController();
                    const timeoutHandle = window.setTimeout(() => abortController.abort(), 180000);

                    try {
                        const response = await fetch('/domain-search/upscale-logo', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            },
                            body: JSON.stringify({
                                image_url: imageUrl,
                                upscale_factor: 2,
                                logo_request_id: logoRequestId,
                                image_index: imageIndex,
                            }),
                            signal: abortController.signal,
                        });

                        const data = await response.json().catch(() => ({ error: 'Server returned an invalid response.' }));
                        if (!response.ok) {
                            this.upscaleError = data.error || 'Upsize failed.';
                            return;
                        }

                        this.replacementUrls = { ...this.replacementUrls, [key]: data.upscaled_url };
                        if (this.previewUrl === imageUrl) {
                            this.previewUrl = data.upscaled_url;
                        }
                    } catch (error) {
                        this.upscaleError = error.name === 'AbortError'
                            ? 'Upsize is taking longer than expected. Please try again in a moment.'
                            : (error.message || 'Upsize failed.');
                    } finally {
                        window.clearTimeout(timeoutHandle);
                        this.upscaling = { ...this.upscaling, [key]: false };
                    }
                },
            };
        }
    </script>
</x-filament-panels::page>
