<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fillable forms - Netkit</title>
    <meta name="description" content="Official forms with fillable fields. Pick one, fill it in the Netkit editor and download the completed PDF.">
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('forms._styles')
    <style>
        .forms-intro { display: grid; gap: 8px; max-width: 640px; margin-bottom: 32px; }
        .forms-group { display: grid; gap: 14px; margin-bottom: 36px; }
        .forms-group h2 { margin: 0; font-size: 17px; font-weight: 600; letter-spacing: -0.015em; }
        .forms-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
        .form-card { display: grid; grid-template-rows: auto 1fr; overflow: hidden; color: inherit; text-decoration: none; transition: border-color 0.16s ease, box-shadow 0.16s ease; }
        .form-card:hover { border-color: var(--nk-border-2); box-shadow: var(--nk-shadow-lg); }
        .form-card:focus-visible { outline: 2px solid var(--nk-accent); outline-offset: 2px; }
        .form-card-preview {
            padding: 22px 26px 0;
            border-bottom: 1px solid var(--nk-border);
            background:
                radial-gradient(circle at 1px 1px, color-mix(in srgb, var(--nk-ink) 7%, transparent) 1px, transparent 0) 0 0 / 14px 14px,
                var(--nk-surface-2);
            overflow: hidden;
            height: 190px;
        }
        .form-card-preview img { display: block; width: 100%; height: auto; border-radius: 3px 3px 0 0; box-shadow: 0 14px 28px -10px rgba(24, 24, 27, 0.35); background: #fff; }
        .form-card-body { display: grid; gap: 4px; padding: 14px; align-content: start; }
        .form-card-title { display: flex; align-items: baseline; gap: 8px; font-size: 15px; font-weight: 600; letter-spacing: -0.01em; }
        .form-card-title small { font-size: 13px; font-weight: 500; color: var(--nk-muted-2); }
        .form-card-subtitle { font-size: 13px; color: var(--nk-muted); line-height: 1.45; }
        .form-card-meta { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
        .form-chip { display: inline-flex; align-items: center; padding: 3px 8px; border-radius: 999px; border: 1px solid var(--nk-border); background: var(--nk-surface-2); font-size: 11.5px; font-weight: 500; color: var(--nk-ink-2); }
        .forms-empty { padding: 36px 20px; border-radius: var(--nk-radius); border: 1.5px dashed var(--nk-border-2); background: var(--nk-surface-2); text-align: center; color: var(--nk-muted); font-size: 14px; }
        @media (max-width: 960px) { .forms-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 560px) { .forms-grid { grid-template-columns: minmax(0, 1fr); } }
    </style>
</head>
<body>
    <x-site-header />

    <main class="nk-page">
        <div class="nk-container">
            <div class="forms-intro">
                <div class="nk-eyebrow">Forms</div>
                <h1 class="nk-title">Official forms, ready to fill in.</h1>
                <p class="nk-lead">Each form keeps its own fillable fields. Open one in the editor, type into the boxes, tick the check boxes, and download the completed PDF.</p>
            </div>

            @forelse ($formsByCategory as $category => $group)
                <section class="forms-group" aria-labelledby="forms-group-{{ Str::slug($category) }}">
                    <h2 id="forms-group-{{ Str::slug($category) }}">{{ $category }}</h2>
                    <div class="forms-grid">
                        @foreach ($group as $form)
                            <a href="{{ route('forms.show', $form['slug']) }}" class="nk-card form-card">
                                <div class="form-card-preview">
                                    @if ($form['preview'])
                                        <img src="{{ asset($form['preview']) }}" alt="First page of {{ $form['title'] }}" loading="lazy" width="1224" height="1584">
                                    @endif
                                </div>
                                <div class="form-card-body">
                                    <div class="form-card-title">{{ $form['title'] }} <small>{{ $form['year'] }}</small></div>
                                    <div class="form-card-subtitle">{{ $form['subtitle'] }}</div>
                                    <div class="form-card-meta">
                                        <span class="form-chip">{{ $form['fields'] }} fillable fields</span>
                                        <span class="form-chip">{{ $form['pages'] }} {{ Str::plural('page', $form['pages']) }}</span>
                                        <span class="form-chip">{{ $form['issuer'] }}</span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="forms-empty">No forms are listed yet.</div>
            @endforelse
        </div>
    </main>
</body>
</html>
