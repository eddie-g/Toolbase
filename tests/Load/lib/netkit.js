// Shared by the k6 scenarios in tests/Load: accounts, sign-in, CSRF, and the
// requests an open editor makes. k6 runs this (ES modules, no Node APIs).
import http from 'k6/http';
import { check, fail, sleep } from 'k6';

export const BASE_URL = (__ENV.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
export const USERS = Number(__ENV.LOAD_USERS || 50);
export const PASSWORD = __ENV.LOAD_PASSWORD || 'load-test-password-2026';

/**
 * Spread into a scenario's options. RESOLVE=localhost:8081=172.19.0.5:80 sends
 * requests for that host to another address (k6 inside a Docker network
 * talking to an app whose cookies are scoped to "localhost").
 */
export function commonOptions() {
    const [from, to] = String(__ENV.RESOLVE || '').split('=');

    return from && to ? { hosts: { [from]: to } } : {};
}

/** A failed sign-in must not turn into a sign-in storm: wait before the next iteration. */
export const BACK_OFF_SECONDS = 30;

/** The account of a virtual user: `php artisan load:seed` creates loadtest+<n>@netkit.test, each with one document. */
export function accountFor(vu) {
    const n = ((vu - 1) % USERS) + 1;

    return { email: `loadtest+${n}@netkit.test`, n };
}

export function csrfFrom(html) {
    const match = /name="csrf-token" content="([^"]+)"/.exec(html) || /name="_token" value="([^"]+)"/.exec(html);

    return match ? match[1] : '';
}

/** Sign in through the real form. Returns the CSRF token of the new session. */
export function signIn(account, tags = {}) {
    const form = http.get(`${BASE_URL}/login`, { tags: { name: 'GET /login', ...tags } });
    const token = csrfFrom(form.body);
    if (!token) fail(`no CSRF token on /login (HTTP ${form.status})`);

    const response = http.post(`${BASE_URL}/login`, { _token: token, email: account.email, password: PASSWORD }, {
        redirects: 0,
        tags: { name: 'POST /login', ...tags },
    });
    const ok = check(response, { 'signed in': (r) => r.status === 302 && !String(r.headers.Location || '').includes('/login') });
    if (!ok) sleep(BACK_OFF_SECONDS);

    return ok;
}

/** The documents page: also where a virtual user learns its document id and a fresh token. */
export function openDocuments() {
    const page = http.get(`${BASE_URL}/pdf-editor`, { tags: { name: 'GET /pdf-editor' } });
    const id = /\/documents\/(\d+)\/edit-pdfjs/.exec(page.body);

    return { token: csrfFrom(page.body), documentId: id ? Number(id[1]) : null, status: page.status };
}

export function jsonHeaders(token, extra = {}) {
    return { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', ...extra };
}

/** One text annotation, shaped like the editor's. */
export function annotation(documentId, index, text) {
    return {
        id: `pdfjs_${documentId}_0_new_load-${index}`,
        type: 'text', pageIndex: 0, text,
        pdfX: 40 + (index % 8) * 60, pdfY: 60 + Math.floor(index / 8) * 16, pdfWidth: 56, pdfHeight: 12,
        x: 40, y: 60, width: 56, height: 12,
        fontFamily: 'Helvetica', fontSize: 10, textColor: '#111111', userCreated: true,
    };
}
