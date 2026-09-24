<x-filament-panels::page>
    {{-- Styles: resources/css/user-portal.css (nk-* classes, "PDF editor" block). --}}
    @php
        $tz = auth()->user()?->displayTimezone() ?? config('app.timezone');
        $usage = $this->usageSummary;
        $documents = $this->documents;
        $history = $this->history;
        $previews = app(\App\Services\DocumentPreviews::class);
        $uploadsPct = min(100, (int) round($usage['uploads_used'] / max(1, $usage['uploads_limit']) * 100));
        $actionsPct = min(100, (int) round($usage['actions_used'] / max(1, $usage['actions_limit']) * 100));
        $actionIcons = [
            'pdf_save' => 'heroicon-o-document-check',
            'word_export' => 'heroicon-o-document-text',
            'excel_export' => 'heroicon-o-table-cells',
            'pdfa_export' => 'heroicon-o-archive-box',
            'split_export' => 'heroicon-o-scissors',
            'pdf_merge' => 'heroicon-o-square-2-stack',
            'pdf_password' => 'heroicon-o-lock-closed',
            'image_export' => 'heroicon-o-photo',
        ];
        $trashIcon = 'm14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0';
        $sizeOf = fn ($bytes) => (int) $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max(1, (int) $bytes) / 1024, 0) . ' KB';
    @endphp

    <div class="nk-page">

        {{-- Call to action --}}
        {{-- Upload straight from the portal: posts to documents.store (same
             quota, probe and duplicate-name rules as the editor's own
             upload) and opens the new document in the editor. --}}
        <section
            class="nk-card nk-row nk-upload-card"
            x-data="nkPdfUpload({
                action: @js(route('documents.store')),
                token: @js(csrf_token()),
                maxKb: @js((int) config('pdf_editor.uploads.max_kb', 20480)),
                returnTo: @js(route('filament.user.pages.pdf-generator')),
            })"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave.prevent="dragging = false"
            x-on:drop.prevent="dragging = false; pick($event.dataTransfer.files)"
            x-bind:class="{ 'is-dragging': dragging }"
        >
            <div>
                <h3 class="nk-heading">Create or edit a PDF</h3>
                <p class="nk-muted nk-mt-1">Upload a PDF (or drop one here) to edit, fill, sign, split or convert it, or start a blank one in the editor.</p>
                <p class="nk-small nk-mt-1" x-show="busy" x-cloak>Uploading <span x-text="fileName"></span>…</p>
                <p class="nk-error nk-mt-1" x-show="error" x-text="error" x-cloak role="alert"></p>
                <div class="nk-upload-dup nk-mt-2" x-show="duplicate" x-cloak>
                    <p class="nk-small" x-text="duplicate?.message"></p>
                    <div class="nk-toolbar nk-mt-2">
                        <a class="nk-btn nk-btn-outline nk-btn-auto" x-bind:href="duplicate?.existing_url">Open existing</a>
                        <button type="button" class="nk-btn nk-btn-primary nk-btn-auto" x-on:click="send(true)">Upload a copy</button>
                        <button type="button" class="nk-btn nk-btn-outline nk-btn-auto" x-on:click="reset()">Cancel</button>
                    </div>
                </div>
            </div>
            <div class="nk-toolbar">
                <input type="file" accept="application/pdf,.pdf" x-ref="file" class="nk-visually-hidden" x-on:change="pick($event.target.files)" aria-label="Choose a PDF to upload">
                <button type="button" class="nk-btn nk-btn-primary nk-btn-auto" x-on:click="$refs.file.click()" x-bind:disabled="busy">
                    <x-filament::icon icon="heroicon-m-arrow-up-tray" class="nk-btn-icon" />
                    <span x-text="busy ? 'Uploading…' : 'Upload PDF'">Upload PDF</span>
                </button>
                <a href="{{ route('documents.index') }}" class="nk-btn nk-btn-outline nk-btn-auto">
                    <x-filament::icon icon="heroicon-m-document-plus" class="nk-btn-icon" />
                    Open PDF editor
                </a>
            </div>
        </section>
        <script>
            window.nkPdfUpload = window.nkPdfUpload || ((cfg) => ({
                busy: false, dragging: false, error: '', duplicate: null, file: null, fileName: '',
                reset() { this.busy = false; this.error = ''; this.duplicate = null; this.file = null; this.fileName = ''; if (this.$refs.file) this.$refs.file.value = ''; },
                pick(files) {
                    const file = files && files[0];
                    if (!file) return;
                    this.reset();
                    if (!/\.pdf$/i.test(file.name) && file.type !== 'application/pdf') { this.error = 'That file is not a PDF.'; return; }
                    if (file.size > cfg.maxKb * 1024) { this.error = `That PDF is larger than ${Math.round(cfg.maxKb / 1024)} MB.`; return; }
                    this.file = file; this.fileName = file.name;
                    this.send(false);
                },
                async send(allowDuplicate) {
                    if (!this.file) return;
                    this.busy = true; this.error = ''; this.duplicate = null;
                    const body = new FormData();
                    body.append('_token', cfg.token);
                    body.append('document', this.file);
                    if (allowDuplicate) body.append('allow_duplicate_name', '1');
                    try {
                        const res = await fetch(cfg.action, { method: 'POST', body, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                        // Success redirects to the editor; fetch follows it.
                        const match = res.ok && res.redirected ? res.url.match(/\/documents\/(\d+)\//) : null;
                        if (match) {
                            const url = new URL(`/documents/${match[1]}/edit-new`, window.location.origin);
                            url.searchParams.set('pdfjs', '1');
                            url.searchParams.set('from', 'admin');
                            url.searchParams.set('return_to', cfg.returnTo);
                            window.location.assign(url.toString());
                            return;
                        }
                        const data = await res.json().catch(() => ({}));
                        this.busy = false;
                        if (res.status === 409 && data.duplicate_name) { this.duplicate = data; return; }
                        this.error = data?.errors?.document?.[0] || data?.message || 'The upload failed. Please try again.';
                    } catch (e) {
                        this.busy = false;
                        this.error = 'The upload failed. Check your connection and try again.';
                    }
                },
            }));
        </script>

        {{-- This month's usage --}}
        <section class="nk-usage">
            <div class="nk-card nk-stat">
                <p class="nk-muted">Uploads · {{ $usage['month'] }}</p>
                <p class="nk-amount nk-mt-1">{{ number_format($usage['uploads_used']) }} <span class="nk-of">/ {{ number_format($usage['uploads_limit']) }}</span></p>
                <div class="nk-meter nk-mt-2" role="progressbar" aria-valuenow="{{ $usage['uploads_used'] }}" aria-valuemax="{{ $usage['uploads_limit'] }}" aria-label="Uploads used"><span style="width: {{ $uploadsPct }}%"></span></div>
                <p class="nk-small nk-mt-2">{{ number_format($usage['uploads_remaining']) }} left this month</p>
            </div>
            <div class="nk-card nk-stat">
                <p class="nk-muted" title="Saves, splits, conversions and exports">Editor actions · {{ $usage['month'] }}</p>
                @if ($usage['unlimited_actions'])
                    <p class="nk-amount nk-mt-1">Unlimited</p>
                    <p class="nk-small nk-mt-2"><span class="nk-badge nk-badge-lime">Plan</span> Your plan includes unlimited editor actions</p>
                @else
                    <p class="nk-amount nk-mt-1">{{ number_format($usage['actions_used']) }} <span class="nk-of">/ {{ number_format($usage['actions_limit']) }}</span></p>
                    <div class="nk-meter nk-mt-2" role="progressbar" aria-valuenow="{{ $usage['actions_used'] }}" aria-valuemax="{{ $usage['actions_limit'] }}" aria-label="Editor actions used"><span style="width: {{ $actionsPct }}%"></span></div>
                    <p class="nk-small nk-mt-2">{{ number_format($usage['actions_remaining']) }} left this month</p>
                @endif
            </div>
        </section>

        {{-- Documents --}}
        <div class="nk-row">
            <div>
                <h3 class="nk-heading">Your PDFs</h3>
                <p class="nk-muted nk-mt-1">{{ number_format($documents->total()) }} {{ $documents->total() === 1 ? 'document' : 'documents' }}</p>
            </div>
            <div class="nk-toolbar">
                <label class="nk-search">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" />
                    <input type="search" class="nk-input" wire:model.live.debounce.300ms="term" placeholder="Search PDFs" aria-label="Search PDFs">
                </label>
                <div class="nk-tabs" role="tablist" aria-label="Layout">
                    <button type="button" role="tab" aria-label="Cards" title="Cards" wire:click="setViewMode('cards')" aria-selected="{{ $viewMode === 'cards' ? 'true' : 'false' }}" @class(['nk-tab', 'nk-tab-icon', 'is-active' => $viewMode === 'cards'])><x-filament::icon icon="heroicon-m-squares-2x2" /></button>
                    <button type="button" role="tab" aria-label="List" title="List" wire:click="setViewMode('list')" aria-selected="{{ $viewMode === 'list' ? 'true' : 'false' }}" @class(['nk-tab', 'nk-tab-icon', 'is-active' => $viewMode === 'list'])><x-filament::icon icon="heroicon-m-bars-3" /></button>
                </div>
            </div>
        </div>

        @if ($documents->isEmpty())
            <section class="nk-card nk-empty">
                <x-filament::icon icon="heroicon-o-document-text" class="nk-empty-icon" />
                @if ($term !== '')
                    <p class="nk-strong">No PDFs match “{{ $term }}”</p>
                @else
                    <p class="nk-strong">No PDFs yet</p>
                    <p class="nk-muted nk-mt-1">Open the PDF editor to upload one or start from a blank page.</p>
                @endif
            </section>
        @elseif ($viewMode === 'cards')
            <div class="nk-docs">
                @foreach ($documents as $document)
                    @php
                        $preview = $previews->url($document);
                        $openUrl = $this->openUrl($document);
                        $edited = $document->updated_at?->copy()->timezone($tz);
                    @endphp
                    <article class="nk-doc-card" wire:key="doc-{{ $document->id }}">
                        <a href="{{ $openUrl }}" target="_blank" rel="noopener" class="nk-doc-frame" aria-label="Open {{ $document->original_name }}">
                            @if ($preview)
                                <img src="{{ $preview }}" alt="" loading="lazy">
                            @else
                                <span class="nk-doc-paper" aria-hidden="true"></span>
                            @endif
                        </a>
                        <div class="nk-img-body">
                            <div class="nk-img-title-row">
                                <p class="nk-img-title" title="{{ $document->original_name }}">{{ $document->original_name }}</p>
                                <button type="button" class="nk-icon-btn nk-icon-btn-danger" wire:click="deleteDocument({{ $document->id }})" wire:confirm="Delete “{{ $document->original_name }}”? This cannot be undone." aria-label="Delete PDF" title="Delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $trashIcon }}" /></svg>
                                </button>
                            </div>
                            <p class="nk-small nk-mt-1" title="{{ $edited?->format('M j, Y g:i a') }}">Edited {{ $document->updated_at?->diffForHumans() }} · {{ $sizeOf($document->size_bytes) }}</p>
                            <a href="{{ $openUrl }}" target="_blank" rel="noopener" class="nk-btn nk-btn-outline nk-btn-sm nk-mt-4">
                                Open in editor
                                <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="nk-btn-icon" />
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <section class="nk-card nk-card-flush nk-scroll">
                <table class="nk-table nk-table-padded">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Size</th>
                            <th>Edited</th>
                            <th>Uploaded</th>
                            <th class="nk-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            @php $openUrl = $this->openUrl($document); @endphp
                            <tr wire:key="row-{{ $document->id }}">
                                <td class="nk-strong"><a href="{{ $openUrl }}" target="_blank" rel="noopener" class="nk-doc-link">{{ $document->original_name }}</a></td>
                                <td class="nk-nowrap">{{ $sizeOf($document->size_bytes) }}</td>
                                <td class="nk-nowrap" title="{{ $document->updated_at?->copy()->timezone($tz)->format('M j, Y g:i a') }}">{{ $document->updated_at?->diffForHumans() }}</td>
                                <td class="nk-nowrap">{{ $document->created_at?->copy()->timezone($tz)->format('M j, Y') }}</td>
                                <td>
                                    <div class="nk-row-actions">
                                        <a href="{{ $openUrl }}" target="_blank" rel="noopener" class="nk-btn nk-btn-outline nk-btn-sm nk-btn-auto">Open</a>
                                        <button type="button" class="nk-icon-btn nk-icon-btn-danger" wire:click="deleteDocument({{ $document->id }})" wire:confirm="Delete “{{ $document->original_name }}”? This cannot be undone." aria-label="Delete PDF" title="Delete">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $trashIcon }}" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        @if ($documents->hasPages())
            <x-filament::pagination :paginator="$documents" />
        @endif

        {{-- Editor action history --}}
        <section class="nk-card">
            <h3 class="nk-heading">Editor actions</h3>
            <p class="nk-muted nk-mt-1">Saves, splits, conversions and exports from the PDF editor.</p>

            @if ($history->isEmpty())
                <div class="nk-empty">
                    <x-filament::icon icon="heroicon-o-clock" class="nk-empty-icon" />
                    <p class="nk-muted">Nothing yet. Actions you take in the editor show up here.</p>
                </div>
            @else
                @php $charges = \App\Models\UserActivity::chargesFor($history->getCollection()); @endphp
                <ul class="nk-feed-list nk-mt-4">
                    @foreach ($history as $activity)
                        @php $at = $activity->created_at?->copy()->timezone($tz); @endphp
                        <li wire:key="act-{{ $activity->id }}">
                            <div class="nk-feed-item is-static">
                                <span class="nk-kind-icon nk-kind-pdf"><x-filament::icon :icon="$actionIcons[$activity->category] ?? 'heroicon-o-document'" /></span>
                                <span class="nk-feed-body">
                                    <span class="nk-feed-title">
                                        {{ $activity->action }}
                                        @if ($activity->status !== 'success')
                                            <span class="nk-badge nk-badge-red nk-ml-2">Failed</span>
                                        @endif
                                    </span>
                                    <span class="nk-feed-detail">{{ $activity->document?->original_name ?? 'Deleted document' }}</span>
                                </span>
                                @if (isset($charges[$activity->id]))
                                    <span class="nk-feed-cost" title="Charged to your balance">${{ number_format($charges[$activity->id], 2) }}</span>
                                @endif
                                <time class="nk-feed-time" datetime="{{ $at?->toIso8601String() }}" title="{{ $at?->format('M j, Y g:i:s a') }}">
                                    {{ $at?->isToday() ? $at->format('g:i a') : $at?->format('M j, Y') }}
                                </time>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($history->hasPages())
                    <div class="nk-mt-4">
                        <x-filament::pagination :paginator="$history" />
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-filament-panels::page>
