<x-filament-widgets::widget class="fi-wi-table">
    <style>
        .netkit-pdf-toolbar {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 12px;
        }

        @media (min-width: 768px) {
            .netkit-pdf-toolbar {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .netkit-pdf-toolbar__title {
            color: rgb(15 23 42);
            font-size: 18px;
            font-weight: 700;
        }

        .dark .netkit-pdf-toolbar__title {
            color: white;
        }

        .netkit-pdf-toolbar__meta {
            margin-top: 2px;
            color: rgb(100 116 139);
            font-size: 13px;
        }

        .dark .netkit-pdf-toolbar__meta {
            color: rgb(156 163 175);
        }

        .netkit-pdf-seg {
            display: inline-flex;
            gap: 2px;
            padding: 4px;
            border: 1px solid rgb(226 232 240);
            border-radius: 10px;
            background: white;
        }

        .dark .netkit-pdf-seg {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }

        .netkit-pdf-seg__btn {
            border-radius: 7px;
            padding: 6px 14px;
            color: rgb(71 85 105);
            font-size: 13px;
            font-weight: 600;
            transition: background 140ms ease, color 140ms ease;
        }

        .netkit-pdf-seg__btn:hover {
            color: rgb(15 23 42);
        }

        .dark .netkit-pdf-seg__btn {
            color: rgb(148 163 184);
        }

        .dark .netkit-pdf-seg__btn:hover {
            color: white;
        }

        .netkit-pdf-seg__btn.is-active {
            background: rgb(15 23 42);
            color: white;
        }

        .dark .netkit-pdf-seg__btn.is-active {
            background: white;
            color: rgb(2 6 23);
        }

        .netkit-pdf-board {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(245px, 1fr));
            gap: 16px;
        }

        .netkit-pdf-card {
            position: relative;
            display: grid;
            min-height: 302px;
            overflow: hidden;
            border: 1px solid rgba(226, 232, 240, 0.92);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.09);
        }

        .dark .netkit-pdf-card {
            border-color: rgb(31 41 55);
            background: rgb(17 24 39);
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.28);
        }

        .netkit-pdf-card__check {
            position: absolute;
            z-index: 2;
            top: 14px;
            left: 14px;
            width: 16px;
            height: 16px;
            border-radius: 3px;
            accent-color: rgb(67 56 202);
        }

        .netkit-pdf-card__preview {
            display: grid;
            min-height: 154px;
            place-items: center;
            overflow: hidden;
            padding: 16px 18px;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.9), rgba(236, 241, 247, 0.95));
        }

        .dark .netkit-pdf-card__preview {
            background: linear-gradient(180deg, rgb(15 23 42), rgb(3 7 18));
        }

        .netkit-pdf-card__frame {
            display: grid;
            width: 100%;
            min-height: 120px;
            place-items: center;
            overflow: hidden;
            border-radius: 8px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(245, 247, 252, 0.96));
        }

        .dark .netkit-pdf-card__frame {
            background: rgb(15 23 42);
        }

        .netkit-pdf-card__image {
            display: block;
            width: auto;
            max-width: 100%;
            height: auto;
            max-height: 120px;
            object-fit: contain;
            border-radius: 3px;
            background: white;
            box-shadow: 0 14px 22px rgba(15, 23, 42, 0.16);
        }

        .netkit-pdf-card__paper {
            position: relative;
            width: 72px;
            height: 96px;
            border-radius: 3px;
            background: white;
            box-shadow: 0 14px 22px rgba(15, 23, 42, 0.16);
        }

        .netkit-pdf-card__paper::before,
        .netkit-pdf-card__paper::after {
            position: absolute;
            right: 10px;
            left: 10px;
            height: 2px;
            content: "";
            background: rgb(203 213 225);
            box-shadow:
                0 8px 0 rgb(203 213 225),
                0 16px 0 rgb(203 213 225),
                0 24px 0 rgb(203 213 225),
                0 32px 0 rgb(203 213 225);
        }

        .netkit-pdf-card__paper::before {
            top: 18px;
        }

        .netkit-pdf-card__paper::after {
            top: 58px;
            right: 24px;
        }

        .netkit-pdf-card__body {
            padding: 16px 14px 18px;
        }

        .netkit-pdf-card__title {
            min-height: 38px;
            color: rgb(17 24 39);
            font-size: 14px;
            font-weight: 700;
            line-height: 19px;
            word-break: break-word;
        }

        .dark .netkit-pdf-card__title {
            color: white;
        }

        .netkit-pdf-card__meta {
            margin-top: 10px;
            color: rgb(100 116 139);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .dark .netkit-pdf-card__meta {
            color: rgb(148 163 184);
        }

        .netkit-pdf-card__actions {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            margin-top: 14px;
        }

        .netkit-pdf-open,
        .netkit-pdf-delete {
            display: inline-flex;
            min-height: 46px;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 700;
            text-align: center;
            transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
        }

        .netkit-pdf-open {
            background: rgb(224 231 255);
            color: rgb(30 64 175);
        }

        .netkit-pdf-open:hover {
            background: rgb(199 210 254);
        }

        .netkit-pdf-delete {
            border: 1px solid rgb(203 213 225);
            background: white;
            color: rgb(15 23 42);
        }

        .netkit-pdf-delete:hover {
            border-color: rgb(248 113 113);
            color: rgb(185 28 28);
        }

        .dark .netkit-pdf-open {
            background: rgba(67, 56, 202, 0.34);
            color: rgb(199 210 254);
        }

        .dark .netkit-pdf-delete {
            border-color: rgb(55 65 81);
            background: rgb(15 23 42);
            color: rgb(229 231 235);
        }

        .netkit-pdf-empty {
            border: 1px dashed rgb(203 213 225);
            border-radius: 8px;
            padding: 34px;
            background: white;
            color: rgb(100 116 139);
            text-align: center;
        }

        .dark .netkit-pdf-empty {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
            color: rgb(156 163 175);
        }
    </style>

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\Widgets\View\WidgetsRenderHook::TABLE_WIDGET_START, scopes: static::class) }}

    <div class="netkit-pdf-toolbar">
        <div>
            <h2 class="netkit-pdf-toolbar__title">Uploaded PDFs</h2>
            <p class="netkit-pdf-toolbar__meta">Open documents in the new PDF editor.</p>
        </div>

        <div class="netkit-pdf-seg" aria-label="PDF view">
            <button
                type="button"
                wire:click="setViewMode('cards')"
                @class(['netkit-pdf-seg__btn', 'is-active' => $this->viewMode === 'cards'])
            >
                Cards
            </button>
            <button
                type="button"
                wire:click="setViewMode('table')"
                @class(['netkit-pdf-seg__btn', 'is-active' => $this->viewMode === 'table'])
            >
                Table
            </button>
        </div>
    </div>

    @if ($this->viewMode === 'table')
        {{ $this->table }}
    @else
        @php
            $records = $this->getTableRecords();
        @endphp

        @if ($records->count())
            <div class="netkit-pdf-board">
                @foreach ($records as $document)
                    @php
                        $previewDataUrl = (!empty($document->preview_image) && !empty($document->preview_image_mime_type))
                            ? ('data:' . $document->preview_image_mime_type . ';base64,' . $document->preview_image)
                            : null;
                        $sizeMb = (int) $document->size_bytes > 0 ? number_format(((int) $document->size_bytes) / (1024 * 1024), 1) : '0.0';
                        $updatedLabel = optional($document->updated_at)->diffForHumans() ?: 'just now';
                    @endphp

                    <article class="netkit-pdf-card">
                        <input type="checkbox" class="netkit-pdf-card__check" aria-label="Select {{ $document->original_name }}">

                        <div class="netkit-pdf-card__preview">
                            <div class="netkit-pdf-card__frame">
                                @if ($previewDataUrl)
                                    <img
                                        class="netkit-pdf-card__image"
                                        src="{{ $previewDataUrl }}"
                                        alt="Preview of {{ $document->original_name }}"
                                        loading="lazy"
                                    >
                                @else
                                    <div class="netkit-pdf-card__paper" aria-hidden="true"></div>
                                @endif
                            </div>
                        </div>

                        <div class="netkit-pdf-card__body">
                            <h3 class="netkit-pdf-card__title">{{ $document->original_name }}</h3>
                            <div class="netkit-pdf-card__meta">Edited {{ strtoupper($updatedLabel) }} &bull; {{ $sizeMb }} MB</div>

                            <div class="netkit-pdf-card__actions">
                                <a href="{{ $this->openUrl($document) }}" target="_blank" rel="noopener" class="netkit-pdf-open">Open</a>
                                <button
                                    type="button"
                                    class="netkit-pdf-delete"
                                    wire:click="deleteDocument({{ (int) $document->id }})"
                                    wire:confirm="Delete this PDF?"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            @if (method_exists($records, 'links'))
                <div class="mt-4">
                    {{ $records->links() }}
                </div>
            @endif
        @else
            <div class="netkit-pdf-empty">No PDFs uploaded yet.</div>
        @endif
    @endif

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\Widgets\View\WidgetsRenderHook::TABLE_WIDGET_END, scopes: static::class) }}
</x-filament-widgets::widget>
