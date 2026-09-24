// Exports: queue a download of the edited PDF, poll until it is done, fetch it.
//   k6 run -e BASE_URL=... -e EXPORTERS=50 tests/Load/exports.js
import { check, sleep } from 'k6';
import http from 'k6/http';
import exec from 'k6/execution';
import { Trend } from 'k6/metrics';
import { BASE_URL, accountFor, annotation, commonOptions, jsonHeaders, openDocuments, signIn } from './lib/netkit.js';

const EXPORTERS = Number(__ENV.EXPORTERS || 5);
const completion = new Trend('export_completion_ms', true);

export const options = {
    ...commonOptions(),
    scenarios: { exports: { executor: 'per-vu-iterations', vus: EXPORTERS, iterations: Number(__ENV.ITERATIONS || 3), maxDuration: '15m' } },
    thresholds: {
        'http_req_duration{name:POST export}': ['p(95)<200'],   // SLO: enqueue under 200 ms
        export_completion_ms: ['p(95)<30000'],                  // SLO: finished within 30 s
        http_req_failed: ['rate<0.001'],
        checks: ['rate>0.99'],
    },
};

export default function () {
    if (!signIn(accountFor(exec.vu.idInTest))) return;
    const { token, documentId } = openDocuments();
    if (!documentId) { check(null, { 'has a document (run php artisan load:seed)': () => false }); return; }

    const annotations = Array.from({ length: 10 }, (_, i) => annotation(documentId, i, `Export ${i}`));
    const started = Date.now();
    const queued = http.post(`${BASE_URL}/documents/${documentId}/download-annotated-pdf`, JSON.stringify({ annotations, session_id: `load-export-${exec.vu.idInTest}` }), { headers: jsonHeaders(token, { 'X-Export-Mode': 'queued' }), tags: { name: 'POST export' } });
    if (!check(queued, { 'export queued': (r) => r.status === 202 && !!r.json('status_url') })) return;

    let status = queued.json();
    for (let waited = 0; waited < 120 && !['done', 'failed'].includes(status.status); waited += 1) {
        sleep(0.5);
        status = http.get(status.status_url || queued.json('status_url'), { headers: { Accept: 'application/json' }, tags: { name: 'GET export status' } }).json();
    }
    if (check(status, { 'export finished': (s) => s.status === 'done' && !!s.download_url })) {
        completion.add(Date.now() - started);
        check(http.get(status.download_url, { tags: { name: 'GET export file' }, responseType: 'none' }), { 'file served': (r) => r.status === 200 });
    }
    sleep(1);
}
