<x-filament-panels::page>
    {{-- Styles: resources/css/user-portal.css (nk-* classes, "Images" block).
         One item per image; per-image state is ai_logo_requests.image_meta. --}}
    @php
        $images = $this->images;
        $inTrash = $this->formatFilter === 'trash';
        $trashCount = $this->trashCount;
        $upscaleCost = '$' . number_format($this->upscaleCost, 2);
        $studioUrl = route('domainSearch.logoStudio');
        $pencil = 'm16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z';
        $trashIcon = 'm14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0';
    @endphp

    <div class="nk-page" x-data="logoGallery()">

        {{-- Call to action --}}
        <section class="nk-card nk-row">
            <div>
                <h3 class="nk-heading">Create more images</h3>
                <p class="nk-muted nk-mt-1">Use the Logo Studio to make raster images, vector marks and brand visuals. Everything you generate lands here.</p>
            </div>
            <a href="{{ $studioUrl }}" class="nk-btn nk-btn-primary nk-btn-auto">
                <x-filament::icon icon="heroicon-m-sparkles" class="nk-btn-icon" />
                Open Logo Generator
            </a>
        </section>

        {{-- Toolbar --}}
        <div class="nk-row">
            <p class="nk-muted">
                @if ($inTrash)
                    {{ number_format($images->total()) }} in Trash · deleted permanently after {{ \App\Models\AiLogoRequest::TRASH_DAYS }} days
                @else
                    {{ number_format($images->total()) }} {{ $images->total() === 1 ? 'image' : 'images' }}
                @endif
            </p>
            <div class="nk-toolbar">
                <div class="nk-tabs" role="tablist" aria-label="Show">
                    @foreach (['all' => 'All', 'raster' => 'Raster', 'vector' => 'Vector'] as $key => $label)
                        <button
                            type="button"
                            role="tab"
                            aria-selected="{{ $this->formatFilter === $key ? 'true' : 'false' }}"
                            wire:click="setFormatFilter('{{ $key }}')"
                            @class(['nk-tab', 'is-active' => $this->formatFilter === $key])
                        >{{ $label }}</button>
                    @endforeach
                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $inTrash ? 'true' : 'false' }}"
                        wire:click="setFormatFilter('trash')"
                        @class(['nk-tab', 'nk-tab-with-count', 'is-active' => $inTrash])
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $trashIcon }}" /></svg>
                        Trash
                        @if ($trashCount > 0)
                            <span class="nk-tab-count">{{ $trashCount }}</span>
                        @endif
                    </button>
                </div>
                <div class="nk-tabs" role="tablist" aria-label="Layout">
                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $this->viewMode === 'grid' ? 'true' : 'false' }}"
                        aria-label="Grid"
                        title="Grid"
                        wire:click="setViewMode('grid')"
                        @class(['nk-tab', 'nk-tab-icon', 'is-active' => $this->viewMode === 'grid'])
                    ><x-filament::icon icon="heroicon-m-squares-2x2" /></button>
                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $this->viewMode === 'table' ? 'true' : 'false' }}"
                        aria-label="List"
                        title="List"
                        wire:click="setViewMode('table')"
                        @class(['nk-tab', 'nk-tab-icon', 'is-active' => $this->viewMode === 'table'])
                    ><x-filament::icon icon="heroicon-m-bars-3" /></button>
                </div>
            </div>
        </div>

        {{-- Upscale result / error --}}
        <div x-show="notice" x-cloak class="nk-callout nk-callout-success">
            <x-heroicon-o-check-circle />
            <div style="flex: 1; min-width: 0;">
                <p class="nk-callout-title" x-text="notice"></p>
            </div>
            <button type="button" class="nk-icon-btn" @click="notice = null" aria-label="Dismiss">
                <x-filament::icon icon="heroicon-m-x-mark" />
            </button>
        </div>
        <div x-show="upscaleError" x-cloak class="nk-callout nk-callout-danger">
            <x-heroicon-o-exclamation-triangle />
            <div style="flex: 1; min-width: 0;">
                <p class="nk-callout-title">Upsize failed</p>
                <p class="nk-callout-body" x-text="upscaleError"></p>
            </div>
            <button type="button" class="nk-icon-btn" @click="upscaleError = null" aria-label="Dismiss error">
                <x-filament::icon icon="heroicon-m-x-mark" />
            </button>
        </div>

        @if ($inTrash && $images->total() > 0)
            <div class="nk-row">
                <p class="nk-small">Images in Trash are hidden everywhere else. Restore one to bring it back.</p>
                <button
                    type="button"
                    class="nk-btn nk-btn-danger nk-btn-sm nk-btn-auto"
                    wire:click="emptyTrash"
                    wire:confirm="Permanently delete all {{ $images->total() }} images in Trash? This cannot be undone."
                >
                    Empty Trash
                </button>
            </div>
        @endif

        @if ($images->total() === 0)
            @php
                $availableBalance = (float) (auth()->user()?->credit_balance ?? 0);
            @endphp

            <section
                class="nk-card nk-empty"
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
                @if ($inTrash)
                    <svg class="nk-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $trashIcon }}" /></svg>
                    <p class="nk-strong">Trash is empty</p>
                    <p class="nk-muted nk-mt-1">Deleted images stay here for {{ \App\Models\AiLogoRequest::TRASH_DAYS }} days before they are removed for good.</p>
                @else
                    <x-filament::icon icon="heroicon-o-photo" class="nk-empty-icon" />
                    @if ($availableBalance <= 0 && $this->formatFilter === 'all')
                        <p class="nk-strong">You'll need credits to generate images</p>
                        <p class="nk-muted nk-mt-1">Add credits to your balance, then open the Logo Studio.</p>
                        <p class="nk-error nk-mt-2" x-show="error" x-cloak x-text="error"></p>
                        <button type="button" class="nk-btn nk-btn-primary nk-btn-auto nk-mt-4" x-on:click="buyCredits()" x-bind:disabled="loading">
                            <x-filament::icon icon="heroicon-m-credit-card" class="nk-btn-icon" />
                            <span x-text="loading ? 'Starting checkout…' : 'Buy credits'">Buy credits</span>
                        </button>
                    @elseif ($this->formatFilter !== 'all')
                        <p class="nk-strong">No {{ $this->formatFilter }} images yet</p>
                        <p class="nk-muted nk-mt-1">Switch to All to see everything you've generated.</p>
                    @else
                        <p class="nk-strong">No images yet</p>
                        <p class="nk-muted nk-mt-1">Open the Logo Studio to make your first one.</p>
                    @endif
                @endif
            </section>
        @elseif ($this->viewMode === 'grid')
            <div class="nk-gallery">
                @foreach ($images as $image)
                    @php
                        $key = $image['key'];
                        $title = $image['name'] ?: 'Untitled';
                        $created = $image['created_at'];
                        $up = $image['upscaled'];
                    @endphp

                    <article class="nk-img-card" wire:key="card-{{ $key }}">
                        <div class="nk-img-media">
                        <button
                            type="button"
                            class="nk-img-frame"
                            aria-label="Preview {{ $title }}"
                            @click="openPreview(@js($image['original_url']), @js($title))"
                        >
                            <img src="{{ $image['preview_url'] }}" alt="{{ $title }}" loading="lazy" x-on:error="imageLoadFallback($event, @js($image['original_url']))" />
                            <span class="nk-img-format">{{ $image['vector'] ? 'Vector' : 'Raster' }}</span>
                            @if ($up)
                                <span class="nk-img-upscaled" title="Upscaled {{ $up['factor'] ?? 2 }}×">
                                    <x-filament::icon icon="heroicon-m-arrows-pointing-out" />
                                    Upscaled @if (!empty($up['width'])) · {{ $up['width'] }} × {{ $up['height'] }} @endif
                                </span>
                            @endif
                        </button>
                        @unless ($inTrash)
                            @include('user-portal.partials.image-menu', ['image' => $image, 'class' => 'nk-img-gear'])
                        @endunless
                        </div>

                        <div class="nk-img-body">
                            @if ($this->editingImageKey === $key)
                                <div class="nk-rename">
                                    <input
                                        type="text"
                                        class="nk-input nk-input-sm"
                                        wire:model="editingName"
                                        wire:keydown.enter="saveRename({{ $image['id'] }}, {{ $image['index'] }})"
                                        wire:keydown.escape="cancelRename"
                                        aria-label="Image name"
                                        autofocus
                                    />
                                    <button type="button" class="nk-btn nk-btn-primary nk-btn-sm" wire:click="saveRename({{ $image['id'] }}, {{ $image['index'] }})">Save</button>
                                    <button type="button" class="nk-btn nk-btn-outline nk-btn-sm" wire:click="cancelRename">Cancel</button>
                                </div>
                                @error('editingName') <p class="nk-error nk-mt-1">{{ $message }}</p> @enderror
                            @else
                                <div class="nk-img-title-row">
                                    <p @class(['nk-img-title', 'is-untitled' => !$image['name']])>{{ $title }}</p>
                                    @unless ($inTrash)
                                        <button type="button" class="nk-icon-btn" wire:click="startRename({{ $image['id'] }}, {{ $image['index'] }})" aria-label="Rename image" title="Rename">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $pencil }}" /></svg>
                                        </button>
                                        <button type="button" class="nk-icon-btn nk-icon-btn-danger" wire:click="trashImage({{ $image['id'] }}, {{ $image['index'] }})" aria-label="Move to Trash" title="Delete (moves to Trash)">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $trashIcon }}" /></svg>
                                        </button>
                                    @endunless
                                </div>
                            @endif

                            <p class="nk-img-prompt" title="{{ $image['prompt'] }}">{{ $image['prompt'] ?: 'Prompt hidden' }}</p>

                            <div class="nk-img-meta">
                                <span class="nk-badge nk-badge-zinc">{{ $image['generator'] }}</span>
                                @if ($image['cost'] !== null)
                                    <span class="nk-badge nk-badge-zinc" title="What this image cost">${{ number_format($image['cost'], 4) }}</span>
                                @endif
                                @if ($inTrash)
                                    <span class="nk-img-date" title="Deleted permanently on {{ $image['purge_on']->format('M j, Y') }}">
                                        {{ max(0, (int) ceil(now()->diffInDays($image['purge_on'], false))) }} days left
                                    </span>
                                @else
                                    <time class="nk-img-date" datetime="{{ $created?->toIso8601String() }}" title="{{ $created?->format('M j, Y g:i a') }}">
                                        {{ $created?->isToday() ? $created->format('g:i a') : $created?->format('M j') }}
                                    </time>
                                @endif
                            </div>

                            @if ($inTrash)
                                <div class="nk-img-actions">
                                    <button type="button" class="nk-btn nk-btn-outline nk-btn-sm" wire:click="restoreImage({{ $image['id'] }}, {{ $image['index'] }})">
                                        <x-filament::icon icon="heroicon-m-arrow-uturn-left" class="nk-btn-icon" />
                                        Restore
                                    </button>
                                    <button
                                        type="button"
                                        class="nk-btn nk-btn-danger nk-btn-sm"
                                        wire:click="purgeImage({{ $image['id'] }}, {{ $image['index'] }})"
                                        wire:confirm="Permanently delete this image? This cannot be undone."
                                    >
                                        Delete permanently
                                    </button>
                                </div>
                            @else
                                <div class="nk-img-actions">
                                    <a href="{{ $image['original_url'] }}" download class="nk-btn nk-btn-outline nk-btn-sm">
                                        <x-filament::icon icon="heroicon-m-arrow-down-tray" class="nk-btn-icon" />
                                        Download
                                    </a>
                                    @if ($image['vector'])
                                        <span class="nk-btn nk-btn-ghost nk-btn-sm" title="Vectors scale to any size already">
                                            <x-filament::icon icon="heroicon-m-check" class="nk-btn-icon" />
                                            Scalable
                                        </span>
                                    @elseif ($up)
                                        @if (!empty($up['original_url']))
                                            <a href="{{ $up['original_url'] }}" download class="nk-btn nk-btn-ghost nk-btn-sm" title="The image as it was before upscaling">
                                                Original
                                            </a>
                                        @else
                                            <span class="nk-btn nk-btn-ghost nk-btn-sm">Upscaled</span>
                                        @endif
                                    @else
                                        <div class="nk-upsize" x-data="{ confirming: false }" @click.outside="confirming = false">
                                            <button
                                                type="button"
                                                class="nk-btn nk-btn-primary nk-btn-sm"
                                                x-show="!confirming && !isUpscaling('{{ $key }}')"
                                                @click="confirming = true"
                                                title="Upscale to 2× resolution"
                                            >
                                                <x-filament::icon icon="heroicon-m-arrows-pointing-out" class="nk-btn-icon" />
                                                Upsize · {{ $upscaleCost }}
                                            </button>
                                            <button
                                                type="button"
                                                class="nk-btn nk-btn-green nk-btn-sm"
                                                x-show="confirming && !isUpscaling('{{ $key }}')"
                                                x-cloak
                                                @click="confirming = false; upsizeImage('{{ $key }}', @js($image['original_url']), {{ $image['id'] }}, {{ $image['index'] }})"
                                            >
                                                Pay {{ $upscaleCost }}
                                            </button>
                                            <button type="button" class="nk-btn nk-btn-primary nk-btn-sm" x-show="isUpscaling('{{ $key }}')" x-cloak disabled>
                                                Upsizing…
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <section class="nk-card nk-card-flush nk-scroll">
                <table class="nk-table nk-table-padded">
                    <thead>
                        <tr>
                            <th>Preview</th>
                            <th>Name</th>
                            <th>Prompt</th>
                            <th>Generator</th>
                            <th class="nk-right">Cost</th>
                            <th>Type</th>
                            <th>{{ $inTrash ? 'Deleted for good' : 'Created' }}</th>
                            <th class="nk-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($images as $image)
                            @php
                                $key = $image['key'];
                                $title = $image['name'] ?: 'Untitled';
                                $up = $image['upscaled'];
                            @endphp
                            <tr wire:key="row-{{ $key }}">
                                <td>
                                    <button type="button" class="nk-img-thumb" aria-label="Preview {{ $title }}" @click="openPreview(@js($image['original_url']), @js($title))">
                                        <img src="{{ $image['preview_url'] }}" alt="" loading="lazy" x-on:error="imageLoadFallback($event, @js($image['original_url']))" />
                                    </button>
                                </td>
                                <td class="nk-strong">
                                    @if ($this->editingImageKey === $key)
                                        <div class="nk-rename">
                                            <input
                                                type="text"
                                                class="nk-input nk-input-sm"
                                                wire:model="editingName"
                                                wire:keydown.enter="saveRename({{ $image['id'] }}, {{ $image['index'] }})"
                                                wire:keydown.escape="cancelRename"
                                                aria-label="Image name"
                                                autofocus
                                            />
                                            <button type="button" class="nk-btn nk-btn-primary nk-btn-sm" wire:click="saveRename({{ $image['id'] }}, {{ $image['index'] }})">Save</button>
                                            <button type="button" class="nk-btn nk-btn-outline nk-btn-sm" wire:click="cancelRename">Cancel</button>
                                        </div>
                                    @else
                                        <div class="nk-img-title-row">
                                            <span @class(['nk-nowrap', 'nk-muted' => !$image['name']])>{{ $title }}</span>
                                            @unless ($inTrash)
                                                <button type="button" class="nk-icon-btn" wire:click="startRename({{ $image['id'] }}, {{ $image['index'] }})" aria-label="Rename image" title="Rename">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $pencil }}" /></svg>
                                                </button>
                                            @endunless
                                        </div>
                                    @endif
                                </td>
                                <td><span class="nk-truncate" title="{{ $image['prompt'] }}">{{ $image['prompt'] ?: 'Prompt hidden' }}</span></td>
                                <td>{{ $image['generator'] }}</td>
                                <td class="nk-right">{{ $image['cost'] !== null ? '$' . number_format($image['cost'], 4) : '—' }}</td>
                                <td>
                                    <span class="nk-badge nk-badge-zinc">{{ $image['vector'] ? 'Vector' : 'Raster' }}</span>
                                    @if ($up)
                                        <span class="nk-badge nk-badge-lime nk-badge-block" title="Upscaled {{ $up['factor'] ?? 2 }}×">Upscaled @if (!empty($up['width'])) {{ $up['width'] }}×{{ $up['height'] }} @endif</span>
                                    @endif
                                </td>
                                <td class="nk-nowrap" title="{{ $inTrash ? '' : $image['created_at']?->format('M j, Y g:i a') }}">
                                    {{ $inTrash ? $image['purge_on']->format('M j, Y') : $image['created_at']?->format('M j, Y') }}
                                </td>
                                <td>
                                    <div class="nk-row-actions">
                                        @if ($inTrash)
                                            <button type="button" class="nk-btn nk-btn-outline nk-btn-sm nk-btn-auto" wire:click="restoreImage({{ $image['id'] }}, {{ $image['index'] }})">Restore</button>
                                            <button type="button" class="nk-btn nk-btn-danger nk-btn-sm nk-btn-auto" wire:click="purgeImage({{ $image['id'] }}, {{ $image['index'] }})" wire:confirm="Permanently delete this image? This cannot be undone.">Delete permanently</button>
                                        @else
                                            @include('user-portal.partials.image-menu', ['image' => $image, 'class' => 'nk-row-gear'])
                                            <a href="{{ $image['original_url'] }}" download class="nk-icon-btn" aria-label="Download" title="Download">
                                                <x-filament::icon icon="heroicon-m-arrow-down-tray" />
                                            </a>
                                            <button type="button" class="nk-icon-btn nk-icon-btn-danger" wire:click="trashImage({{ $image['id'] }}, {{ $image['index'] }})" aria-label="Move to Trash" title="Delete (moves to Trash)">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $trashIcon }}" /></svg>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        @if ($images->hasPages())
            <x-filament::pagination :paginator="$images" />
        @endif

        {{-- Lightbox --}}
        <div
            x-show="previewUrl"
            x-cloak
            x-transition.opacity
            class="nk-lightbox"
            role="dialog"
            aria-modal="true"
            @click.self="closePreview()"
            @keydown.escape.window="closePreview()"
        >
            <div class="nk-lightbox-inner">
                <div class="nk-lightbox-bar">
                    <p class="nk-lightbox-title" x-text="previewAlt"></p>
                    <a :href="previewUrl" download class="nk-btn nk-btn-sm nk-btn-glass">
                        <x-filament::icon icon="heroicon-m-arrow-down-tray" class="nk-btn-icon" />
                        Download
                    </a>
                    <button type="button" class="nk-btn nk-btn-sm nk-btn-glass" @click="closePreview()">Close</button>
                </div>
                <img :src="previewUrl" :alt="previewAlt" class="nk-lightbox-img" />
            </div>
        </div>
    </div>

    <script>
        function logoGallery() {
            return {
                previewUrl: null,
                previewAlt: '',
                notice: null,
                upscaleError: null,
                upscaling: {},

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

                // The studio reads this stash on load and sets itself up exactly
                // as the image was made (see generator-script applyShowcasePreset).
                openInStudio(preset) {
                    try { sessionStorage.setItem('logo-lab:preset', JSON.stringify(preset)); } catch (e) {}
                    window.location.href = @js($studioUrl);
                },

                async upsizeImage(key, imageUrl, logoRequestId, imageIndex) {
                    if (this.isUpscaling(key)) return;

                    this.notice = null;
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

                        const size = data.width && data.height ? ` to ${data.width} × ${data.height}` : '';
                        const cost = typeof data.cost === 'number' ? ` · $${data.cost.toFixed(2)} charged` : '';
                        this.notice = `Image upscaled${size}${cost}.`;
                        // The card re-renders from the server: new file, "Upscaled" badge.
                        await this.$wire.$refresh();
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
