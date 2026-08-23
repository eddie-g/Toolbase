<x-filament-panels::page>
    <div
        x-data="automatedTests({
            base: @js(url('/automated-tests')),
            suites: @js($this->getSuites()),
        })"
        x-init="load()"
        class="at-root"
    >
        {{-- ── Suite switcher ─────────────────────────────────────────── --}}
        <nav class="at-tabs" aria-label="Test suites">
            <template x-for="entry in suites" :key="entry.key">
                <button type="button" class="at-tab"
                        :class="{ 'is-active': entry.key === activeSuite }"
                        x-on:click="selectSuite(entry.key)">
                    <span class="at-tab__dot" x-show="entry.key === activeSuite"></span>
                    <span x-text="entry.label"></span>
                </button>
            </template>
        </nav>

        {{-- ── Loading / error ────────────────────────────────────────── --}}
        <template x-if="loadError">
            <div class="at-alert at-alert--error" x-text="loadError"></div>
        </template>

        <template x-if="!suite && !loadError">
            <div class="at-alert">Loading suite…</div>
        </template>

        <template x-if="suite">
            <div class="at-stack">
                {{-- ── Source issue ───────────────────────────────────── --}}
                <section class="at-card at-source">
                    <div class="at-source__main">
                        <div class="at-eyebrow">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true">
                                <circle cx="12" cy="6" r="3.1"/><circle cx="5.6" cy="16.4" r="3.1"/><circle cx="18.4" cy="16.4" r="3.1"/>
                            </svg>
                            Asana ·
                            <span x-text="suite.asana.project"></span> /
                            <span x-text="suite.asana.section"></span>
                        </div>

                        <h2 class="at-source__title" x-text="suite.asana.task_name"></h2>

                        <ol class="at-source__steps">
                            <template x-for="(line, index) in suite.asana.instructions" :key="index">
                                <li x-text="line"></li>
                            </template>
                        </ol>

                        <div class="at-source__meta">
                            <code x-text="suite.target_url"></code>
                        </div>
                    </div>

                    <div class="at-source__side">
                        <a class="at-link-btn" :href="suite.asana.permalink" target="_blank" rel="noopener">
                            Open in Asana
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M7 17 17 7M9 7h8v8"/>
                            </svg>
                        </a>
                        <div class="at-source__counts">
                            <div><strong x-text="suite.tests.length"></strong> specified</div>
                            <div><strong x-text="automatedTests.length"></strong> automated</div>
                        </div>
                    </div>
                </section>

                {{-- ── Run bar ────────────────────────────────────────── --}}
                <section class="at-card at-runbar">
                    <div class="at-runbar__lead">
                        <h3>Automated run</h3>
                        <p>
                            Executes tests
                            <template x-for="(test, index) in automatedTests" :key="rowKey(test)">
                                <span><span x-text="test.number"></span><span x-show="index < automatedTests.length - 1">, </span></span>
                            </template>
                            in headless Chromium against a fresh blank PDF.
                        </p>
                    </div>

                    <div class="at-runbar__actions">
                        <template x-if="summary">
                            <div class="at-summary">
                                <div class="at-summary__pill" :class="summary.tests_failed || summary.tests_errored ? 'is-fail' : 'is-pass'">
                                    <span x-text="summary.tests_passed"></span>/<span x-text="summary.tests_total"></span> tests
                                </div>
                                <div class="at-summary__sub">
                                    <span x-text="summary.checks_passed"></span>/<span x-text="summary.checks_total"></span> checks ·
                                    <span x-text="(summary.duration_ms / 1000).toFixed(1)"></span>s
                                </div>
                            </div>
                        </template>

                        <button type="button" class="at-run-btn" x-on:click="run()" :disabled="running">
                            <template x-if="!running">
                                <span class="at-run-btn__inner">
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                                    Run tests
                                </span>
                            </template>
                            <template x-if="running">
                                <span class="at-run-btn__inner">
                                    <span class="at-spinner" aria-hidden="true"></span>
                                    Running…
                                </span>
                            </template>
                        </button>
                    </div>
                </section>

                <template x-if="runError">
                    <div class="at-alert at-alert--error" x-text="runError"></div>
                </template>

                {{-- ── Test list ──────────────────────────────────────── --}}
                <section class="at-card at-list">
                    <header class="at-list__head">
                        <h3>Test plan</h3>
                        <p>All <span x-text="suite.tests.length"></span> cases specified on the Asana task. Automated ones show live results.</p>
                    </header>

                    <template x-for="group in groupedTests" :key="group.area">
                        <div class="at-group">
                            <div class="at-group__label" x-text="group.area"></div>

                            <template x-for="test in group.tests" :key="rowKey(test)">
                                <article
                                    class="at-test"
                                    :class="{
                                        'is-automated': test.automated,
                                        'is-open': isExpanded(test),
                                        'is-pass': resultFor(test.id)?.status === 'passed',
                                        'is-fail': resultFor(test.id)?.status === 'failed',
                                        'is-error': resultFor(test.id)?.status === 'error',
                                        'is-running': running && test.automated,
                                    }"
                                >
                                    <button
                                        type="button"
                                        class="at-test__head"
                                        x-on:click="toggle(test)"
                                        :aria-expanded="isExpanded(test) ? 'true' : 'false'"
                                    >
                                        <span class="at-test__num" x-text="test.number"></span>

                                        <span class="at-test__body">
                                            <span class="at-test__title" x-text="test.title"></span>
                                            <span class="at-test__summary" x-text="test.summary"></span>
                                        </span>

                                        <span class="at-test__right">
                                            <template x-if="running && test.automated && !resultFor(test.id)">
                                                <span class="at-status at-status--running"><span class="at-spinner at-spinner--sm"></span> running</span>
                                            </template>

                                            <template x-if="resultFor(test.id)">
                                                <span
                                                    class="at-status"
                                                    :class="{
                                                        'at-status--pass': resultFor(test.id).status === 'passed',
                                                        'at-status--fail': resultFor(test.id).status === 'failed',
                                                        'at-status--error': resultFor(test.id).status === 'error',
                                                    }"
                                                >
                                                    <span x-text="resultFor(test.id).status === 'passed' ? 'PASS' : (resultFor(test.id).status === 'failed' ? 'FAIL' : 'ERROR')"></span>
                                                    <span class="at-status__count"
                                                          x-text="resultFor(test.id).checks_passed + '/' + resultFor(test.id).checks_total"></span>
                                                </span>
                                            </template>

                                            <template x-if="!test.automated">
                                                <span class="at-status at-status--manual">manual</span>
                                            </template>

                                            <svg class="at-chevron" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                                <path d="m6 9 6 6 6-6"/>
                                            </svg>
                                        </span>
                                    </button>

                                    <div class="at-test__detail" x-show="isExpanded(test)" x-cloak>
                                        <div class="at-test__links">
                                            <a :href="asanaSubtaskUrl(test)" target="_blank" rel="noopener">Subtask <span x-text="test.number"></span> in Asana ↗</a>
                                            <template x-if="resultFor(test.id)?.document_id">
                                                <span>· test document #<span x-text="resultFor(test.id).document_id"></span></span>
                                            </template>
                                            <template x-if="resultFor(test.id)?.duration_ms">
                                                <span>· <span x-text="(resultFor(test.id).duration_ms / 1000).toFixed(1)"></span>s</span>
                                            </template>
                                        </div>

                                        <template x-if="resultFor(test.id)?.error">
                                            <div class="at-alert at-alert--error" x-text="resultFor(test.id).error"></div>
                                        </template>

                                        <template x-if="resultFor(test.id)?.checks?.length">
                                            <ul class="at-checks">
                                                <template x-for="check in resultFor(test.id).checks" :key="check.item">
                                                    <li class="at-check" :class="check.result === 'PASS' ? 'is-pass' : 'is-fail'">
                                                        <span class="at-check__badge" x-text="check.result"></span>
                                                        <span class="at-check__text">
                                                            <span class="at-check__desc" x-text="check.description"></span>
                                                            <code class="at-check__item" x-text="check.item"></code>
                                                            <span class="at-check__detail" x-show="check.detail" x-text="check.detail"></span>
                                                        </span>
                                                    </li>
                                                </template>
                                            </ul>
                                        </template>

                                        <template x-if="resultFor(test.id)?.artifacts?.length">
                                            <div class="at-shots">
                                                <template x-for="shot in resultFor(test.id).artifacts" :key="shot.filename">
                                                    <figure class="at-shot">
                                                        <a :href="artifactBase + '/' + shot.filename" target="_blank" rel="noopener">
                                                            <img :src="artifactBase + '/' + shot.filename" :alt="shot.label" loading="lazy">
                                                        </a>
                                                        <figcaption x-text="shot.label"></figcaption>
                                                    </figure>
                                                </template>
                                            </div>
                                        </template>

                                        <template x-if="test.automation_note">
                                            <p class="at-scope-note">
                                                <strong>Automation scope:</strong>
                                                <span x-text="test.automation_note"></span>
                                            </p>
                                        </template>

                                        <template x-if="!test.automated">
                                            <p class="at-manual-note">
                                                Not automated yet — run this case by hand from the Asana subtask.
                                            </p>
                                        </template>
                                    </div>
                                </article>
                            </template>
                        </div>
                    </template>
                </section>
            </div>
        </template>
    </div>

    <script>
            function automatedTests(config) {
                return {
                    base: config.base,
                    suites: config.suites || [],
                    activeSuite: (config.suites && config.suites[0]) ? config.suites[0].key : null,

                    suite: null,
                    loadError: null,
                    runError: null,
                    running: false,
                    results: {},
                    summary: null,
                    expanded: {},

                    get suiteUrl() { return `${this.base}/${this.activeSuite}/suite`; },
                    get runUrl() { return `${this.base}/${this.activeSuite}/run`; },
                    get artifactBase() { return `${this.base}/${this.activeSuite}/artifacts`; },

                    /** Switch suites, discarding the previous run's results. */
                    async selectSuite(key) {
                        if (key === this.activeSuite) return;
                        this.activeSuite = key;
                        this.suite = null;
                        this.results = {};
                        this.summary = null;
                        this.expanded = {};
                        this.loadError = null;
                        this.runError = null;
                        await this.load();
                    },

                    /** Runner ids repeat when several subtasks share one test. */
                    get automatedTestIds() {
                        return [...new Set(this.automatedTests.map((test) => test.id))];
                    },

                    get automatedTests() {
                        return (this.suite?.tests || []).filter((test) => test.automated);
                    },

                    /** Preserve catalogue order while grouping by area. */
                    get groupedTests() {
                        const groups = [];
                        const index = new Map();
                        for (const test of this.suite?.tests || []) {
                            if (!index.has(test.area)) {
                                index.set(test.area, { area: test.area, tests: [] });
                                groups.push(index.get(test.area));
                            }
                            index.get(test.area).tests.push(test);
                        }
                        return groups;
                    },

                    resultFor(id) {
                        return this.results[id] || null;
                    },

                    asanaSubtaskUrl(test) {
                        const base = (this.suite?.asana?.permalink || '').split('/task/')[0];
                        return `${base}/task/${test.gid}`;
                    },

                    rowKey(test) {
                        return `${test.number}:${test.id}`;
                    },

                    toggle(test) {
                        const key = this.rowKey(test);
                        this.expanded[key] = !this.expanded[key];
                    },

                    isExpanded(test) {
                        return !!this.expanded[this.rowKey(test)] || !!this.expanded[test.id];
                    },

                    async load() {
                        try {
                            const response = await fetch(this.suiteUrl, {
                                headers: { Accept: 'application/json' },
                                credentials: 'same-origin',
                            });
                            const data = await response.json();
                            if (!response.ok || !data.success) {
                                throw new Error(data.message || `Failed to load suite (HTTP ${response.status})`);
                            }
                            this.suite = data.suite;
                        } catch (error) {
                            this.loadError = error.message || String(error);
                        }
                    },

                    async run() {
                        if (this.running) return;
                        this.running = true;
                        this.runError = null;
                        this.results = {};
                        this.summary = null;

                        try {
                            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                            const response = await fetch(this.runUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/json',
                                    Accept: 'application/json',
                                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                                },
                                body: JSON.stringify({ tests: this.automatedTestIds }),
                            });

                            const data = await response.json();
                            if (!response.ok || data.success === false) {
                                throw new Error(data.message || `Run failed (HTTP ${response.status})`);
                            }

                            for (const result of data.results || []) {
                                this.results[result.id] = result;
                                // Surface anything that needs attention straight away.
                                if (result.status !== 'passed') this.expanded[result.id] = true;
                            }
                            this.summary = data.summary || null;
                        } catch (error) {
                            this.runError = error.message || String(error);
                        } finally {
                            this.running = false;
                        }
                    },
                };
            }
    </script>

    <style>
            .at-root { --at-line: rgb(228 228 231); --at-muted: rgb(113 113 122); --at-bg: rgb(255 255 255); --at-soft: rgb(250 250 250); }
            .dark .at-root { --at-line: rgb(63 63 70); --at-muted: rgb(161 161 170); --at-bg: rgb(24 24 27); --at-soft: rgb(39 39 42); }

            .at-stack { display: flex; flex-direction: column; gap: 1rem; }
            [x-cloak] { display: none !important; }

            .at-card {
                background: var(--at-bg);
                border: 1px solid var(--at-line);
                border-radius: 0.85rem;
                box-shadow: 0 1px 2px rgb(0 0 0 / 0.04);
            }

            /* Tabs */
            .at-tabs { display: flex; gap: 0.35rem; margin-bottom: 1rem; }
            .at-tab {
                display: inline-flex; align-items: center; gap: 0.45rem;
                padding: 0.45rem 0.9rem; border-radius: 999px;
                font-size: 0.82rem; font-weight: 600;
                border: 1px solid var(--at-line); background: var(--at-bg); color: inherit;
            }
            .at-tab.is-active { border-color: rgb(59 130 246 / 0.5); background: rgb(59 130 246 / 0.08); color: rgb(37 99 235); }
            .dark .at-tab.is-active { color: rgb(147 197 253); }
            .at-tab.is-disabled { opacity: 0.45; }
            .at-tab__dot { width: 6px; height: 6px; border-radius: 999px; background: currentColor; }

            /* Source card */
            .at-source { display: flex; gap: 1.5rem; padding: 1.15rem 1.25rem; align-items: flex-start; flex-wrap: wrap; }
            .at-source__main { flex: 1 1 22rem; min-width: 0; }
            .at-eyebrow {
                display: inline-flex; align-items: center; gap: 0.35rem;
                font-size: 0.7rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase;
                color: rgb(217 70 118);
            }
            .at-source__title { font-size: 1.15rem; font-weight: 700; margin: 0.35rem 0 0.55rem; }
            .at-source__steps { margin: 0; padding-left: 1.1rem; font-size: 0.85rem; color: var(--at-muted); }
            .at-source__steps li { margin-bottom: 0.15rem; }
            .at-source__meta { margin-top: 0.7rem; }
            .at-source__meta code {
                font-size: 0.75rem; padding: 0.2rem 0.45rem; border-radius: 0.35rem;
                background: var(--at-soft); border: 1px solid var(--at-line); color: var(--at-muted);
            }
            .at-source__side { display: flex; flex-direction: column; gap: 0.7rem; align-items: flex-end; }
            .at-link-btn {
                display: inline-flex; align-items: center; gap: 0.35rem;
                padding: 0.4rem 0.75rem; border-radius: 0.5rem;
                font-size: 0.8rem; font-weight: 600; text-decoration: none;
                border: 1px solid var(--at-line); color: inherit; background: var(--at-bg);
            }
            .at-link-btn:hover { background: var(--at-soft); }
            .at-source__counts { text-align: right; font-size: 0.75rem; color: var(--at-muted); line-height: 1.5; }
            .at-source__counts strong { color: inherit; font-size: 0.9rem; }

            /* Run bar */
            .at-runbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; flex-wrap: wrap; }
            .at-runbar__lead h3 { font-size: 0.95rem; font-weight: 700; margin: 0; }
            .at-runbar__lead p { font-size: 0.8rem; color: var(--at-muted); margin: 0.2rem 0 0; }
            .at-runbar__actions { display: flex; align-items: center; gap: 1rem; }

            .at-summary { text-align: right; }
            .at-summary__pill {
                display: inline-block; padding: 0.2rem 0.6rem; border-radius: 999px;
                font-size: 0.78rem; font-weight: 700;
            }
            .at-summary__pill.is-pass { background: rgb(34 197 94 / 0.14); color: rgb(21 128 61); }
            .at-summary__pill.is-fail { background: rgb(239 68 68 / 0.14); color: rgb(185 28 28); }
            .dark .at-summary__pill.is-pass { color: rgb(134 239 172); }
            .dark .at-summary__pill.is-fail { color: rgb(252 165 165); }
            .at-summary__sub { font-size: 0.72rem; color: var(--at-muted); margin-top: 0.15rem; }

            .at-run-btn {
                border: 0; border-radius: 0.6rem; padding: 0.6rem 1.15rem;
                font-size: 0.85rem; font-weight: 650; color: #fff; cursor: pointer;
                background: linear-gradient(180deg, rgb(59 130 246), rgb(37 99 235));
                box-shadow: 0 6px 16px rgb(37 99 235 / 0.28);
            }
            .at-run-btn:hover:not(:disabled) { filter: brightness(1.07); }
            .at-run-btn:disabled { opacity: 0.65; cursor: progress; }
            .at-run-btn__inner { display: inline-flex; align-items: center; gap: 0.45rem; }

            .at-spinner {
                width: 13px; height: 13px; border-radius: 999px;
                border: 2px solid rgb(255 255 255 / 0.35); border-top-color: #fff;
                animation: at-spin 0.65s linear infinite; display: inline-block;
            }
            .at-spinner--sm { width: 10px; height: 10px; border-width: 1.6px; border-color: rgb(120 120 130 / 0.3); border-top-color: rgb(120 120 130); }
            @keyframes at-spin { to { transform: rotate(360deg); } }
            @media (prefers-reduced-motion: reduce) { .at-spinner { animation-duration: 2s; } }

            /* Alerts */
            .at-alert {
                padding: 0.7rem 0.9rem; border-radius: 0.6rem; font-size: 0.82rem;
                border: 1px solid var(--at-line); background: var(--at-soft); color: var(--at-muted);
            }
            .at-alert--error {
                border-color: rgb(239 68 68 / 0.35); background: rgb(239 68 68 / 0.08); color: rgb(185 28 28);
            }
            .dark .at-alert--error { color: rgb(252 165 165); }

            /* Test list */
            .at-list__head { padding: 1rem 1.25rem 0.35rem; }
            .at-list__head h3 { font-size: 0.95rem; font-weight: 700; margin: 0; }
            .at-list__head p { font-size: 0.8rem; color: var(--at-muted); margin: 0.2rem 0 0; }

            .at-group { padding: 0 0.6rem; }
            .at-group__label {
                font-size: 0.68rem; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase;
                color: var(--at-muted); padding: 0.9rem 0.65rem 0.35rem;
            }

            .at-test { border-radius: 0.6rem; border: 1px solid transparent; margin-bottom: 0.2rem; }
            .at-test.is-open { border-color: var(--at-line); background: var(--at-soft); }
            .at-test.is-pass { border-left: 3px solid rgb(34 197 94); }
            .at-test.is-fail, .at-test.is-error { border-left: 3px solid rgb(239 68 68); }

            .at-test__head {
                width: 100%; display: flex; align-items: flex-start; gap: 0.75rem;
                padding: 0.6rem 0.7rem; background: none; border: 0; cursor: pointer; text-align: left; color: inherit;
            }
            .at-test__head:hover { background: var(--at-soft); border-radius: 0.6rem; }

            .at-test__num {
                flex: none; width: 1.55rem; height: 1.55rem; border-radius: 0.4rem;
                display: grid; place-items: center;
                font-size: 0.7rem; font-weight: 700; font-variant-numeric: tabular-nums;
                background: var(--at-soft); border: 1px solid var(--at-line); color: var(--at-muted);
            }
            .at-test.is-automated .at-test__num { background: rgb(59 130 246 / 0.12); border-color: rgb(59 130 246 / 0.3); color: rgb(37 99 235); }
            .dark .at-test.is-automated .at-test__num { color: rgb(147 197 253); }

            .at-test__body { flex: 1; min-width: 0; }
            .at-test__title { display: block; font-size: 0.85rem; font-weight: 600; line-height: 1.35; }
            .at-test__summary {
                display: block; font-size: 0.76rem; color: var(--at-muted); line-height: 1.45; margin-top: 0.15rem;
                overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
            }
            .at-test.is-open .at-test__summary { -webkit-line-clamp: unset; }

            .at-test__right { flex: none; display: flex; align-items: center; gap: 0.5rem; padding-top: 0.1rem; }
            .at-chevron { color: var(--at-muted); transition: transform 0.15s ease; }
            .at-test.is-open .at-chevron { transform: rotate(180deg); }

            .at-status {
                display: inline-flex; align-items: center; gap: 0.3rem;
                padding: 0.18rem 0.5rem; border-radius: 999px;
                font-size: 0.68rem; font-weight: 700; letter-spacing: 0.03em;
                background: var(--at-soft); border: 1px solid var(--at-line); color: var(--at-muted);
            }
            .at-status--pass { background: rgb(34 197 94 / 0.14); border-color: rgb(34 197 94 / 0.3); color: rgb(21 128 61); }
            .at-status--fail, .at-status--error { background: rgb(239 68 68 / 0.14); border-color: rgb(239 68 68 / 0.3); color: rgb(185 28 28); }
            .dark .at-status--pass { color: rgb(134 239 172); }
            .dark .at-status--fail, .dark .at-status--error { color: rgb(252 165 165); }
            .at-status__count { font-weight: 600; opacity: 0.8; font-variant-numeric: tabular-nums; }
            .at-status--manual { text-transform: lowercase; font-weight: 600; opacity: 0.75; }

            /* Detail */
            .at-test__detail { padding: 0.15rem 0.7rem 0.85rem 3rem; display: flex; flex-direction: column; gap: 0.7rem; }
            .at-test__links { font-size: 0.75rem; color: var(--at-muted); display: flex; gap: 0.4rem; flex-wrap: wrap; }
            .at-test__links a { color: rgb(37 99 235); text-decoration: none; font-weight: 600; }
            .dark .at-test__links a { color: rgb(147 197 253); }
            .at-test__links a:hover { text-decoration: underline; }

            .at-checks { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.25rem; }
            .at-check {
                display: flex; gap: 0.55rem; align-items: flex-start;
                padding: 0.4rem 0.55rem; border-radius: 0.45rem;
                background: var(--at-bg); border: 1px solid var(--at-line);
            }
            .at-check.is-fail { border-color: rgb(239 68 68 / 0.35); background: rgb(239 68 68 / 0.05); }
            .at-check__badge {
                flex: none; font-size: 0.6rem; font-weight: 800; letter-spacing: 0.04em;
                padding: 0.15rem 0.35rem; border-radius: 0.3rem; margin-top: 0.05rem;
            }
            .at-check.is-pass .at-check__badge { background: rgb(34 197 94 / 0.16); color: rgb(21 128 61); }
            .at-check.is-fail .at-check__badge { background: rgb(239 68 68 / 0.16); color: rgb(185 28 28); }
            .dark .at-check.is-pass .at-check__badge { color: rgb(134 239 172); }
            .dark .at-check.is-fail .at-check__badge { color: rgb(252 165 165); }
            .at-check__text { min-width: 0; }
            .at-check__desc { display: block; font-size: 0.78rem; line-height: 1.4; }
            .at-check__item {
                font-size: 0.68rem; color: var(--at-muted); background: var(--at-soft);
                padding: 0.05rem 0.3rem; border-radius: 0.25rem; margin-right: 0.35rem;
            }
            .at-check__detail { font-size: 0.72rem; color: var(--at-muted); word-break: break-word; }

            /* Screenshots */
            .at-shots { display: flex; gap: 0.7rem; flex-wrap: wrap; }
            .at-shot { margin: 0; width: 15rem; max-width: 100%; }
            .at-shot img {
                width: 100%; border-radius: 0.5rem; border: 1px solid var(--at-line); display: block;
            }
            .at-shot figcaption { font-size: 0.7rem; color: var(--at-muted); margin-top: 0.25rem; }

            .at-manual-note { font-size: 0.78rem; color: var(--at-muted); margin: 0; font-style: italic; }
            .at-scope-note {
                font-size: 0.76rem; margin: 0; padding: 0.5rem 0.65rem; border-radius: 0.45rem;
                background: rgb(245 158 11 / 0.09); border: 1px solid rgb(245 158 11 / 0.3);
                color: rgb(146 64 14); line-height: 1.45;
            }
            .dark .at-scope-note { color: rgb(253 230 138); }
    </style>
</x-filament-panels::page>
