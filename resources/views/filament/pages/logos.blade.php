<x-filament-panels::page>
    <style>
        .netkit-images-callout {
            border: 1px solid rgb(199 210 254);
            background: linear-gradient(135deg, rgb(238 242 255), rgb(250 245 255));
            border-radius: 8px;
            padding: 18px 20px;
        }

        .dark .netkit-images-callout {
            border-color: rgba(99, 102, 241, 0.45);
            background: linear-gradient(135deg, rgba(49, 46, 129, 0.46), rgba(88, 28, 135, 0.24));
        }

        .netkit-image-board {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(265px, 1fr));
            gap: 16px;
        }

        .netkit-image-card {
            position: relative;
            overflow: hidden;
            border: 1px solid rgb(214 219 226);
            border-radius: 3px;
            background: white;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
            transition: transform 160ms ease, box-shadow 160ms ease, border-color 160ms ease;
        }

        .netkit-image-card:hover {
            transform: translateY(-2px);
            border-color: rgb(148 163 184);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.14);
        }

        .dark .netkit-image-card {
            border-color: rgb(31 41 55);
            background: rgb(17 24 39);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.28);
        }

        .netkit-image-frame {
            position: relative;
            height: 210px;
            border-bottom: 1px solid rgb(229 231 235);
            background: rgb(241 245 249);
        }

        .dark .netkit-image-frame {
            border-bottom-color: rgb(31 41 55);
            background: rgb(3 7 18);
        }

        .netkit-image-frame button,
        .netkit-image-frame img {
            width: 100%;
            height: 100%;
        }

        .netkit-image-frame img {
            object-fit: cover;
        }

        .netkit-image-format {
            position: absolute;
            top: 8px;
            left: 8px;
            padding: 2px 6px;
            border-radius: 2px;
            background: rgba(255, 255, 255, 0.9);
            color: rgb(71 85 105);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.03em;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.12);
        }

        .dark .netkit-image-format {
            background: rgba(17, 24, 39, 0.9);
            color: rgb(203 213 225);
        }

        .netkit-image-body {
            padding: 16px;
        }

        .netkit-image-title {
            overflow: hidden;
            color: rgb(31 41 55);
            font-size: 15px;
            font-weight: 600;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dark .netkit-image-title {
            color: white;
        }

        .netkit-image-title-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }

        .netkit-image-title-edit {
            display: inline-flex;
            width: 28px;
            height: 28px;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            border: 1px solid rgb(226 232 240);
            border-radius: 7px;
            color: rgb(100 116 139);
            transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
        }

        .netkit-image-title-edit:hover {
            border-color: rgb(148 163 184);
            background: rgb(248 250 252);
            color: rgb(15 23 42);
        }

        .dark .netkit-image-title-edit {
            border-color: rgb(55 65 81);
            color: rgb(203 213 225);
        }

        .dark .netkit-image-title-edit:hover {
            background: rgb(31 41 55);
            color: white;
        }

        .netkit-rename-form {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 8px;
            margin-top: 10px;
        }

        .netkit-rename-input {
            min-width: 0;
            border: 1px solid rgb(203 213 225);
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 13px;
            color: rgb(15 23 42);
        }

        .netkit-rename-input:focus {
            outline: 2px solid rgb(14 165 233 / 0.25);
            border-color: rgb(14 165 233);
        }

        .dark .netkit-rename-input {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
            color: white;
        }

        .netkit-rename-save,
        .netkit-rename-cancel {
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 12px;
            font-weight: 700;
        }

        .netkit-rename-save {
            background: rgb(15 23 42);
            color: white;
        }

        .netkit-rename-cancel {
            border: 1px solid rgb(203 213 225);
            color: rgb(71 85 105);
        }

        .netkit-image-prompt {
            display: -webkit-box;
            min-height: 40px;
            margin-top: 8px;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            color: rgb(100 116 139);
            font-size: 13px;
            line-height: 20px;
        }

        .dark .netkit-image-prompt {
            color: rgb(156 163 175);
        }

        .netkit-image-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            color: rgb(148 163 184);
            font-size: 12px;
        }

        .netkit-image-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 12px;
        }

        .netkit-seg {
            display: inline-flex;
            padding: 4px;
            border-radius: 10px;
            border: 1px solid rgb(226 232 240);
            background: white;
            gap: 2px;
        }

        .dark .netkit-seg {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }

        .netkit-seg__btn {
            border-radius: 7px;
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 600;
            color: rgb(71 85 105);
            transition: background 140ms ease, color 140ms ease;
        }

        .dark .netkit-seg__btn {
            color: rgb(148 163 184);
        }

        .netkit-seg__btn.is-active {
            background: rgb(15 23 42);
            color: white;
        }

        .dark .netkit-seg__btn.is-active {
            background: white;
            color: rgb(2 6 23);
        }

        .netkit-btn-primary,
        .netkit-btn-secondary,
        .netkit-btn-showcase,
        .netkit-btn-vector {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .netkit-btn-primary {
            background: rgb(2 132 199);
            color: white;
        }

        .netkit-btn-primary:disabled {
            cursor: wait;
            background: rgb(56 189 248);
        }

        .netkit-btn-secondary {
            border: 1px solid rgb(203 213 225);
            color: rgb(51 65 85);
        }

        .dark .netkit-btn-secondary {
            border-color: rgb(55 65 81);
            color: rgb(226 232 240);
        }

        .netkit-btn-showcase {
            border: 1px solid rgb(245 158 11);
            color: rgb(146 64 14);
            background: rgb(255 251 235);
        }

        .dark .netkit-btn-showcase {
            border-color: rgb(180 83 9);
            background: rgba(120, 53, 15, 0.35);
            color: rgb(253 230 138);
        }

        .netkit-btn-vector {
            background: rgb(241 245 249);
            color: rgb(100 116 139);
        }

        .dark .netkit-btn-vector {
            background: rgb(31 41 55);
            color: rgb(203 213 225);
        }

        .netkit-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 6px;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 700;
            background: rgb(239 246 255);
            color: rgb(29 78 216);
        }

        .netkit-badge--cost {
            background: rgb(236 253 245);
            color: rgb(4 120 87);
        }

        .netkit-badge--showcase {
            background: rgb(254 243 199);
            color: rgb(146 64 14);
        }

        .dark .netkit-badge {
            background: rgba(30, 58, 138, 0.4);
            color: rgb(191 219 254);
        }

        .dark .netkit-badge--cost {
            background: rgba(6, 78, 59, 0.45);
            color: rgb(167 243 208);
        }

        .dark .netkit-badge--showcase {
            background: rgba(120, 53, 15, 0.45);
            color: rgb(253 230 138);
        }
    </style>

    <div class="space-y-5" x-data="logoGallery()">
        <section class="netkit-images-callout">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-base font-bold text-indigo-900 dark:text-indigo-100">Admin image gallery</h2>
                    <p class="mt-1 text-sm text-indigo-700 dark:text-indigo-200">Review generated images with the same gallery controls users have, then curate selected logos into the public showcase.</p>
                </div>
                <x-filament::button
                    tag="a"
                    :href="route('domainSearch.logoGenerator')"
                    size="lg"
                    icon="heroicon-m-sparkles"
                >
                    Open Logo Generator
                </x-filament::button>
            </div>
        </section>

        <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-950 dark:text-white">Images</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">Completed generated images and vectors from every account.</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <input
                    type="text"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Search logos..."
                    class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:w-60"
                />

                <div class="netkit-seg">
                    <button type="button" wire:click="$set('filterFavourites', '')" @class(['netkit-seg__btn', 'is-active' => $this->filterFavourites === ''])>All</button>
                    <button type="button" wire:click="$set('filterFavourites', 'favourites')" @class(['netkit-seg__btn', 'is-active' => $this->filterFavourites === 'favourites'])>Favourites</button>
                    <button type="button" wire:click="$set('filterFavourites', 'showcase')" @class(['netkit-seg__btn', 'is-active' => $this->filterFavourites === 'showcase'])>Showcase</button>
                </div>

                <div class="netkit-seg">
                    <button type="button" wire:click="setFormatFilter('all')" @class(['netkit-seg__btn', 'is-active' => $this->formatFilter === 'all'])>All</button>
                    <button type="button" wire:click="setFormatFilter('raster')" @class(['netkit-seg__btn', 'is-active' => $this->formatFilter === 'raster'])>Raster</button>
                    <button type="button" wire:click="setFormatFilter('vector')" @class(['netkit-seg__btn', 'is-active' => $this->formatFilter === 'vector'])>Vector</button>
                </div>

                <div class="netkit-seg">
                    <button type="button" wire:click="setViewMode('grid')" @class(['netkit-seg__btn', 'is-active' => $this->viewMode === 'grid'])>Grid</button>
                    <button type="button" wire:click="setViewMode('table')" @class(['netkit-seg__btn', 'is-active' => $this->viewMode === 'table'])>Table</button>
                </div>
            </div>
        </div>

        <div x-show="upscaleError" x-cloak class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800/60 dark:bg-red-950/40 dark:text-red-200">
            <div class="flex items-start justify-between gap-3">
                <span x-text="upscaleError"></span>
                <button type="button" class="text-red-500 hover:text-red-700" @click="upscaleError = null" aria-label="Dismiss error">&times;</button>
            </div>
        </div>

        @if ($items->isEmpty())
            <div class="flex flex-col items-center gap-3 rounded-lg border border-dashed border-gray-300 bg-white px-6 py-16 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                <p class="font-medium text-gray-700 dark:text-gray-200">No logos found</p>
                <p>Adjust the filters or generate new logos.</p>
            </div>
        @elseif ($this->viewMode === 'grid')
            <div class="netkit-image-board">
                @foreach ($items as $item)
                    @php
                        $imageKey = 'admin-' . $item['logo_id'] . '-' . $item['image_index'];
                        $title = $item['domain'] ?: 'Untitled';
                    @endphp

                    <article class="netkit-image-card">
                        <div class="netkit-image-frame">
                            <button
                                type="button"
                                class="block aspect-[4/3] w-full"
                                @click="openPreview(imageUrl('{{ $imageKey }}', @js($item['original_url'])), @js($title))"
                            >
                                <img
                                    :src="imagePreviewUrl('{{ $imageKey }}', @js($item['preview_url']))"
                                    alt="{{ $title }}"
                                    loading="lazy"
                                    x-on:error="imageLoadFallback($event, imageUrl('{{ $imageKey }}', @js($item['original_url'])))"
                                />
                            </button>
                            <div class="netkit-image-format">{{ strtoupper((string) ($item['output_format'] ?? 'image')) }}</div>
                        </div>

                        <div class="netkit-image-body">
                            <div class="flex flex-wrap items-center gap-1.5 text-[11px] font-semibold">
                                <span class="netkit-badge">{{ $item['generator'] }}</span>
                                @if ($item['cost'] !== null)
                                    <span class="netkit-badge netkit-badge--cost">${{ number_format((float) $item['cost'], 4) }}</span>
                                @endif
                                @if ($item['is_showcase'])
                                    <span class="netkit-badge netkit-badge--showcase">Showcase</span>
                                @endif
                            </div>

                            @if ($this->editingImageKey === $imageKey)
                                <div class="netkit-rename-form">
                                    <input
                                        type="text"
                                        wire:model="editingName"
                                        class="netkit-rename-input"
                                        wire:keydown.enter="saveRename({{ $item['logo_id'] }})"
                                        wire:keydown.escape="cancelRename"
                                    />
                                    <button type="button" wire:click="saveRename({{ $item['logo_id'] }})" class="netkit-rename-save">Save</button>
                                    <button type="button" wire:click="cancelRename" class="netkit-rename-cancel">Cancel</button>
                                </div>
                            @else
                                <div class="netkit-image-title-row">
                                    <div class="min-w-0 grow">
                                        <p class="netkit-image-title">{{ $title }}</p>
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="startRename({{ $item['logo_id'] }}, '{{ $imageKey }}')"
                                        class="netkit-image-title-edit"
                                        aria-label="Rename image"
                                        title="Rename"
                                    >
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                                        </svg>
                                    </button>
                                </div>
                                <p class="netkit-image-prompt">{{ $item['prompt'] ?: 'Prompt hidden' }}</p>
                            @endif

                            <div class="netkit-image-meta">
                                <span>{{ $item['user_name'] }}</span>
                                @if ($item['seed_number'])
                                    <span>Seed {{ $item['seed_number'] }}</span>
                                @endif
                                <span class="grow"></span>
                                <span>{{ optional($item['created_at'])->format('g:i a') }}</span>
                            </div>

                            <div class="netkit-image-actions">
                                <button type="button" wire:click="toggleShowcase({{ $item['logo_id'] }}, {{ $item['image_index'] }})" class="netkit-btn-showcase">
                                    {{ $item['is_showcase'] ? 'Remove from showcase' : 'Showcase this logo' }}
                                </button>
                                <button type="button" wire:click="toggleFavourite({{ $item['logo_id'] }})" class="netkit-btn-secondary">
                                    {{ $item['is_favourited'] ? 'Unfavourite' : 'Favourite' }}
                                </button>
                                <a :href="imageUrl('{{ $imageKey }}', @js($item['original_url']))" download class="netkit-btn-secondary">Download</a>
                                @if ($item['is_vector'])
                                    <span class="netkit-btn-vector">Vector</span>
                                @else
                                    <button
                                        type="button"
                                        class="netkit-btn-primary"
                                        :disabled="isUpscaling('{{ $imageKey }}')"
                                        @click="upsizeImage('{{ $imageKey }}', imageUrl('{{ $imageKey }}', @js($item['original_url'])), {{ (int) $item['logo_id'] }}, {{ (int) $item['image_index'] }}, @js($item['preview_url']))"
                                        x-text="isUpscaling('{{ $imageKey }}') ? 'Upsizing...' : 'Upsize'"
                                    ></button>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Preview</th>
                            <th class="px-4 py-3 text-left font-semibold">Name</th>
                            <th class="px-4 py-3 text-left font-semibold">Prompt</th>
                            <th class="px-4 py-3 text-left font-semibold">Owner</th>
                            <th class="px-4 py-3 text-left font-semibold">Settings</th>
                            <th class="px-4 py-3 text-left font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($items as $item)
                            @php
                                $imageKey = 'admin-table-' . $item['logo_id'] . '-' . $item['image_index'];
                                $title = $item['domain'] ?: 'Untitled';
                            @endphp
                            <tr>
                                <td class="px-4 py-3">
                                    <button type="button" @click="openPreview(imageUrl('{{ $imageKey }}', @js($item['original_url'])), @js($title))" class="block">
                                        <img
                                            :src="imagePreviewUrl('{{ $imageKey }}', @js($item['preview_url']))"
                                            alt="{{ $title }}"
                                            class="h-12 w-12 rounded-md border border-gray-200 object-contain dark:border-gray-700"
                                            loading="lazy"
                                            x-on:error="imageLoadFallback($event, imageUrl('{{ $imageKey }}', @js($item['original_url'])))"
                                        />
                                    </button>
                                </td>
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                                    {{ $title }}
                                    @if ($item['is_showcase'])
                                        <span class="ml-2 netkit-badge netkit-badge--showcase">Showcase</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                    <span class="block max-w-md truncate">{{ $item['prompt'] ?: 'Prompt hidden' }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $item['user_email'] ?: $item['user_name'] }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                    <span class="block">{{ $item['generator'] }} · {{ strtoupper((string) ($item['output_format'] ?? 'image')) }}</span>
                                    <span class="block text-xs text-gray-400">{{ $item['style'] }}{{ $item['logo_shape'] ? ' · ' . $item['logo_shape'] : '' }}{{ $item['logo_detail'] ? ' · ' . $item['logo_detail'] : '' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" wire:click="toggleShowcase({{ $item['logo_id'] }}, {{ $item['image_index'] }})" class="netkit-btn-showcase">
                                            {{ $item['is_showcase'] ? 'Remove from showcase' : 'Showcase this logo' }}
                                        </button>
                                        <a :href="imageUrl('{{ $imageKey }}', @js($item['original_url']))" download class="netkit-btn-secondary">Download</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div>
            {{ $logos->links() }}
        </div>

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
                replacementPreviewUrls: {},

                imageUrl(key, fallbackUrl) {
                    return this.replacementUrls[key] || fallbackUrl;
                },

                imagePreviewUrl(key, fallbackUrl) {
                    return this.replacementPreviewUrls[key] || fallbackUrl;
                },

                isUpscaling(key) {
                    return Boolean(this.upscaling[key]);
                },

                openPreview(url, alt) {
                    this.previewUrl = url;
                    this.previewAlt = alt || 'Generated image';
                },

                imageLoadFallback(event, fallbackUrl) {
                    const image = event?.target;
                    if (!image || !fallbackUrl || image.dataset.fallbackTried === '1') {
                        if (image) {
                            image.style.display = 'none';
                        }
                        return;
                    }

                    image.dataset.fallbackTried = '1';
                    image.src = fallbackUrl;
                },

                closePreview() {
                    this.previewUrl = null;
                    this.previewAlt = '';
                },

                async upsizeImage(key, imageUrl, logoRequestId, imageIndex, previewUrl) {
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
                        this.replacementPreviewUrls = { ...this.replacementPreviewUrls, [key]: `${previewUrl}?v=${Date.now()}` };
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
