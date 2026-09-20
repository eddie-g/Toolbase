import test from 'node:test';
import assert from 'node:assert/strict';
import { createEditorFetch } from './editor-fetch.js';
import { textOfNode } from './inert-html.js';

const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

test('a 419 is replayed once with the refreshed token, and only for requests that sent one', async () => {
    let token = 'stale';
    const seen = [];
    const editorFetch = createEditorFetch({
        fetchImpl: async (input, init) => {
            const sent = new Headers(init?.headers).get('X-CSRF-TOKEN');
            seen.push([input, sent]);

            return sent === 'fresh' ? json(200, { ok: true }) : json(419, {});
        },
        getCsrf: () => token,
        refreshCsrf: async () => { token = 'fresh'; },
    });

    const saved = await editorFetch('/save', { method: 'POST', headers: { 'X-CSRF-TOKEN': 'stale' }, body: '{}' });
    assert.equal(saved.status, 200);
    assert.deepEqual(seen, [['/save', 'stale'], ['/save', 'fresh']]);

    seen.length = 0;
    assert.equal((await editorFetch('/info', { headers: { Accept: 'application/json' } })).status, 419, 'no token was sent: nothing to replay');
    assert.equal((await editorFetch('/bare')).status, 419);
    assert.equal(seen.length, 2);
});

test('a refresh that fails hands back the original 419', async () => {
    let calls = 0;
    const editorFetch = createEditorFetch({
        fetchImpl: async () => { calls += 1; return json(419, {}); },
        getCsrf: () => 'x',
        refreshCsrf: async () => { throw new Error('offline'); },
    });
    assert.equal((await editorFetch('/save', { method: 'POST', headers: { 'X-CSRF-TOKEN': 'x' } })).status, 419);
    assert.equal(calls, 1);
});

test('a kill switch is reported to the page, an ordinary 503 is not, and the body stays readable', async () => {
    const reported = [];
    const responses = [
        json(503, { success: false, code: 'export_disabled', message: 'Downloads are temporarily unavailable.' }),
        new Response('<html>Bad gateway</html>', { status: 503 }),
        json(503, { message: 'Python is busy' }),
    ];
    const editorFetch = createEditorFetch({ fetchImpl: async () => responses.shift(), onUnavailable: (code, message) => reported.push([code, message]) });

    const off = await editorFetch('/download', { method: 'POST' });
    assert.deepEqual(await off.json(), { success: false, code: 'export_disabled', message: 'Downloads are temporarily unavailable.' });
    await editorFetch('/download', { method: 'POST' });
    await editorFetch('/download', { method: 'POST' });

    assert.deepEqual(reported, [['export_disabled', 'Downloads are temporarily unavailable.']]);
});

test('pasted markup reads as text with line breaks, and scripts and styles are not text', () => {
    const el = (tagName, ...childNodes) => ({ nodeType: 1, tagName, childNodes });
    const text = (nodeValue) => ({ nodeType: 3, nodeValue });
    const body = el('BODY',
        el('STYLE', text('p { color: red }')),
        el('P', text('First'), el('B', text(' line'))),
        el('DIV', text('Second'), el('BR'), text('third word')),
        el('SCRIPT', text('alert(1)')),
        el('UL', el('LI', text('one')), el('LI', text('two'))),
    );

    assert.equal(textOfNode(body), 'First line\nSecond\nthird word\none\ntwo');
});
