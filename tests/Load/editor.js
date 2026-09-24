// Editors: each virtual user signs in, opens its document, then autosaves every 2 s as the editor does.
//   k6 run -e BASE_URL=... -e LOAD_USERS=1000 -e EDITORS=1000 -e DURATION=5m tests/Load/editor.js
import { check, sleep } from 'k6';
import http from 'k6/http';
import exec from 'k6/execution';
import { BASE_URL, accountFor, annotation, commonOptions, jsonHeaders, openDocuments, signIn } from './lib/netkit.js';

const EDITORS = Number(__ENV.EDITORS || 20);
const DURATION = __ENV.DURATION || '2m';
const ANNOTATIONS = Number(__ENV.ANNOTATIONS || 40);   // on the page when the session starts
const DELTA_SAVES = __ENV.DELTA_SAVES === '1';         // what the editor sends once delta saves are deployed (PR #119)

export const options = {
    ...commonOptions(),
    scenarios: {
        editors: { executor: 'ramping-vus', startVUs: 0, stages: [{ target: EDITORS, duration: '1m' }, { target: EDITORS, duration: DURATION }, { target: 0, duration: '20s' }], gracefulRampDown: '10s' },
    },
    thresholds: {
        'http_req_duration{name:GET editor}': ['p(95)<2000'],        // SLO: editor open under 2 s of server time
        'http_req_duration{name:GET document info}': ['p(95)<1000'],
        'http_req_duration{name:POST autosave}': ['p(95)<200'],      // SLO: autosave under 200 ms
        http_req_failed: ['rate<0.001'],
        checks: ['rate>0.999'],
    },
};

export default function () {
    if (!signIn(accountFor(exec.vu.idInTest))) return;
    const { token, documentId } = openDocuments();
    if (!documentId) { check(null, { 'has a document (run php artisan load:seed)': () => false }); sleep(5); return; }

    const session = `load-${exec.vu.idInTest}-${Date.now()}`;
    check(http.get(`${BASE_URL}/documents/${documentId}/edit-pdfjs`, { tags: { name: 'GET editor' } }), { 'editor opens': (r) => r.status === 200 });
    const info = http.get(`${BASE_URL}/pdf-tests/document/${documentId}/info?session_id=${session}&skip_embedded_fonts=1`, { headers: { Accept: 'application/json' }, tags: { name: 'GET document info' } });
    let version = Number(info.json('state_version') || 0);

    const state = Array.from({ length: ANNOTATIONS }, (_, i) => annotation(documentId, i, `Item ${i}`));
    const save = (body) => {
        const response = http.post(`${BASE_URL}/documents/${documentId}/save-annotation-state`, JSON.stringify({ ...body, session_id: session, acro_form_entries: [], base_version: version }), { headers: jsonHeaders(token), tags: { name: 'POST autosave' } });
        if (check(response, { 'autosave accepted': (r) => r.status === 200 })) version = Number(response.json('state_version') || version + 1);

        return response;
    };

    save({ annotations: state });   // the first save of a session is always full
    for (let edit = 0; ; edit += 1) {
        sleep(2);
        const index = edit % ANNOTATIONS;
        state[index] = annotation(documentId, index, `Edit ${edit}`);
        save(DELTA_SAVES ? { delta: true, annotations: [state[index]], removed_ids: [], expected_count: state.length } : { annotations: state });
    }
}
