<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $form['title'] }} {{ $form['year'] }} - Fillable form - Netkit</title>
    <meta name="description" content="{{ $form['summary'] }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('forms._styles')
    <style>
        .form-layout { display: grid; grid-template-columns: minmax(0, 6fr) minmax(0, 6fr); gap: 36px; align-items: start; }
        .form-preview-card {
            padding: 28px;
            background:
                radial-gradient(circle at 1px 1px, color-mix(in srgb, var(--nk-ink) 7%, transparent) 1px, transparent 0) 0 0 / 16px 16px,
                var(--nk-surface-2);
        }
        .form-preview-card img { display: block; width: 100%; height: auto; border-radius: 3px; box-shadow: 0 24px 50px -22px rgba(24, 24, 27, 0.5); background: #fff; }
        .form-preview-thumbs { display: flex; gap: 10px; margin-top: 14px; }
        .form-preview-thumbs button { appearance: none; padding: 4px; border-radius: 6px; border: 1px solid var(--nk-border); background: var(--nk-surface); cursor: pointer; width: 64px; }
        .form-preview-thumbs button[aria-pressed="true"] { border-color: var(--nk-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--nk-accent) 16%, transparent); }
        .form-preview-thumbs img { display: block; width: 100%; height: auto; border-radius: 2px; box-shadow: none; }
        .form-details { display: grid; gap: 18px; }
        .form-heading { display: grid; gap: 6px; }
        .form-heading h1 { margin: 0; font-size: clamp(30px, 3.8vw, 40px); font-weight: 600; letter-spacing: -0.03em; line-height: 1.1; }
        .form-heading h1 span { color: var(--nk-muted-2); font-weight: 500; }
        .form-heading p { margin: 0; font-size: 15px; color: var(--nk-muted); }
        .form-badges { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
        .form-badge { display: grid; gap: 4px; padding: 12px 14px; border-radius: var(--nk-radius); border: 1px solid var(--nk-border); background: var(--nk-surface-2); }
        .form-badge strong { font-size: 15px; font-weight: 600; letter-spacing: -0.01em; font-variant-numeric: tabular-nums; }
        .form-badge span { font-size: 12px; color: var(--nk-muted); }
        .form-copy p { margin: 0 0 12px; font-size: 15px; line-height: 1.65; color: var(--nk-ink-2); }
        .form-copy p:last-child { margin-bottom: 0; }
        .form-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .form-actions form { flex: 1; min-width: 200px; margin: 0; }
        .form-actions .nk-button { width: 100%; padding: 12px 18px; font-size: 15px; }
        .form-copy-link { width: 44px; height: 44px; padding: 0; }
        .form-copy-link svg { width: 18px; height: 18px; }
        .form-copied { font-size: 12.5px; color: var(--nk-ok); min-height: 1.2em; }
        .form-source { font-size: 13px; color: var(--nk-muted); }
        .form-source a { color: var(--nk-accent-ink); text-decoration: none; }
        .form-source a:hover { text-decoration: underline; }
        @media (max-width: 860px) { .form-layout { grid-template-columns: minmax(0, 1fr); } .form-badges { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 520px) { .form-badges { grid-template-columns: minmax(0, 1fr); } .form-preview-card { padding: 16px; } }
    </style>
</head>
<body>
    <x-site-header />

    <main class="nk-page">
        <div class="nk-container">
            @if ($errors->any())
                <div class="nk-banner error" style="margin-bottom: 20px;">{{ $errors->first() }}</div>
            @endif

            <div class="form-layout">
                <div>
                    <div class="nk-card form-preview-card">
                        @if ($form['preview'])
                            <img id="form-preview-image" src="{{ asset($form['preview']) }}" alt="First page of {{ $form['title'] }} ({{ $form['year'] }})" width="1224" height="1584" fetchpriority="high">
                        @endif
                    </div>
                    @if (count($form['previews']) > 1)
                        <div class="form-preview-thumbs" role="group" aria-label="Pages">
                            @foreach ($form['previews'] as $index => $preview)
                                <button type="button" data-form-preview="{{ asset($preview) }}" aria-pressed="{{ $index === 0 ? 'true' : 'false' }}" aria-label="Show page {{ $index + 1 }}">
                                    <img src="{{ asset($preview) }}" alt="" loading="lazy" width="1224" height="1584">
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="form-details">
                    <nav class="nk-crumbs" aria-label="Breadcrumb">
                        <a href="{{ route('documents.index') }}">PDF editor</a>
                        <span aria-hidden="true">/</span>
                        <a href="{{ route('documents.index') }}#fillable-forms">Fillable forms</a>
                        <span aria-hidden="true">/</span>
                        <span>{{ $form['category'] }}</span>
                    </nav>

                    <div class="form-heading">
                        <h1>{{ $form['title'] }} <span>{{ $form['year'] }}</span></h1>
                        <p>{{ $form['subtitle'] }} &middot; {{ $form['issuer'] }}</p>
                    </div>

                    <div class="form-badges">
                        <div class="form-badge"><strong>{{ $form['fields'] }}</strong><span>fillable fields</span></div>
                        <div class="form-badge"><strong>{{ $form['pages'] }}</strong><span>{{ Str::plural('page', $form['pages']) }}</span></div>
                        <div class="form-badge"><strong>{{ $form['year'] }}</strong><span>official revision</span></div>
                    </div>

                    <div class="form-copy">
                        <p>{{ $form['summary'] }}</p>
                        <p>{{ $form['instructions'] }}</p>
                    </div>

                    <div class="form-actions">
                        <form action="{{ route('forms.fill', $form['slug']) }}" method="POST" id="form-fill">
                            @csrf
                            <button type="submit" class="nk-button" id="form-fill-button" @disabled(!$available)>
                                Fill out now
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 7l5 5-5 5M6 12h12" /></svg>
                            </button>
                        </form>
                        <button type="button" class="nk-button-secondary form-copy-link" id="form-copy-link" aria-label="Copy link to this form" title="Copy link">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" /><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" /></svg>
                        </button>
                    </div>
                    <div class="form-copied" id="form-copied" aria-live="polite"></div>
                    @unless ($available)
                        <div class="nk-banner error">This form is not available right now.</div>
                    @endunless

                    @if (!empty($form['source_url']))
                        <div class="form-source">Source: <a href="{{ $form['source_url'] }}" rel="noopener" target="_blank">{{ parse_url($form['source_url'], PHP_URL_HOST) }}</a></div>
                    @endif
                </div>
            </div>
        </div>
    </main>

    <script>
        (() => {
            const image = document.getElementById('form-preview-image');
            document.querySelectorAll('[data-form-preview]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (image) image.src = button.dataset.formPreview;
                    document.querySelectorAll('[data-form-preview]').forEach((other) => other.setAttribute('aria-pressed', other === button ? 'true' : 'false'));
                });
            });

            const copyButton = document.getElementById('form-copy-link');
            const copied = document.getElementById('form-copied');
            copyButton?.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(window.location.href);
                    if (copied) copied.textContent = 'Link copied.';
                } catch (_) {
                    if (copied) copied.textContent = window.location.href;
                }
            });

            const fillForm = document.getElementById('form-fill');
            const fillButton = document.getElementById('form-fill-button');
            fillForm?.addEventListener('submit', () => {
                if (!fillButton || fillButton.disabled) return;
                fillButton.disabled = true;
                fillButton.textContent = 'Preparing your form…';
            });
        })();
    </script>
</body>
</html>
