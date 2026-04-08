<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $document->original_name }} — Edit New</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js"></script>
    <script>
        if (typeof pdfjsLib !== 'undefined') {
            pdfjsLib.GlobalWorkerOptions.workerSrc =
                'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
        }
    </script>
    {{-- REWRITTEN: uses --scale CSS variable (pdf.net approach) for pixel-perfect overlay positioning --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; font-family: system-ui, sans-serif; }

        .top-bar {
            position: sticky; top: 0; z-index: 40;
            background: #fff; border-bottom: 1px solid #e5e7eb;
            padding: 10px 20px; display: flex; align-items: center; gap: 12px;
        }
        .top-bar h1 { margin: 0; font-size: 15px; font-weight: 600; color: #111827; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .top-bar a  { font-size: 13px; color: #6b7280; text-decoration: none; white-space: nowrap; }
        .top-bar a:hover { color: #111827; }

        #pages-wrap { padding: 24px 20px; max-width: 1100px; margin: 0 auto; display: flex; flex-direction: column; gap: 24px; align-items: center; }

        .page-card { background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); overflow: hidden; width: 100%; }
        .page-label {
            padding: 8px 16px; border-bottom: 1px solid #f3f4f6;
            font-size: 12px; font-weight: 500; color: #6b7280;
            display: flex; align-items: center; justify-content: space-between;
        }
        .page-canvas-wrap { display: flex; justify-content: center; background: #e5e7eb; padding: 16px; }

        /*
         * .page-content is sized in PDF points × --scale, exactly like pdf.net.
         *   width:  calc(wPts * var(--scale, 1))
         *   height: calc(hPts * var(--scale, 1))
         * The img fills it; the ann-overlay covers it absolutely.
         */
        .page-content {
            position: relative;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
            line-height: 0;
        }
        .page-content img { display: block; width: 100%; height: 100%; object-fit: fill; }

        /* Overlay covers page-content; annotation divs are positioned via transform */
        .ann-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            overflow: visible; pointer-events: none;
        }

        /*
         * .ann-wrap       — positioned via translate over the clean PDF background
         * .ann-drag-handle — drag tab shown on hover to reposition
         * .ann-field      — always visible, white background, shows the annotation text
         */
        .ann-wrap {
            position: absolute; top: 0; left: 0;
            pointer-events: all;
        }
        .ann-drag-handle {
            position: absolute; bottom: 100%; left: -1px;
            height: 16px; padding: 0 6px;
            background: #3b82f6; color: #fff;
            border-radius: 3px 3px 0 0;
            font-size: 10px; line-height: 16px; white-space: nowrap;
            cursor: grab; user-select: none;
            display: none; align-items: center; gap: 4px;
            z-index: 20;
        }
        .ann-drag-handle:active { cursor: grabbing; }
        .ann-wrap:hover .ann-drag-handle,
        .ann-wrap.active .ann-drag-handle { display: flex; }
        .ann-field {
            display: block; cursor: text;
            background: #fff; color: inherit;
            border: 1.5px solid rgba(59,130,246,.3); border-radius: 2px;
            outline: none; overflow: hidden; white-space: pre-wrap;
            transition: border-color .12s, box-shadow .12s;
        }
        .ann-wrap:hover .ann-field                    { border-color: rgba(59,130,246,.7); }
        .ann-wrap.active .ann-field, .ann-field:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,.25); }
        #error-banner { background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 12px 16px; font-size: 13px; color: #dc2626; display: none; }
        #save-toast { position: fixed; bottom: 20px; right: 20px; background: #1e293b; color: #fff; font-size: 13px; padding: 8px 16px; border-radius: 8px; opacity: 0; transition: opacity .2s; pointer-events: none; z-index: 100; }
        #save-toast.show { opacity: 1; }
    </style>
</head>
<body>

<div class="top-bar">
    <h1>{{ $document->original_name }}</h1>
    <a href="{{ route('documents.edit', $document) }}">← Edit</a>
    <a href="{{ route('documents.index') }}">All documents</a>
</div>

<div id="pages-wrap">
    <div id="error-banner"></div>
</div>
<div id="save-toast">Saved</div>

<script>
(function () {
    const CSRF        = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const INFO_URL    = '{{ route('pdfTests.documentInfo', $document) }}';
    const SAVE_URL    = '{{ route('documents.saveAnnotationState', $document) }}';
    const wrap        = document.getElementById('pages-wrap');
    const errorBanner = document.getElementById('error-banner');
    const saveToast   = document.getElementById('save-toast');

    let _pdfDoc  = null;
    const pageImgs = {};   // pi → <img>
    const pageDims = {};   // pi → { wPts, hPts }

    const FONT_MAP = {
        Helvetica:     '"AnnotHelvetica","Arimo",Arial,sans-serif',
        Arial:         '"AnnotHelvetica","Arimo",Arial,sans-serif',
        Verdana:       'Verdana,Geneva,Arial,sans-serif',
        Tahoma:        '"Arimo",Tahoma,Arial,Verdana,sans-serif',
        TahomaUnicode: '"Arimo",Tahoma,Arial,Verdana,sans-serif',
        TimesRoman:    '"TimesRoman","Tinos","Times New Roman",Times,serif',
        TimesNewRoman: '"TimesRoman","Tinos","Times New Roman",Times,serif',
        Times:         '"TimesRoman","Tinos","Times New Roman",Times,serif',
        Palatino:      '"AnnotPalatino","Liberation Serif","Times New Roman",serif',
        BookAntiqua:   '"AnnotPalatino","Liberation Serif","Times New Roman",serif',
        Courier:       '"Courier","Cousine","Courier New",monospace',
        Garamond:      '"AnnotGaramond","EB Garamond",Baskerville,serif',
        Georgia:       '"Georgia","Liberation Serif",serif',
        Calibri:       'Calibri,Arial,sans-serif',
        Roboto:        '"Roboto",Arial,Helvetica,sans-serif',
        Lato:          '"Lato",Arial,Helvetica,sans-serif',
        Montserrat:    '"Montserrat",Arial,Helvetica,sans-serif',
    };
    function resolveCssFont(name) {
        if (!name) return FONT_MAP.Helvetica;
        const k = String(name).replace(/['"]/g, '').trim()
            .replace(/[-_ ]?(regular|bold|italic|oblique|light|medium|condensed|narrow|unicode)$/i, '');
        for (const [key, val] of Object.entries(FONT_MAP)) {
            if (key.toLowerCase() === k.toLowerCase()) return val;
        }
        return FONT_MAP.Helvetica;
    }

    function showError(msg) { errorBanner.textContent = msg; errorBanner.style.display = 'block'; }

    function showToast(msg) {
        saveToast.textContent = msg; saveToast.classList.add('show');
        clearTimeout(saveToast._t);
        saveToast._t = setTimeout(() => saveToast.classList.remove('show'), 2000);
    }

    async function saveAnnotation(ann) {
        const payload = { ...ann };
        delete payload.element;
        try {
            const resp = await fetch(SAVE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                credentials: 'same-origin',
                body: JSON.stringify({ annotations: [payload] }),
            });
            const result = await resp.json().catch(() => ({}));
            showToast(result.success ? 'Saved' : 'Save failed');
        } catch { showToast('Save failed'); }
    }

    // --- Drag-to-move system ---
    let _drag = null;

    function onDragMove(e) {
        if (!_drag) return;
        const dxPts = (e.clientX - _drag.startX) / _drag.scale;
        const dyPts = (e.clientY - _drag.startY) / _drag.scale;
        const newLeft = _drag.startLeftPts + dxPts;
        const newTop  = _drag.startTopPts  + dyPts;
        _drag.wrap.dataset.leftPts = String(newLeft);
        _drag.wrap.dataset.topPts  = String(newTop);
        _drag.wrap.style.transform = `translate(calc(${newLeft}px * var(--scale, 1)), calc(${newTop}px * var(--scale, 1)))`;
    }

    function onDragEnd() {
        if (!_drag) return;
        const newLeft = Number(_drag.wrap.dataset.leftPts);
        const newTop  = Number(_drag.wrap.dataset.topPts);
        // Reconvert top-down topPts back to bottom-up pdfY
        _drag.ann.pdfX = newLeft;
        _drag.ann.pdfY = _drag.pageHeightPts - newTop - Number(_drag.ann.pdfHeight);
        _drag.wrap.classList.remove('dragging');
        saveAnnotation(_drag.ann);
        _drag = null;
        document.removeEventListener('mousemove', onDragMove);
        document.removeEventListener('mouseup',   onDragEnd);
    }

    function makeDraggable(handle, wrap, ann, overlayEl, pageHeightPts) {
        handle.addEventListener('mousedown', e => {
            e.preventDefault();
            const scale = parseFloat(overlayEl.style.getPropertyValue('--scale')) || 1;
            _drag = {
                ann, wrap, pageHeightPts, scale,
                startX: e.clientX, startY: e.clientY,
                startLeftPts: Number(wrap.dataset.leftPts),
                startTopPts:  Number(wrap.dataset.topPts),
            };
            wrap.classList.add('dragging');
            document.addEventListener('mousemove', onDragMove);
            document.addEventListener('mouseup',   onDragEnd);
        });
    }

    /*
     * Build annotation overlays.
     * Structure: .ann-wrap (positioned via translate) > .ann-drag-handle + .ann-field
     * .ann-field is transparent by default so the Python writer PNG shows through.
     * On hover: border-only hint. On focus: white bg + text for editing.
     * Drag the handle to reposition; saves new pdfX/pdfY and re-renders the PNG.
     */
    function buildOverlay(overlayEl, annotations, pageHeightPts) {
        overlayEl.innerHTML = '';
        annotations.forEach(ann => {
            if (!ann || ann.db_state === 'deleted') return;
            if ((ann.type || 'text') !== 'text') return;
            const pdfX      = Number(ann.pdfX)      || 0;
            const pdfY      = Number(ann.pdfY)      || 0;
            const pdfWidth  = Number(ann.pdfWidth)  || 0;
            const pdfHeight = Number(ann.pdfHeight) || 0;
            if (!pdfWidth || !pdfHeight) return;

            const leftPts = pdfX;
            const topPts  = pageHeightPts - pdfY - pdfHeight; // flip bottom-up → top-down

            // Wrapper: provides the position via translate
            const wrap = document.createElement('div');
            wrap.className = 'ann-wrap';
            wrap.dataset.leftPts = String(leftPts);
            wrap.dataset.topPts  = String(topPts);
            wrap.style.transform = `translate(calc(${leftPts}px * var(--scale, 1)), calc(${topPts}px * var(--scale, 1)))`;

            // Drag handle — appears above the field, only when hovering or active
            const handle = document.createElement('div');
            handle.className = 'ann-drag-handle';
            handle.innerHTML = '&#9783; move';
            wrap.appendChild(handle);

            // Text field — transparent hit-target, white bg only when focused for editing
            const div = document.createElement('div');
            div.className = 'ann-field';
            div.contentEditable = 'true';
            div.dataset.dbId = String(ann.db_id);
            div.textContent = ann.text || '';
            div.style.width      = `calc(${pdfWidth}px * var(--scale, 1))`;
            div.style.height     = `calc(${pdfHeight}px * var(--scale, 1))`;
            div.style.fontSize   = `calc(${Math.max(1, Number(ann.fontSize) || 12)}px * var(--scale, 1))`;
            if (Number(ann.lineHeight) > 0) div.style.lineHeight = `calc(${Number(ann.lineHeight)}px * var(--scale, 1))`;
            div.style.fontFamily = resolveCssFont(ann.fontSourceName || ann.fontFamily);
            if (ann.fontWeight) div.style.fontWeight = String(ann.fontWeight);
            if (ann.fontStyle)  div.style.fontStyle  = String(ann.fontStyle);
            div.style.color = ann.textColor || '#000000';
            wrap.appendChild(div);

            div.addEventListener('focus', () => wrap.classList.add('active'));
            div.addEventListener('blur', () => {
                wrap.classList.remove('active');
                const newText = div.textContent || '';
                if (newText === ann.text) return;
                ann.text = newText;
                saveAnnotation(ann);
            });

            makeDraggable(handle, wrap, ann, overlayEl, pageHeightPts);
            overlayEl.appendChild(wrap);
            ann.element = div;
        });
    }

    function createPageCard(pg, total) {
        const card = document.createElement('div');
        card.className = 'page-card';
        card.innerHTML =
            `<div class="page-label"><span>Page ${pg} of ${total}</span></div>` +
            `<div class="page-canvas-wrap"><div class="page-content" id="pc-${pg}">` +
                `<img id="en-img-${pg}" alt="Page ${pg}">` +
                `<div class="ann-overlay" id="ao-${pg}"></div>` +
            `</div></div>`;
        wrap.appendChild(card);
        return card;
    }

    /*
     * ResizeObserver on .page-content: when the container width changes (e.g. window resize),
     * recalculate --scale = newWidth / pageWidthPts and set it on both the page container and overlay.
     * Since all calc() expressions reference var(--scale), everything repositions automatically.
     */
    const scaleObs = new ResizeObserver(entries => {
        for (const entry of entries) {
            const el   = entry.target;
            const pi   = Number(el.dataset.pi);
            const dims = pageDims[pi];
            if (!dims) continue;
            const newScale = entry.contentRect.width / dims.wPts;
            el.style.setProperty('--scale', newScale);
            const overlay = document.getElementById('ao-' + (pi + 1));
            if (overlay) overlay.style.setProperty('--scale', newScale);
        }
    });

    async function run() {
        let data;
        try {
            const resp = await fetch(INFO_URL, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            data = await resp.json();
            if (!data.success) throw new Error(data.message || 'Failed to load document info.');
        } catch (e) { showError(String(e)); return; }

        const annotations = (data.annotations || []).filter(a => a && a.db_state !== 'deleted');
        const byPageAnns  = {};
        annotations.forEach(a => {
            const pi = Number(a.pageIndex) || 0;
            if (!byPageAnns[pi]) byPageAnns[pi] = [];
            byPageAnns[pi].push(a);
        });

        try {
            if (typeof pdfjsLib === 'undefined') throw new Error('PDF.js not loaded.');
            _pdfDoc = await pdfjsLib.getDocument(data.document.clean_url).promise;
        } catch (e) { showError(String(e)); return; }

        const pageCount = _pdfDoc.numPages;
        const cards = [];
        for (let pg = 1; pg <= pageCount; pg++) cards.push(createPageCard(pg, pageCount));

        const offscreen = document.createElement('canvas');

        for (let pg = 1; pg <= pageCount; pg++) {
            const pi        = pg - 1;
            const card      = cards[pi];
            const pageEl    = document.getElementById('pc-' + pg);
            const img       = document.getElementById('en-img-' + pg);
            const overlayEl = document.getElementById('ao-' + pg);
            pageImgs[pi]    = img;
            pageEl.dataset.pi = String(pi);

            // Page dimensions in PDF points at scale=1 (1pt = 1px)
            const pdfPage = await _pdfDoc.getPage(pg);
            const vp1     = pdfPage.getViewport({ scale: 1 });
            const wPts    = vp1.width;
            const hPts    = vp1.height;
            pageDims[pi]  = { wPts, hPts };

            // Initial --scale to fit available width
            const availW    = Math.max(wrap.clientWidth - 32, 200);
            const initScale = availW / wPts;
            pageEl.style.width  = `calc(${wPts}px * var(--scale, 1))`;
            pageEl.style.height = `calc(${hPts}px * var(--scale, 1))`;
            pageEl.style.setProperty('--scale', initScale);
            overlayEl.style.setProperty('--scale', initScale);
            scaleObs.observe(pageEl);

            // Render the clean (redacted) PDF at 2× — this is the background.
            // The ann-field divs on top are always visible with white bg + text.
            const renderScale = 2;
            offscreen.width  = Math.round(wPts * renderScale);
            offscreen.height = Math.round(hPts * renderScale);
            await pdfPage.render({
                canvasContext: offscreen.getContext('2d'),
                viewport:      pdfPage.getViewport({ scale: renderScale }),
            }).promise.catch(() => {});
            await new Promise(res => { img.onload = res; img.onerror = res; img.src = offscreen.toDataURL('image/png'); });

            buildOverlay(overlayEl, byPageAnns[pi] || [], hPts);
        }
    }

    run();
})();
</script>

</body>
</html>
