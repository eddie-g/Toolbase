// Uploads: concurrent visitors with accounts uploading a PDF (which queues an extraction).
//   k6 run -e BASE_URL=... -e UPLOADERS=100 tests/Load/uploads.js
import { check, sleep } from 'k6';
import http from 'k6/http';
import exec from 'k6/execution';
import { BASE_URL, accountFor, commonOptions, openDocuments, signIn } from './lib/netkit.js';

const UPLOADERS = Number(__ENV.UPLOADERS || 5);
const pdf = open('../../resources/load-tests/invoice.pdf', 'b');

export const options = {
    ...commonOptions(),
    scenarios: { uploads: { executor: 'per-vu-iterations', vus: UPLOADERS, iterations: Number(__ENV.ITERATIONS || 2), maxDuration: '10m' } },
    thresholds: {
        'http_req_duration{name:POST upload}': ['p(95)<1500'],   // probe + store + queue; extraction itself runs on Horizon
        http_req_failed: ['rate<0.001'],
        checks: ['rate>0.99'],
    },
};

export default function () {
    if (!signIn(accountFor(exec.vu.idInTest))) return;
    const { token } = openDocuments();
    const response = http.post(`${BASE_URL}/documents`, { _token: token, document: http.file(pdf, `load-${exec.vu.idInTest}-${exec.vu.iterationInScenario}.pdf`, 'application/pdf') }, { redirects: 0, headers: { Accept: 'application/json' }, tags: { name: 'POST upload' } });
    check(response, { 'upload accepted': (r) => r.status === 302 || r.status === 200 || r.status === 201 });
    sleep(1);
}
