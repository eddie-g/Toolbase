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

        .netkit-image-frame button {
            display: block;
            width: 100%;
            height: 100%;
        }

        .netkit-image-frame img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .netkit-image-format {
            position: absolute;
            top: 8px;
            left: 8px;
            padding: 2px 6px;
            border-radius: 2px;
            background: rgba(255, 255, 255, 0.88);
            color: rgb(71 85 105);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.03em;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.12);
        }

        .dark .netkit-image-format {
            background: rgba(17, 24, 39, 0.88);
            color: rgb(203 213 225);
        }

        .netkit-image-edit {
            position: absolute;
            top: 8px;
            right: 8px;
            display: inline-flex;
            width: 26px;
            height: 26px;
            align-items: center;
            justify-content: center;
            border-radius: 2px;
            background: rgba(255, 255, 255, 0.88);
            color: rgb(100 116 139);
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.12);
        }

        .dark .netkit-image-edit {
            background: rgba(17, 24, 39, 0.88);
            color: rgb(203 213 225);
        }

        .netkit-image-generator-mark {
            position: absolute;
            right: 14px;
            bottom: -20px;
            display: flex;
            width: 50px;
            height: 50px;
            align-items: center;
            justify-content: center;
            border: 1px solid rgb(226 232 240);
            border-radius: 2px;
            background: white;
            color: rgb(15 23 42);
            font-size: 18px;
            font-weight: 800;
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.18);
        }

        .dark .netkit-image-generator-mark {
            border-color: rgb(55 65 81);
            background: rgb(3 7 18);
            color: white;
        }

        .netkit-image-body {
            padding: 28px 16px 12px;
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
            opacity: 0.92;
        }

        .netkit-image-card:hover .netkit-image-actions {
            opacity: 1;
        }

        .netkit-images-callout__row {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        @media (min-width: 768px) {
            .netkit-images-callout__row {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .netkit-images-callout__title {
            font-size: 16px;
            font-weight: 700;
            color: rgb(49 46 129);
        }

        .dark .netkit-images-callout__title {
            color: rgb(224 231 255);
        }

        .netkit-images-callout__text {
            margin-top: 4px;
            font-size: 14px;
            color: rgb(67 56 202);
        }

        .dark .netkit-images-callout__text {
            color: rgb(199 210 254);
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

        .netkit-seg__btn:hover {
            color: rgb(15 23 42);
        }

        .dark .netkit-seg__btn {
            color: rgb(148 163 184);
        }

        .dark .netkit-seg__btn:hover {
            color: white;
        }

        .netkit-seg__btn.is-active {
            background: rgb(15 23 42);
            color: white;
        }

        .dark .netkit-seg__btn.is-active {
            background: white;
            color: rgb(2 6 23);
        }

        .netkit-btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            background: rgb(2 132 199);
            color: white;
            transition: background 140ms ease;
        }

        .netkit-btn-primary:hover {
            background: rgb(3 105 161);
        }

        .netkit-btn-primary:disabled {
            cursor: wait;
            background: rgb(56 189 248);
        }

        .netkit-btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid rgb(203 213 225);
            color: rgb(51 65 85);
            transition: background 140ms ease;
        }

        .netkit-btn-secondary:hover {
            background: rgb(248 250 252);
        }

        .dark .netkit-btn-secondary {
            border-color: rgb(55 65 81);
            color: rgb(226 232 240);
        }

        .dark .netkit-btn-secondary:hover {
            background: rgb(31 41 55);
        }

        .netkit-btn-vector {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
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

        .dark .netkit-badge {
            background: rgba(30, 58, 138, 0.4);
            color: rgb(191 219 254);
        }

        .netkit-badge--cost {
            background: rgb(236 253 245);
            color: rgb(4 120 87);
        }

        .dark .netkit-badge--cost {
            background: rgba(6, 78, 59, 0.45);
            color: rgb(167 243 208);
        }

        .netkit-images-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            padding: 28px 16px;
            color: rgb(100 116 139);
            font-size: 14px;
            text-align: center;
        }

        .dark .netkit-images-empty {
            color: rgb(156 163 175);
        }
    </style>

    <div class="space-y-5" x-data="logoGallery()">
        <section class="netkit-images-callout">
            <div class="netkit-images-callout__row">
                <div>
                    <h2 class="netkit-images-callout__title">Create more image concepts</h2>
                    <p class="netkit-images-callout__text">Use Logo Generator to create raster images, vector marks, and brand visuals for this gallery.</p>
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

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-950 dark:text-white">Images</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">Generated images and vectors from your logo workflows.</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="netkit-seg">
                    <button
                        type="button"
                        wire:click="setFormatFilter('all')"
                        @class(['netkit-seg__btn', 'is-active' => $this->formatFilter === 'all'])
                    >
                        All
                    </button>
                    <button
                        type="button"
                        wire:click="setFormatFilter('raster')"
                        @class(['netkit-seg__btn', 'is-active' => $this->formatFilter === 'raster'])
                    >
                        Raster
                    </button>
                    <button
                        type="button"
                        wire:click="setFormatFilter('vector')"
                        @class(['netkit-seg__btn', 'is-active' => $this->formatFilter === 'vector'])
                    >
                        Vector
                    </button>
                </div>
                <div class="netkit-seg">
                    <button
                        type="button"
                        wire:click="setViewMode('grid')"
                        @class(['netkit-seg__btn', 'is-active' => $this->viewMode === 'grid'])
                    >
                        Grid
                    </button>
                    <button
                        type="button"
                        wire:click="setViewMode('table')"
                        @class(['netkit-seg__btn', 'is-active' => $this->viewMode === 'table'])
                    >
                        Table
                    </button>
                </div>
            </div>
        </div>

        <div x-show="upscaleError" x-cloak class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800/60 dark:bg-red-950/40 dark:text-red-200">
            <div class="flex items-start justify-between gap-3">
                <span x-text="upscaleError"></span>
                <button type="button" class="text-red-500 hover:text-red-700" @click="upscaleError = null" aria-label="Dismiss error">&times;</button>
            </div>
        </div>

        @if ($this->requests->count() === 0)
            @php
                $availableBalance = (float) (auth()->user()?->credit_balance ?? 0);
            @endphp

            <div
                class="netkit-images-empty"
                x-data="{
                    loading: false,
                    error: null,
                    async buyCredits() {
                        this.loading = true;
                        this.error = null;

                        try {
                            const response = await fetch('{{ route('credits.checkout') }}', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
                                    'Accept': 'application/json',
                                },
                                body: JSON.stringify({ amount: 5 }),
                            });
                            const data = await response.json();

                            if (data.checkout_url) {
                                window.location.href = data.checkout_url;
                                return;
                            }

                            this.error = data.error || 'Unable to start checkout.';
                        } catch (e) {
                            this.error = 'Unable to start checkout.';
                        } finally {
                            this.loading = false;
                        }
                    }
                }"
            >
                @if ($availableBalance <= 0)
                    <div>
                        <p class="font-medium text-gray-700 dark:text-gray-200">You will need credits to generate images.</p>
                        <template x-if="error">
                            <p class="mt-2 text-sm text-red-600 dark:text-red-400" x-text="error"></p>
                        </template>
                    </div>
                    <x-filament::button
                        type="button"
                        icon="heroicon-m-credit-card"
                        x-on:click="buyCredits()"
                        x-bind:disabled="loading"
                    >
                        <span x-text="loading ? 'Starting checkout...' : 'Buy credits'">Buy credits</span>
                    </x-filament::button>
                @else
                    <p>No images generated yet</p>
                @endif
            </div>
        @elseif ($this->viewMode === 'grid')
            <div class="netkit-image-board">
                @foreach ($this->requests as $request)
                    @php
                        $urls = is_array($request->image_urls) ? array_values(array_filter($request->image_urls)) : [];
                        $generator = $this->modelLabel($request->model);
                        $cost = $request->latest_cost_usd ?? null;
                        $title = $request->domain ?: 'Untitled';
                    @endphp

                    @foreach ($urls as $imageIndex => $url)
                        @php
                            $path = strtolower((string) parse_url($url, PHP_URL_PATH));
                            $isVector = $request->output_format === 'vector' || str_ends_with($path, '.svg');
                            $imageKey = 'user-' . $request->id . '-' . $imageIndex;
                        @endphp

                        <article class="netkit-image-card">
                            <div class="netkit-image-frame">
                                <button
                                    type="button"
                                    class="block aspect-[4/3] w-full"
                                    @click="openPreview(imageUrl('{{ $imageKey }}', @js($url)), @js($title))"
                                >
                                    <img
                                        :src="imageUrl('{{ $imageKey }}', @js($url))"
                                        alt="{{ $title }}"
                                        class="h-full w-full object-cover"
                                        loading="lazy"
                                        onerror="this.parentElement.innerHTML='<div class=\'flex h-full w-full items-center justify-center text-xs text-gray-400\'>Image unavailable</div>'"
                                    />
                                </button>
                                <div class="netkit-image-format">
                                    {{ strtoupper((string) ($request->output_format ?? 'image')) }}
                                </div>
                                <button
                                    type="button"
                                    wire:click="startRename({{ $request->id }})"
                                    class="netkit-image-edit"
                                    aria-label="Rename image"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 7.125 16.862 4.487" />
                                    </svg>
                                </button>
                                <div class="netkit-image-generator-mark">
                                    {{ strtoupper(substr($generator, 0, 1)) }}
                                </div>
                            </div>

                            <div class="netkit-image-body">
                                <div class="flex flex-wrap items-center gap-1.5 text-[11px] font-semibold">
                                    <span class="netkit-badge">{{ $generator }}</span>
                                    @if ($cost !== null)
                                        <span class="netkit-badge netkit-badge--cost">${{ number_format((float) $cost, 4) }}</span>
                                    @endif
                                </div>

                                @if ($this->editingRequestId === (int) $request->id)
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="text"
                                            wire:model="editingName"
                                            class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                                        />
                                        <button wire:click="saveRename({{ $request->id }})" class="rounded-lg bg-gray-900 px-2 py-1.5 text-xs font-semibold text-white">Save</button>
                                        <button wire:click="cancelRename" class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs font-semibold text-gray-600">Cancel</button>
                                    </div>
                                @else
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="netkit-image-title">{{ $title }}</p>
                                            <p class="netkit-image-prompt">{{ $request->original_prompt ?: 'Prompt hidden' }}</p>
                                        </div>
                                    </div>
                                @endif

                                <div class="netkit-image-meta">
                                    <span aria-hidden="true">☆</span>
                                    <span class="grow"></span>
                                    <span>{{ optional($request->created_at)->format('g:i a') }}</span>
                                </div>

                                <div class="netkit-image-actions">
                                    <a
                                        :href="imageUrl('{{ $imageKey }}', @js($url))"
                                        download
                                        class="netkit-btn-secondary"
                                    >
                                        Download
                                    </a>
                                    @if ($isVector)
                                        <span class="netkit-btn-vector">Vector</span>
                                    @else
                                        <button
                                            type="button"
                                            class="netkit-btn-primary"
                                            :disabled="isUpscaling('{{ $imageKey }}')"
                                            @click="upsizeImage('{{ $imageKey }}', imageUrl('{{ $imageKey }}', @js($url)), {{ (int) $request->id }}, {{ (int) $imageIndex }})"
                                            x-text="isUpscaling('{{ $imageKey }}') ? 'Upsizing...' : 'Upsize'"
                                        ></button>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                @endforeach
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Preview</th>
                            <th class="px-4 py-3 text-left font-semibold">Name</th>
                            <th class="px-4 py-3 text-left font-semibold">Prompt</th>
                            <th class="px-4 py-3 text-left font-semibold">Generator</th>
                            <th class="px-4 py-3 text-left font-semibold">Cost</th>
                            <th class="px-4 py-3 text-left font-semibold">Type</th>
                            <th class="px-4 py-3 text-left font-semibold">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($this->requests as $request)
                            @php
                                $urls = is_array($request->image_urls) ? array_values(array_filter($request->image_urls)) : [];
                                $cover = $urls[0] ?? null;
                                $title = $request->domain ?: 'Untitled';
                            @endphp
                            <tr>
                                <td class="px-4 py-3">
                                    @if ($cover)
                                        <button type="button" @click="openPreview(@js($cover), @js($title))" class="block">
                                            <img src="{{ $cover }}" alt="{{ $title }}" class="h-12 w-12 rounded-md border border-gray-200 object-contain" loading="lazy" />
                                        </button>
                                    @else
                                        <div class="h-12 w-12 rounded-md border border-gray-200 bg-gray-100"></div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    @if ($this->editingRequestId === (int) $request->id)
                                        <div class="flex items-center gap-2">
                                            <input
                                                type="text"
                                                wire:model="editingName"
                                                class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                                            />
                                            <button wire:click="saveRename({{ $request->id }})" class="rounded-lg bg-gray-900 px-2 py-1.5 text-xs font-semibold text-white">Save</button>
                                            <button wire:click="cancelRename" class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs font-semibold text-gray-600">Cancel</button>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-2">
                                            <span>{{ $request->domain ?: '-' }}</span>
                                            <button wire:click="startRename({{ $request->id }})" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-600">Edit</button>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    <span class="block max-w-md truncate">{{ $request->original_prompt ?: 'Prompt hidden' }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ $this->modelLabel($request->model) }}</td>
                                <td class="px-4 py-3 text-gray-600">${{ number_format((float) ($request->latest_cost_usd ?? 0), 4) }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ strtoupper((string) ($request->output_format ?? 'image')) }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ optional($request->created_at)->format('M d, Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div>
            {{ $this->requests->links() }}
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
