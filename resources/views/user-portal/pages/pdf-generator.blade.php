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
        <section class="nk-card nk-row">
            <div>
                <h3 class="nk-heading">Create or edit a PDF</h3>
                <p class="nk-muted nk-mt-1">Upload a file or start a blank one, then edit, fill, sign, split or convert it.</p>
            </div>
            <a href="{{ route('documents.index') }}" class="nk-btn nk-btn-primary nk-btn-auto">
                <x-filament::icon icon="heroicon-m-document-plus" class="nk-btn-icon" />
                Open PDF editor
            </a>
        </section>

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
