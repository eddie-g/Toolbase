// Sign-in storm: arrivals per second, each a fresh visitor signing in through the form.
//   k6 run -e BASE_URL=https://staging.example -e LOAD_USERS=1000 -e RATE=20 tests/Load/login.js
import { sleep } from 'k6';
import http from 'k6/http';
import exec from 'k6/execution';
import { BASE_URL, accountFor, commonOptions, signIn } from './lib/netkit.js';

const RATE = Number(__ENV.RATE || 5);           // sign-ins per second at the peak
const DURATION = __ENV.DURATION || '2m';

export const options = {
    ...commonOptions(),
    scenarios: {
        logins: {
            executor: 'ramping-arrival-rate', startRate: 1, timeUnit: '1s',
            preAllocatedVUs: RATE * 4, maxVUs: RATE * 20,
            stages: [{ target: RATE, duration: '30s' }, { target: RATE, duration: DURATION }, { target: 0, duration: '15s' }],
        },
    },
    thresholds: {
        'http_req_duration{name:POST /login}': ['p(95)<300'],   // SLO: sign-in p95 under 300 ms (bcrypt is most of it)
        'http_req_duration{name:GET /login}': ['p(95)<200'],
        http_req_failed: ['rate<0.001'],
        checks: ['rate>0.999'],
    },
};

export default function () {
    http.cookieJar().clear(BASE_URL);
    signIn(accountFor(exec.scenario.iterationInTest + 1));
    sleep(0.1);
}
