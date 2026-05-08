// edit-pdfjs spike — PDF.js rendering + click-to-edit + server-side surgical
// content-stream rewrite. See routes/web.php → documents.editPdfjsRewriteTj.

import * as pdfjsLib from 'pdfjs-dist';
import workerSrc from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;

const root = document.getElementById('epj-pages');
const statusEl = document.getElementById('epj-status');
const editBar = document.getElementById('epj-edit-bar');
const editInput = document.getElementById('epj-input');
const editOrig = document.getElementById('epj-orig');
const editApply = document.getElementById('epj-apply');
const editCancel = document.getElementById('epj-cancel');
const downloadBtn = document.getElementById('epj-download');

const PDF_URL = root.dataset.pdfUrl;
const REWRITE_URL = root.dataset.rewriteUrl;
const CSRF = root.dataset.csrf;
const SCALE = 1.5; // CSS px per PDF point

let currentPdfBytes = null; // ArrayBuffer of latest PDF (original or after edit)
let currentDocId = root.dataset.docId;
let pendingEdits = []; // queued edits (we apply one at a time for now)

// In-memory map from each rendered run-key to its descriptor: { page, originalText, occurrence, element }
let activeRun = null;

function setStatus(msg, isError = false) {
    statusEl.textContent = msg;
    statusEl.style.color = isError ? '#900' : '#666';
}

function showError(msg) {
    let el = document.querySelector('.epj-error');
    if (el) el.remove();
    el = document.createElement('div');
    el.className = 'epj-error';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 6000);
}

async function fetchOriginalPdf() {
    setStatus('Fetching PDF…');
    const r = await fetch(PDF_URL, { credentials: 'same-origin' });
    if (!r.ok) throw new Error(`PDF fetch failed: ${r.status}`);
    return await r.arrayBuffer();
}

async function renderAllPages(pdfBytes) {
    setStatus('Rendering…');
    root.innerHTML = '';
    // PDF.js mutates the input ArrayBuffer; clone first.
    const buf = pdfBytes.slice(0);
    const loadingTask = pdfjsLib.getDocument({ data: buf, isEvalSupported: false });
    const pdf = await loadingTask.promise;
    const occurrenceCounter = new Map(); // page → text → next occurrence index
    for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
        const page = await pdf.getPage(pageNum);
        const viewport = page.getViewport({ scale: SCALE });

        const wrapper = document.createElement('div');
        wrapper.className = 'epj-page';
        wrapper.style.width = `${viewport.width}px`;
        wrapper.style.height = `${viewport.height}px`;
        wrapper.dataset.pageIndex = String(pageNum - 1);

        const canvas = document.createElement('canvas');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        wrapper.appendChild(canvas);

        const textLayer = document.createElement('div');
        textLayer.className = 'epj-textlayer';
        textLayer.style.width = `${viewport.width}px`;
        textLayer.style.height = `${viewport.height}px`;
        wrapper.appendChild(textLayer);

        root.appendChild(wrapper);

        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

        const textContent = await page.getTextContent({ disableCombineTextItems: true });
        const counters = occurrenceCounter.get(pageNum - 1) || new Map();

        for (const item of textContent.items) {
            if (!item.str) continue;
            const tx = pdfjsLib.Util.transform(viewport.transform, item.transform);
            // tx = [a, b, c, d, e, f]; e,f = origin in CSS px (top-left coord system).
            const fontHeightPx = Math.hypot(tx[2], tx[3]); // approx
            const widthPx = item.width * SCALE;
            // The textContent transform's tx[5] is the BASELINE y in CSS px.
            const baselineY = tx[5];
            const left = tx[4];

            const run = document.createElement('span');
            run.className = 'epj-run';
            run.textContent = item.str;
            // Position so visible text aligns with the canvas glyphs.
            run.style.left = `${left}px`;
            run.style.top = `${baselineY - fontHeightPx}px`;
            run.style.fontSize = `${fontHeightPx}px`;
            run.style.lineHeight = `${fontHeightPx}px`;

            const occ = counters.get(item.str) || 0;
            counters.set(item.str, occ + 1);

            run.dataset.page = String(pageNum - 1);
            run.dataset.original = item.str;
            run.dataset.occurrence = String(occ);

            run.addEventListener('click', (ev) => {
                ev.stopPropagation();
                openEditor(run);
            });
            textLayer.appendChild(run);
        }
        occurrenceCounter.set(pageNum - 1, counters);
    }
    setStatus(`Loaded ${pdf.numPages} page${pdf.numPages === 1 ? '' : 's'}. Click any text to edit.`);
}

function openEditor(runEl) {
    closeEditor();
    activeRun = runEl;
    runEl.classList.add('epj-editing');
    editOrig.textContent = `was: "${runEl.dataset.original}"`;
    editInput.value = runEl.dataset.original;
    editBar.classList.add('epj-visible');
    editInput.focus();
    editInput.select();
}

function closeEditor() {
    if (activeRun) activeRun.classList.remove('epj-editing');
    activeRun = null;
    editBar.classList.remove('epj-visible');
}

editCancel.addEventListener('click', closeEditor);
editInput.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') closeEditor();
    else if (ev.key === 'Enter') applyEdit();
});

async function applyEdit() {
    if (!activeRun) return;
    const newText = editInput.value;
    const original = activeRun.dataset.original;
    if (newText === original) { closeEditor(); return; }
    const edit = {
        page: parseInt(activeRun.dataset.page, 10),
        original_text: original,
        new_text: newText,
        occurrence: parseInt(activeRun.dataset.occurrence, 10),
    };
    setStatus('Applying edit…');
    editApply.disabled = true;
    try {
        const r = await fetch(REWRITE_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF,
                'Accept': 'application/pdf',
            },
            body: JSON.stringify({ edits: [edit] }),
        });
        if (!r.ok) {
            let body = '';
            try { body = await r.text(); } catch (_) {}
            throw new Error(`Server rewrite failed (${r.status}): ${body.slice(0, 400)}`);
        }
        const buf = await r.arrayBuffer();
        currentPdfBytes = buf;
        pendingEdits.push(edit);
        closeEditor();
        await renderAllPages(buf);
        downloadBtn.disabled = false;
    } catch (err) {
        console.error(err);
        showError(err.message);
        setStatus('Edit failed.', true);
    } finally {
        editApply.disabled = false;
    }
}
editApply.addEventListener('click', applyEdit);

downloadBtn.addEventListener('click', () => {
    if (!currentPdfBytes) return;
    const blob = new Blob([currentPdfBytes], { type: 'application/pdf' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `doc-${currentDocId}-edited.pdf`;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 1000);
});

(async () => {
    try {
        const buf = await fetchOriginalPdf();
        currentPdfBytes = buf;
        await renderAllPages(buf);
    } catch (err) {
        console.error(err);
        showError(err.message);
        setStatus('Failed to load PDF.', true);
    }
})();
