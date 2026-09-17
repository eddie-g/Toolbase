const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');

const repositoryRoot = path.resolve(__dirname, '../../..');
process.env.PLAYWRIGHT_BROWSERS_PATH ||= path.join(repositoryRoot, 'node_modules/playwright-core/.local-browsers');
const { chromium } = require('playwright');
const { PNG } = require('pngjs');
const baseUrl = process.env.BASE_URL || 'http://localhost:8081';
const uploadConfig = process.env.PDF_UPLOAD_TEST_CONFIG
    ? JSON.parse(readFileSync(process.env.PDF_UPLOAD_TEST_CONFIG, 'utf8')) : null;
let documentId = Number(uploadConfig?.source_document_id || process.env.SOURCE_DOCUMENT_ID || process.env.DOCUMENT_ID || 7341);
const scenarioDocumentId = Number(uploadConfig?.inline_regression?.document_id || process.env.DOCUMENT_ID || documentId);
const selectedAnnotation = uploadConfig?.saved_runtime_annotation_id || process.env.INLINE_ANNOTATION_ID;
let allLabelIds = (scenarioDocumentId === 7339 ? ['0_0:129', '0_0:24']
    : scenarioDocumentId === 5294 ? ['4_4:128'] : ['0_0:24', '0_0:29'])
    .map((suffix) => `pdfjs_${documentId}_${suffix}`);
let labelIds = allLabelIds.filter((id) => !selectedAnnotation || selectedAnnotation === id);
const paragraphId = 'promoted_2_6';

function loadFixture() {
    const command = uploadConfig ? 'php' : 'docker';
    const prefix = uploadConfig ? [] : ['compose', 'exec', '-T', 'laravel.test', 'php'];
    const fixture = JSON.parse(execFileSync(command, [...prefix, '-r',
        `require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        Illuminate\\Support\\Facades\\URL::forceRootUrl($argv[1]);
        $document = App\\Models\\Document::findOrFail((int) $argv[2]);
        if ($argv[3] === 'retained') {
            $fixture = App\\Models\\PdfUploadTest::where('admin_id', $document->admin_id)
                ->where('original_name', 'Inline regressions - '.$document->original_name)->firstOrFail();
            $document = $fixture->document;
        }
        $request = Illuminate\\Http\\Request::create('/pdf-tests/document/'.$document->id.'/info', 'GET', ['session_id' => 'inline-stability-fixture', 'skip_embedded_fonts' => '1']);
        $info = app(App\\Http\\Controllers\\PdfTestController::class)->documentInfo($request, $document)->getData(true);
        $fonts = app(App\\Http\\Controllers\\DocumentController::class)->getFonts($document)->getData(true);
        echo json_encode([
            'document_id' => $document->id,
            'html' => view('documents.edit-new-pdfjs', ['document' => $document])->render(),
            'info' => $info,
            'fonts' => $fonts,
            'pdf' => base64_encode(Illuminate\\Support\\Facades\\Storage::disk('local')->get($document->original_backup_path ?: $document->path)),
        ]);`, baseUrl, String(documentId), process.env.USE_RETAINED_FIXTURE === '1' ? 'retained' : '',
    ], { cwd: repositoryRoot, maxBuffer: 30 * 1024 * 1024, encoding: 'utf8' }));
    if (documentId !== fixture.document_id) {
        allLabelIds = allLabelIds.map((id) => id.replace(`pdfjs_${documentId}_`, `pdfjs_${fixture.document_id}_`));
        documentId = fixture.document_id;
        labelIds = allLabelIds.filter((id) => !selectedAnnotation || selectedAnnotation === id);
    }
    fixture.info.annotations = fixture.info.annotations.filter((annotation) => !allLabelIds.includes(annotation.id));
    if (uploadConfig) fixture.pdf = readFileSync(uploadConfig.source_pdf_path).toString('base64');
    return fixture;
}

async function state(box) {
    return box.evaluate((element) => {
        const content = element.querySelector('.enpv-text-content');
        const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
        const runs = [];
        while (walker.nextNode()) {
            const node = walker.currentNode;
            if (!node.textContent.trim()) continue;
            const range = document.createRange();
            range.selectNodeContents(node);
            runs.push({
                text: node.textContent,
                family: getComputedStyle(node.parentElement).fontFamily,
                left: range.getBoundingClientRect().left,
                right: range.getBoundingClientRect().right,
                tops: Array.from(range.getClientRects(), (rect) => Math.round(rect.top * 100) / 100),
            });
        }
        const bounds = element.getBoundingClientRect();
        return { text: content.textContent, dx: Number(element.dataset.dxPts), origin: { left: bounds.left, top: bounds.top }, runs,
            html: content.innerHTML, data: { ...element.dataset }, style: content.style.cssText };
    });
}

async function selectText(box, search, collapse = false) {
    await box.locator('.enpv-text-content').evaluate((content, options) => {
        content.focus();
        const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const node = walker.currentNode;
            const offset = node.textContent.indexOf(options.search);
            if (offset < 0) continue;
            const range = document.createRange();
            range.setStart(node, offset);
            range.setEnd(node, offset + options.search.length);
            if (options.collapse) range.collapse(false);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            return;
        }
        throw new Error(`Text not found: ${options.search}`);
    }, { search, collapse });
}

async function main() {
    const fixture = loadFixture();
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: [7339, 7151, 5294].includes(scenarioDocumentId) ? 2100 : 1600, height: 1100 } });
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    const savedAnnotations = [];
    const boxFor = (id) => page.locator(`.enpv-annotation-box[data-annotation-id="${id}"]:visible`).last();
    const outsideClick = () => page.locator('#viewerContainer').click({ position: { x: 5, y: 150 } });
    const save = async () => {
        const response = page.waitForResponse((result) => result.url().endsWith('/save-annotation-state'));
        await page.locator('#save-btn').dispatchEvent('click');
        await response;
    };
    try {
        await page.route('**/*', async (route) => {
            const request = route.request();
            const pathname = new URL(request.url()).pathname;
            if (!['GET', 'HEAD'].includes(request.method())) {
                if (pathname.endsWith('/save-annotation-state')) {
                    savedAnnotations.push(...request.postDataJSON().annotations);
                }
                await route.fulfill({ json: { success: true } });
            } else if (pathname === `/documents/${documentId}/edit-new`) {
                await route.fulfill({ contentType: 'text/html', body: fixture.html });
            } else if (pathname === `/pdf-tests/document/${documentId}/info`) {
                await route.fulfill({ json: fixture.info });
            } else if (new RegExp(`^/documents/${documentId}/(file|clean-pdf|baked-pdf|original-file)$`).test(pathname)) {
                await route.fulfill({ contentType: 'application/pdf', body: Buffer.from(fixture.pdf, 'base64') });
            } else if (pathname === `/documents/${documentId}/fonts`) {
                await route.fulfill({ json: fixture.fonts });
            } else await route.continue();
        });
        await page.goto(`${baseUrl}/documents/${documentId}/edit-new?pdfjs=1`, { waitUntil: 'networkidle' });
        await page.locator('body.enpv-viewer-ready').waitFor();
        await page.locator('#ftb-edit-mode').click();
        const runParagraphActions = async () => {
            const failures = [];
            const enterTarget = selectedAnnotation === 'promoted_3_8' ? 'your own behalf,' : 'agency';
            const fontTarget = selectedAnnotation === 'promoted_3_8' ? 'physically' : 'agency';
            const glyphs = async (box) => box.evaluate((element) => {
                const bounds = element.getBoundingClientRect();
                const content = element.querySelector('.enpv-text-content');
                const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
                const result = [];
                while (walker.nextNode()) {
                    const node = walker.currentNode;
                    for (let offset = 0; offset < node.length; offset += 1) {
                        const text = node.textContent[offset];
                        if (/[\s\u200b]/.test(text)) continue;
                        const range = document.createRange();
                        range.setStart(node, offset);
                        range.setEnd(node, offset + 1);
                        const rect = range.getBoundingClientRect();
                        result.push({ text, left: rect.left - bounds.left, top: rect.top - bounds.top, family: getComputedStyle(node.parentElement).fontFamily });
                    }
                }
                return result;
            });
            const assertGlyphLayout = (actual, expected, ratio, label) => {
                assert.equal(actual.map((glyph) => glyph.text).join(''), expected.map((glyph) => glyph.text).join(''), `${label}: text changed`);
                actual.forEach((glyph, index) => {
                    assert.equal(glyph.family.split(',')[0].trim(), expected[index].family.split(',')[0].trim(), `${label}: glyph ${index} font changed`);
                    assert.ok(Math.abs(glyph.left - expected[index].left * ratio) < 1.5, `${label}: glyph ${index} horizontal spacing changed (${glyph.left} vs ${expected[index].left * ratio})`);
                    assert.ok(Math.abs(glyph.top - expected[index].top * ratio) < 1.5, `${label}: glyph ${index} row position changed (${glyph.top} vs ${expected[index].top * ratio})`);
                });
            };
            for (const action of ['resize', 'enter', 'font']) {
                await page.reload({ waitUntil: 'networkidle' });
                await page.locator('body.enpv-viewer-ready').waitFor();
                await page.locator('#ftb-edit-mode').click();
                await page.evaluate(() => {
                    window.__enpv.pdfViewer.currentScaleValue = '1.5';
                    window.__enpv.pdfViewer.currentPageNumber = 3;
                });
                await page.waitForTimeout(2000);
                const box = boxFor(selectedAnnotation);
                await box.waitFor({ state: 'visible' });
                await box.evaluate((element) => element.scrollIntoView({ block: 'center' }));
                await box.dblclick();
                await box.locator('[contenteditable="true"]').waitFor();
                const before = await state(box);
                const beforeGlyphs = await glyphs(box);
                if (action === 'resize') {
                    const handle = await box.locator('.enpv-resize-handle[data-edge="r"]').boundingBox();
                    assert.ok(handle, 'Right resize handle must be visible');
                    await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2);
                    await page.mouse.down();
                    await page.mouse.move(handle.x + handle.width / 2 - 90, handle.y + handle.height / 2, { steps: 12 });
                    await page.mouse.up();
                } else if (action === 'enter') {
                    await selectText(box, enterTarget, true);
                    await page.keyboard.press('Enter');
                } else {
                    await selectText(box, fontTarget);
                    await box.locator('.enpv-text-content').dispatchEvent('mouseup');
                    await page.locator('#afb-font').selectOption('Georgia');
                    await page.evaluate(() => document.fonts.ready);
                }
                const edited = await state(box);
                const editedGlyphs = await glyphs(box);
                await box.screenshot({ path: `/tmp/${selectedAnnotation}-${action}-edited.png` });
                await outsideClick();
                const committed = await state(box);
                const committedGlyphs = await glyphs(box);
                await box.screenshot({ path: `/tmp/${selectedAnnotation}-${action}-deselected.png` });
                if (process.env.INLINE_DEBUG) console.log(JSON.stringify({ action, before, edited, committed }));
                try {
                    assert.equal(committed.text.replace(/\u200b/g, '').replace(/\s+/g, ' ').trim(), before.text.replace(/\s+/g, ' ').trim(), 'Paragraph text changed');
                    assert.deepEqual(committed.runs.map((run) => [run.text, run.family]), edited.runs.map((run) => [run.text, run.family]), `${action}: deselection changed text or fonts`);
                    committed.runs.forEach((run, index) => {
                        assert.ok(Math.abs((run.left - committed.origin.left) - (edited.runs[index].left - edited.origin.left)) < 1, `${action}: deselection changed run ${index} spacing`);
                        assert.equal(run.tops.length, edited.runs[index].tops.length, `${action}: deselection changed wrapping`);
                        run.tops.forEach((top, row) => assert.ok(Math.abs((top - committed.origin.top) - (edited.runs[index].tops[row] - edited.origin.top)) < 1, `${action}: deselection changed row pitch`));
                    });
                    assertGlyphLayout(committedGlyphs, editedGlyphs, 1, `${action}: deselection`);
                    const markerLength = before.text.trim().match(/^\d+[.)]/)?.[0].length || 0;
                    assert.ok(Math.abs(editedGlyphs[0].left - beforeGlyphs[0].left) < 1, `${action}: paragraph left inset changed`);
                    if (markerLength) assert.ok(Math.abs(editedGlyphs[markerLength].left - beforeGlyphs[markerLength].left) < 1, `${action}: marker-to-body gap changed`);
                    if (action === 'enter') {
                        const text = editedGlyphs.map((glyph) => glyph.text).join('');
                        const compactTarget = enterTarget.replace(/\s/g, '');
                        const continuation = text.indexOf(compactTarget) + compactTarget.length;
                        assert.ok(continuation >= compactTarget.length, 'Enter target must be present');
                        const bodyGlyph = beforeGlyphs.find((glyph) => glyph.top > beforeGlyphs[0].top + 10);
                        assert.ok(Math.abs(editedGlyphs[continuation].left - bodyGlyph.left) < 8, 'Enter continuation lost the body indent');
                    }
                    if (action === 'font') {
                        const wordStart = beforeGlyphs.map((glyph) => glyph.text).join('').indexOf(fontTarget);
                        committedGlyphs.forEach((glyph, index) => {
                            if (index >= wordStart && index < wordStart + fontTarget.length) assert.match(glyph.family, /Georgia/i);
                            else assert.equal(glyph.family, beforeGlyphs[index].family, 'Font change leaked outside the selected word');
                        });
                    }
                    await save();
                    await page.evaluate(() => { window.__enpv.pdfViewer.currentScaleValue = '1.25'; });
                    await page.waitForTimeout(2000);
                    await box.evaluate((element) => element.scrollIntoView({ block: 'center' }));
                    await box.dblclick();
                    await box.locator('[contenteditable="true"]').waitFor();
                    if (process.env.INLINE_DEBUG) console.log(JSON.stringify({ action: `${action}-reopened`, state: await state(box), saved: savedAnnotations.filter((annotation) => annotation.id === selectedAnnotation).at(-1) }));
                    assertGlyphLayout(await glyphs(box), committedGlyphs, 1.25 / 1.5, `${action}: reopen`);
                    assert.deepEqual(pageErrors, [], 'Browser runtime errors');
                    console.log(`PASS ${selectedAnnotation}: ${action}, deselect, save payload and zoom/reopen retain layout`);
                } catch (error) {
                    failures.push(error.message);
                }
            }
            assert.deepEqual(failures, []);
        };
        if (scenarioDocumentId === 5294 && (selectedAnnotation === 'promoted_3_8'
            || (selectedAnnotation === 'promoted_3_4' && process.env.INLINE_PARAGRAPH_ACTIONS === '1'))) {
            await runParagraphActions();
            return;
        }
        if (scenarioDocumentId === 5294 && selectedAnnotation === 'promoted_3_4') {
            for (const [search, replacement] of [['agency', 'abcd'], ['agency', 'office'], ['reason', 'explanation']]) {
            if (replacement !== 'abcd') {
                await page.reload({ waitUntil: 'networkidle' });
                await page.locator('body.enpv-viewer-ready').waitFor();
                await page.locator('#ftb-edit-mode').click();
            }
            await page.evaluate(() => {
                window.__enpv.pdfViewer.currentScaleValue = '1.9';
                window.__enpv.pdfViewer.currentPageNumber = 3;
            });
            const box = boxFor('promoted_3_4');
            await box.waitFor({ state: 'visible' });
            await box.evaluate((element) => element.scrollIntoView({ block: 'center' }));
            await box.dblclick();
            await box.locator('[contenteditable="true"]').waitFor();
            const before = await state(box);
            await selectText(box, search);
            await page.keyboard.type(replacement);
            const edited = await state(box);
            await page.keyboard.press('Escape');
            const committed = await state(box);
            await box.screenshot({ path: '/tmp/doc5294-paragraph-spacing.png' });
            assert.deepEqual(pageErrors, [], 'Browser runtime errors');
            const expectedText = before.text.replace(search, replacement).replace(/\s+/g, ' ').trim();
            const assertPositions = (snapshot, ratio = 1, absolute = true) => {
                assert.equal(snapshot.text.replace(/\s+/g, ' ').trim(), expectedText);
                assert.equal(snapshot.runs.length, before.runs.length, 'Captured paragraph runs were discarded');
                before.runs.forEach((run, index) => {
                    const expectedLeft = (run.left - before.runs[0].left) * ratio;
                    const actualLeft = snapshot.runs[index].left - snapshot.runs[0].left;
                    assert.ok(Math.abs(actualLeft - expectedLeft) < 1, `Run ${index} indent changed`);
                    const expectedTop = (run.tops[0] - before.runs[0].tops[0]) * ratio;
                    const actualTop = snapshot.runs[index].tops[0] - snapshot.runs[0].tops[0];
                    assert.ok(Math.abs(actualTop - expectedTop) < 1, `Run ${index} row pitch changed`);
                    assert.equal(snapshot.runs[index].family, run.family);
                    if (absolute) assert.ok(Math.abs(snapshot.runs[index].left - run.left) < 1, `Run ${index} shifted`);
                });
            };
            assertPositions(edited);
            assertPositions(committed);
            await save();
            const saved = savedAnnotations.filter((annotation) => annotation.id === 'promoted_3_4').at(-1);
            assert.equal(saved?.text?.replace(/\s+/g, ' ').trim(), expectedText);
            await page.evaluate(() => { window.__enpv.pdfViewer.currentScaleValue = '1.5'; });
            await page.waitForTimeout(2500);
            await box.evaluate((element) => element.scrollIntoView({ block: 'center' }));
            await box.dblclick();
            await box.locator('[contenteditable="true"]').waitFor();
            assertPositions(await state(box), 1.5 / 1.9, false);
            assert.deepEqual(pageErrors, [], 'Browser runtime errors after rebuild');
            console.log(`PASS promoted_3_4 ${search} -> ${replacement}: marker gap, indents, rows, fonts, commit, save payload and zoom/reopen`);
            }
            await runParagraphActions();
            return;
        }
        if (scenarioDocumentId === 5294) {
            await page.evaluate(() => {
                window.__enpv.pdfViewer.currentScaleValue = '1.9';
                window.__enpv.pdfViewer.currentPageNumber = 5;
            });
            const box = boxFor(labelIds[0]);
            await box.waitFor({ state: 'visible' });
            await box.evaluate((element) => element.scrollIntoView({ block: 'center' }));
            await page.waitForTimeout(2500);
            const before = await state(box);
            const bounds = await box.boundingBox();
            const original = PNG.sync.read(await page.screenshot({ path: '/tmp/doc5294-source.png', clip: bounds }));
            const pointer = { x: bounds.x + bounds.width / 2, y: bounds.y + bounds.height / 2 };
            await page.mouse.move(pointer.x, pointer.y);
            await page.mouse.down();
            await page.mouse.move(pointer.x + bounds.width + 90, pointer.y, { steps: 20 });
            await page.mouse.up();
            await page.waitForTimeout(1200);
            const moved = await state(box);
            assert.ok(Math.abs(moved.dx) > 1, 'Label did not move');
            assert.equal(moved.text, before.text);
            const image = PNG.sync.read(await page.screenshot({
                path: '/tmp/doc5294-vacated.png', clip: bounds,
                style: '.enpv-annotation-box { visibility: hidden !important; } * { box-shadow: none !important; }',
            }));
            const graySamples = new Map();
            for (let offset = 0; offset < original.data.length; offset += 4) {
                const value = original.data[offset];
                if (value >= 180 && value <= 240 && value === original.data[offset + 1] && value === original.data[offset + 2]) {
                    graySamples.set(value, (graySamples.get(value) || 0) + 1);
                }
            }
            const background = [...graySamples].sort((left, right) => right[1] - left[1])[0]?.[0];
            let backgroundPixels = 0;
            let changedBackgroundPixels = 0;
            let remainingGlyphPixels = 0;
            for (let row = 3; row < image.height - 3; row += 1) {
                for (let column = 3; column < image.width - 3; column += 1) {
                    const offset = (row * image.width + column) * 4;
                    const value = original.data[offset];
                    if (value < 100 && image.data[offset] < 170) remainingGlyphPixels += 1;
                    if (value !== background || value !== original.data[offset + 1] || value !== original.data[offset + 2]) continue;
                    backgroundPixels += 1;
                    if (Math.abs(image.data[offset] - value) > 10) changedBackgroundPixels += 1;
                }
            }
            assert.ok(backgroundPixels > 50, 'Fixture must contain a gray cell background');
            assert.equal(changedBackgroundPixels, 0, 'Moving the label erased the gray cell background');
            assert.equal(remainingGlyphPixels, 0, 'Old digits remain in the gray cell');
            console.log(`PASS ${labelIds[0]}: ${backgroundPixels} gray cell pixels preserved and old digits erased`);
            return;
        }
        if (scenarioDocumentId === 7151) {
            for (const zoom of ['1.2', '1.9']) {
                if (zoom !== '1.2') {
                    await page.reload({ waitUntil: 'networkidle' });
                    await page.locator('body.enpv-viewer-ready').waitFor();
                    await page.locator('#ftb-edit-mode').click();
                }
                await page.evaluate((value) => { window.__enpv.pdfViewer.currentScaleValue = value; }, zoom);
                await page.waitForTimeout(2500);
                const box = boxFor('promoted_1_1');
                await box.waitFor({ state: 'visible' });
                await box.evaluate((element) => {
                    const container = document.getElementById('viewerContainer');
                    container.scrollTop += element.getBoundingClientRect().top - 130;
                });
                const before = await state(box);
                const bounds = await box.boundingBox();
                await page.screenshot({ path: `/tmp/doc7151-source-${zoom}.png`, clip: bounds });
                const pointer = { x: bounds.x + bounds.width / 2, y: bounds.y + 20 };
                const deltaX = bounds.width + 70;
                await page.mouse.move(pointer.x, pointer.y);
                await page.mouse.down();
                await page.mouse.move(pointer.x + deltaX, pointer.y, { steps: 20 });
                await page.mouse.up();
                await page.waitForTimeout(1200);
                const moved = await state(box);
                assert.ok(Math.abs(moved.dx) > 1, 'Block did not move');
                assert.equal(moved.text, before.text, 'Moving the block changed its text');
                const image = PNG.sync.read(await page.screenshot({ path: `/tmp/doc7151-vacated-${zoom}.png`, clip: bounds }));
                await box.screenshot({
                    path: `/tmp/doc7151-moved-${zoom}.png`,
                    style: '.canvasWrapper, .textLayer, .enpv-annotation-box:not([data-annotation-id="promoted_1_1"]) { visibility: hidden !important; }',
                });
                let darkPixels = 0;
                for (let row = 2; row < image.height - 2; row += 1) {
                    for (let column = 2; column < image.width - 2; column += 1) {
                        const offset = (row * image.width + column) * 4;
                        if (image.data[offset] < 170 && image.data[offset + 1] < 170 && image.data[offset + 2] < 170) darkPixels += 1;
                    }
                }
                assert.equal(darkPixels, 0, `Source glyph strokes remain after moving promoted_1_1 at ${zoom}`);
                console.log(`PASS promoted_1_1 at ${Number(zoom) * 100}%: moved text intact, zero residual source pixels`);
            }
            return;
        }
        if (scenarioDocumentId === 7339) {
            const openBox = async (box) => {
                await box.evaluate((element) => element.scrollIntoView({ block: 'center', inline: 'center' }));
                await box.evaluate((element) => {
                    const container = document.getElementById('viewerContainer');
                    container.scrollTop += element.getBoundingClientRect().top - 450;
                });
                await box.dblclick();
                await box.locator('.enpv-text-content[contenteditable="true"]').waitFor();
            };
            const assertSourceGaps = (before, after, ratio = 1) => {
                assert.equal(after.runs.length, before.runs.length, 'Source styled runs were lost');
                before.runs.forEach((run, index) => {
                    assert.equal(after.runs[index].family, run.family, `Run ${index} font changed`);
                    if (!index) return;
                    const expected = (run.left - before.runs[index - 1].right) * ratio;
                    const actual = after.runs[index].left - after.runs[index - 1].right;
                    assert.ok(Math.abs(expected - actual) < 1, `Run ${index} gap changed from ${expected} to ${actual}`);
                });
            };
            for (const id of labelIds) {
                const box = boxFor(id);
                await openBox(box);
                const before = await state(box);
                await selectText(box, id.endsWith(':129') ? 'income' : '2a');
                await page.keyboard.type(id.endsWith(':129') ? 'taxes' : '44');
                const edited = await state(box);
                await page.keyboard.press('Escape');
                const committed = await state(box);
                await box.screenshot({ path: `/tmp/${id.replace(/:/g, '-')}-committed.png` });
                if (id.endsWith(':24')) {
                    assert.equal(edited.text, '44');
                    assert.equal(committed.text, '44');
                    assert.equal(committed.dx, 0, 'Short-label test must not move the annotation');
                    await page.waitForTimeout(1100);
                    const image = PNG.sync.read(await box.screenshot({
                        path: '/tmp/doc7339-label-mask.png',
                        style: '.enpv-text-content, .enpv-resize-handle { visibility: hidden !important; } .enpv-annotation-box { border-color: transparent !important; outline: none !important; }',
                    }));
                    let darkPixels = 0;
                    for (let row = 3; row < image.height - 2; row += 1) {
                        for (let column = 2; column < image.width - 2; column += 1) {
                            const offset = (row * image.width + column) * 4;
                            if (image.data[offset] < 170 && image.data[offset + 1] < 170 && image.data[offset + 2] < 170) darkPixels += 1;
                        }
                    }
                    assert.equal(darkPixels, 0, 'Old 2a glyph pixels remain underneath 44');
                    console.log(`PASS ${id}: stationary 2a -> 44 leaves no old-glyph stripe`);
                } else {
                    assert.equal(edited.text, before.text.replace('income', 'taxes'));
                    assert.equal(committed.text, edited.text);
                    assertSourceGaps(before, edited);
                    assertSourceGaps(before, committed);
                    const initialScale = await page.evaluate(() => window.__enpv.pdfViewer.currentScale);
                    await save();
                    await page.evaluate(() => { window.__enpv.pdfViewer.currentScaleValue = '1.5'; });
                    await page.waitForTimeout(2500);
                    await openBox(box);
                    const reopened = await state(box);
                    const nextScale = await page.evaluate(() => window.__enpv.pdfViewer.currentScale);
                    assert.equal(reopened.text, edited.text);
                    assertSourceGaps(before, reopened, nextScale / initialScale);
                    await page.keyboard.press('Escape');
                    await page.evaluate(() => { window.__enpv.pdfViewer.currentScaleValue = '1.9'; });
                    await page.waitForTimeout(2500);
                    console.log(`PASS ${id}: unequal-length typing preserves gaps, fonts, commit, and zoom/reopen`);
                }
            }
            return;
        }
        for (const id of labelIds) {
            const box = boxFor(id);
            await box.scrollIntoViewIfNeeded();
            await box.dblclick({ force: true });
            const before = await state(box);
            await selectText(box, id.endsWith(':24') ? '3' : '6');
            await page.keyboard.insertText('5');
            const edited = await state(box);
            await page.keyboard.press('Escape');
            const committed = await state(box);
            for (const snapshot of [edited, committed]) {
                assert.equal(snapshot.runs.length, before.runs.length, `${id}: source runs changed`);
                before.runs.forEach((run, index) => {
                    assert.ok(Math.abs(snapshot.runs[index].left - run.left) < 1, `${id}: run ${index} gap collapsed`);
                    assert.deepEqual(snapshot.runs[index].tops, run.tops, `${id}: line spacing changed`);
                });
            }
            console.log(`PASS ${id}: digit substitution preserves gaps and baselines`);
        }

        if (!selectedAnnotation || selectedAnnotation === paragraphId) {
        await page.evaluate(() => window.__enpv.pdfViewer.scrollPageIntoView({ pageNumber: 2 }));
        const paragraph = boxFor(paragraphId);
        await paragraph.scrollIntoViewIfNeeded();
        await paragraph.dblclick({ force: true });
        const before = await state(paragraph);
        await selectText(paragraph, 'have been completed.', true);
        await page.keyboard.press('Enter');
        await outsideClick();
        const bounds = await paragraph.boundingBox();
        await page.mouse.move(bounds.x + bounds.width / 2, bounds.y + 4);
        await page.mouse.down();
        await page.mouse.move(bounds.x + bounds.width / 2 + 35, bounds.y + 44, { steps: 12 });
        await page.mouse.up();
        const moved = await state(paragraph);
        assert.ok(Math.abs(moved.dx) > 1, 'Paragraph was not moved');
        assert.match(moved.text, /completed\.\n\u200b\nPurpose/, 'Paragraph break was lost');
        await paragraph.dblclick({ force: true });
        await outsideClick();
        await save();
        await page.evaluate(() => { window.__enpv.pdfViewer.currentScaleValue = '1.5'; });
        await page.waitForTimeout(2500);
        await page.evaluate(() => window.__enpv.pdfViewer.scrollPageIntoView({ pageNumber: 2 }));
        await paragraph.waitFor({ state: 'visible' });
        await paragraph.scrollIntoViewIfNeeded();
        await paragraph.dblclick({ force: true });
        await page.keyboard.press('Escape');
        const rebuilt = await state(paragraph);
        await save();
        const saved = savedAnnotations.filter((annotation) => annotation.id === paragraphId).at(-1);
        for (const label of ['Caution:', 'Purpose of form.']) {
            const initial = before.runs.find((run) => run.text.includes(label));
            for (const snapshot of [moved, rebuilt]) {
                assert.equal(snapshot.runs.find((run) => run.text.includes(label))?.family, initial.family, `${label}: bold face lost`);
            }
            assert.equal(saved?.richTextRuns?.find((run) => run.text?.includes(label))?.fontWeight, '700', `${label}: saved bold lost`);
        }
        await paragraph.screenshot({
            path: '/tmp/doc7341-inline-paragraph.png',
            style: '.canvasWrapper, .textLayer { visibility: hidden !important; }',
        });
        console.log('PASS promoted_2_6: Enter, move, reopen, rebuild, and save preserve bold');
        }

        await page.evaluate(() => { window.__enpv.pdfViewer.currentScaleValue = '1.9'; });
        await page.waitForTimeout(2500);
        await page.evaluate(() => window.__enpv.pdfViewer.scrollPageIntoView({ pageNumber: 1 }));
        for (const id of labelIds) {
            const box = boxFor(id);
            await box.waitFor({ state: 'visible' });
            await box.scrollIntoViewIfNeeded();
            await box.dblclick({ force: true });
            const rebuiltLabel = await state(box);
            assert.ok(rebuiltLabel.runs[1].left - rebuiltLabel.runs[0].left > (id.endsWith(':24') ? 30 : 44), `${id}: gap lost after rebuilding`);
            await page.keyboard.press('Escape');
        }
        if (labelIds.length) console.log('PASS source label gaps survive layer reconstruction');
    } finally {
        await browser.close();
    }
}

if (uploadConfig) {
    const checks = [];
    console.log = (message) => checks.push({ item: `inline_check_${checks.length + 1}`, result: 'PASS', description: String(message) });
    const report = (error = null) => {
        if (error) checks.push({ item: 'inline_regression', result: 'FAIL', description: error.message });
        process.stdout.write(JSON.stringify({
            test_key: uploadConfig.result_test_key,
            filename: uploadConfig.original_name,
            description: uploadConfig.test_comment,
            test_category: 'PDF Upload Tests',
            section_name: uploadConfig.inline_regression.title,
            status: error ? 'fail' : 'pass',
            checks, checks_passed: checks.filter((check) => check.result === 'PASS').length,
            checks_total: checks.length, error: error?.stack || null,
            warnings: ['Isolated browser fixture: all mutation requests intercepted; server save/export not exercised.'],
        }));
    };
    main().then(() => report(), (error) => { report(error); process.exitCode = 1; });
} else {
    main().catch((error) => { console.error(error); process.exitCode = 1; });
}