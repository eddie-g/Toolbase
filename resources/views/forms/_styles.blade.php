<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
<style>
    :root {
        --nk-bg: #ffffff; --nk-surface: #ffffff; --nk-surface-2: #fafafa; --nk-surface-3: #f4f4f5;
        --nk-border: #e4e4e7; --nk-border-2: #d4d4d8;
        --nk-ink: #18181b; --nk-ink-2: #3f3f46; --nk-muted: #71717a; --nk-muted-2: #a1a1aa;
        --nk-accent: #2563eb; --nk-accent-ink: #1d4ed8; --nk-accent-soft: #eff6ff; --nk-accent-line: #bfdbfe;
        --nk-danger: #dc2626; --nk-danger-soft: #fef2f2; --nk-danger-line: #fecaca;
        --nk-ok: #15803d; --nk-ok-soft: #f0fdf4; --nk-ok-line: #bbf7d0;
        --nk-primary-bg: #18181b; --nk-primary-ink: #ffffff; --nk-primary-hover: #27272a;
        --nk-shadow: 0 1px 2px rgba(24, 24, 27, 0.05); --nk-shadow-lg: 0 24px 60px -30px rgba(24, 24, 27, 0.35);
        --nk-radius: 12px; --nk-radius-sm: 8px;
    }
    .dark {
        --nk-bg: #09090b; --nk-surface: #09090b; --nk-surface-2: #18181b; --nk-surface-3: #27272a;
        --nk-border: #27272a; --nk-border-2: #3f3f46;
        --nk-ink: #fafafa; --nk-ink-2: #e4e4e7; --nk-muted: #a1a1aa; --nk-muted-2: #71717a;
        --nk-accent: #60a5fa; --nk-accent-ink: #93c5fd; --nk-accent-soft: #172554; --nk-accent-line: #1e40af;
        --nk-danger: #f87171; --nk-danger-soft: #2a1215; --nk-danger-line: #7f1d1d;
        --nk-ok: #4ade80; --nk-ok-soft: #052e16; --nk-ok-line: #166534;
        --nk-primary-bg: #ffffff; --nk-primary-ink: #18181b; --nk-primary-hover: #e4e4e7;
        --nk-shadow: 0 1px 2px rgba(0, 0, 0, 0.5); --nk-shadow-lg: 0 24px 70px -24px rgba(0, 0, 0, 0.8);
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh; background: var(--nk-bg); color: var(--nk-ink);
        font-family: 'Inter', 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
        font-feature-settings: 'cv11', 'ss01'; -webkit-font-smoothing: antialiased;
    }
    .nk-page { padding: 120px 16px 72px; }
    .nk-container { max-width: 1120px; margin: 0 auto; }
    .nk-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; color: var(--nk-accent); }
    .nk-title { margin: 0; font-size: clamp(28px, 3.6vw, 36px); font-weight: 600; letter-spacing: -0.025em; line-height: 1.15; text-wrap: balance; }
    .nk-lead { margin: 0; color: var(--nk-muted); font-size: 15px; line-height: 1.6; max-width: 62ch; }
    .nk-card { background: var(--nk-surface); border: 1px solid var(--nk-border); border-radius: var(--nk-radius); box-shadow: var(--nk-shadow); }
    .nk-button, .nk-button-secondary {
        appearance: none; border: 1px solid transparent; cursor: pointer; font: inherit; text-decoration: none;
        display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 16px;
        border-radius: var(--nk-radius-sm); font-size: 14px; font-weight: 500; line-height: 1.2; white-space: nowrap;
        transition: background-color 0.16s ease, border-color 0.16s ease, color 0.16s ease;
    }
    .nk-button { background: var(--nk-primary-bg); color: var(--nk-primary-ink); box-shadow: var(--nk-shadow); }
    .nk-button:hover { background: var(--nk-primary-hover); }
    .nk-button:disabled { opacity: 0.6; cursor: not-allowed; }
    .nk-button-secondary { background: var(--nk-surface); color: var(--nk-ink-2); border-color: var(--nk-border); }
    .nk-button-secondary:hover { border-color: var(--nk-border-2); background: var(--nk-surface-2); color: var(--nk-ink); }
    .nk-button:focus-visible, .nk-button-secondary:focus-visible { outline: 2px solid var(--nk-accent); outline-offset: 2px; }
    .nk-banner { padding: 12px 14px; border-radius: var(--nk-radius-sm); border: 1px solid var(--nk-border); background: var(--nk-surface-2); font-size: 14px; line-height: 1.5; }
    .nk-banner.error { color: var(--nk-danger); border-color: var(--nk-danger-line); background: var(--nk-danger-soft); }
    .nk-banner.success { color: var(--nk-ok); border-color: var(--nk-ok-line); background: var(--nk-ok-soft); }
    .nk-crumbs { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 13px; color: var(--nk-muted); }
    .nk-crumbs a { color: var(--nk-muted); text-decoration: none; }
    .nk-crumbs a:hover { color: var(--nk-ink); }
    .nk-crumbs span[aria-hidden] { color: var(--nk-muted-2); }
    @media (max-width: 720px) { .nk-page { padding-top: 100px; } }
</style>
