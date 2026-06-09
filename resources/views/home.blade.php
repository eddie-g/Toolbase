<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Netkit - Open-Source PDF Editor & Admin Dashboard</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            .nkheader{
                font-size:30px;
            }
            @keyframes netkit-planet-float {
                0%, 100% {
                    transform: translateY(0);
                }
                50% {
                    transform: translateY(-16px);
                }
            }

            @keyframes netkit-ring-spin {
                from {
                    transform: rotate(0deg);
                }
                to {
                    transform: rotate(360deg);
                }
            }

            @keyframes netkit-planet-glow {
                0%, 100% {
                    opacity: 0.45;
                    transform: scale(1);
                }
                50% {
                    opacity: 0.75;
                    transform: scale(1.08);
                }
            }

            @keyframes netkit-star-twinkle {
                0%, 100% {
                    opacity: 0.18;
                    transform: scale(0.8);
                }
                50% {
                    opacity: 0.95;
                    transform: scale(1.15);
                }
            }

            .netkit-hero-planet {
                position: absolute;
                top: 50%;
                left: 50%;
                width: clamp(260px, 42vw, 540px);
                height: auto;
                transform: translate(-50%, -50%);
                opacity: 0.48;
                filter: drop-shadow(0 22px 48px rgba(15, 23, 42, 0.28));
            }

            .netkit-hero-starfield {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                opacity: 0.78;
                filter: drop-shadow(0 0 0.6px rgba(226, 232, 255, 0.9));
            }

            .netkit-hero-night-sky {
                opacity: 0;
                transition: opacity 260ms ease;
            }

            .netkit-wordmark {
                display: block;
                width: clamp(180px, 22vw, 320px);
                height: auto;
                margin: 0 auto 0.5rem;
                position: relative;
                z-index: 1;
            }

            .netkit-flow {
                position: absolute;
                top: 0;
                left: 50%;
                width: clamp(680px, 98vw, 1480px);
                height: auto;
                transform: translateX(-50%);
                pointer-events: none;
                z-index: 0;
            }

            .netkit-fill-logo {
                display: block;
                width: 60.75%;
                max-width: 802px;
                height: auto;
                margin: 0 auto 0.25rem;
                position: relative;
                z-index: 1;
            }

            .netkit-fill-logo-dark {
                display: none;
            }

            .dark .netkit-fill-logo-light {
                display: none;
            }

            .dark .netkit-fill-logo-dark {
                display: block;
            }

            .netkit-home-hero.netkit-hero-circuits,
            .netkit-home-hero.netkit-hero-fill_logo {
                background-color: #f8fbff;
                background-image: linear-gradient(135deg, #ffffff 0%, #eef5ff 48%, #e8eef9 100%);
            }

            .dark .netkit-home-hero.netkit-hero-circuits,
            .dark .netkit-home-hero.netkit-hero-fill_logo {
                background-color: #050816;
                background-image:
                    linear-gradient(180deg, rgba(5, 8, 22, 0.08) 0%, rgba(5, 8, 22, 0.22) 58%, rgba(17, 24, 39, 0.72) 100%),
                    url('/images/sky_bg_dark_mode.png');
                background-position: center;
                background-size: cover;
                background-repeat: no-repeat;
            }

            .dark .netkit-home-hero.netkit-hero-circuits .netkit-hero-night-sky,
            .dark .netkit-home-hero.netkit-hero-fill_logo .netkit-hero-night-sky {
                opacity: 0.72;
                mix-blend-mode: screen;
            }

            .dark .netkit-home-hero.netkit-hero-circuits .netkit-hero-starfield,
            .dark .netkit-home-hero.netkit-hero-fill_logo .netkit-hero-starfield {
                opacity: 0.9;
                filter:
                    drop-shadow(0 0 1px rgba(255, 255, 255, 0.95))
                    drop-shadow(0 0 6px rgba(147, 197, 253, 0.45));
            }

            .netkit-home-hero.netkit-hero-space .netkit-hero-night-sky {
                opacity: 1;
            }

            .netkit-hero-circuits .netkit-hero-heading,
            .netkit-hero-fill_logo .netkit-hero-heading {
                color: #0f1115;
            }

            .netkit-hero-circuits .netkit-hero-subtitle,
            .netkit-hero-fill_logo .netkit-hero-subtitle {
                color: #3f4651;
            }

            .dark .netkit-hero-circuits .netkit-hero-heading,
            .dark .netkit-hero-fill_logo .netkit-hero-heading {
                color: #ffffff;
                text-shadow: 0 2px 18px rgba(15, 23, 42, 0.55);
            }

            .dark .netkit-hero-circuits .netkit-hero-subtitle,
            .dark .netkit-hero-fill_logo .netkit-hero-subtitle {
                color: #e5edff;
                text-shadow: 0 1px 14px rgba(15, 23, 42, 0.5);
            }

            @media (max-width: 1024px) {
                .netkit-hero-planet {
                    width: clamp(230px, 66vw, 420px);
                    opacity: 0.34;
                }
            }

            .netkit-planet-system {
                animation: netkit-planet-float 7s ease-in-out infinite;
                transform-origin: center;
            }

            .netkit-planet-glow {
                animation: netkit-planet-glow 6s ease-in-out infinite;
                transform-box: view-box;
                transform-origin: 220px 220px;
            }

            .netkit-ring-tilt {
                transform: rotate(-22deg) scaleY(0.4);
                transform-box: view-box;
                transform-origin: 220px 220px;
            }

            .netkit-ring-spin {
                transform-box: view-box;
                transform-origin: 220px 220px;
            }

            .netkit-ring-spin-fast {
                animation: netkit-ring-spin 22s linear infinite;
            }

            .netkit-ring-spin-slow {
                animation: netkit-ring-spin 34s linear infinite;
            }

            .netkit-star {
                animation: netkit-star-twinkle var(--star-duration, 3s) ease-in-out infinite;
                animation-delay: var(--star-delay, 0s);
                transform-box: fill-box;
                transform-origin: center;
            }

            .netkit-star-spark line {
                stroke-linecap: round;
            }

            .netkit-home-hero {
                background-color: #1b2230;
            }

            .dark .netkit-home-hero {
                background-color: #0b0f17;
            }

            @media (prefers-reduced-motion: reduce) {
                .netkit-planet-system,
                .netkit-planet-glow,
                .netkit-ring-spin-fast,
                .netkit-ring-spin-slow,
                .netkit-star,
                .netkit-hero-night-sky {
                    animation: none;
                    transition: none;
                }
            }
        </style>
    </head>
    <body class="bg-white dark:bg-gray-900 antialiased">
        <!-- Header -->
        <x-site-header />


        <!-- Hero Section -->
        @php
            $netkitAnimationCookie = 'netkit_intro_seen';
            $showNetkitAnimation = ! request()->cookies->has($netkitAnimationCookie);
            $netkitLogoLight = asset('images/' . ($showNetkitAnimation ? 'netkit-fill-logo.svg' : 'netkit-fill-logo-static.svg'));
            $netkitLogoDark = asset('images/' . ($showNetkitAnimation ? 'netkit-fill-logo-dark.svg' : 'netkit-fill-logo-dark-static.svg'));
            $netkitLogoStaticLight = asset('images/netkit-fill-logo-static.svg');
            $netkitLogoStaticDark = asset('images/netkit-fill-logo-dark-static.svg');
            $heroBackground = config('home.hero_background', 'fill_logo');
            $stars = [];
            mt_srand(20260531);
            for ($i = 0; $i < 130; $i++) {
                $stars[] = [
                    'x' => mt_rand(0, 1000) / 10,
                    'y' => mt_rand(0, 1000) / 10,
                    'r' => mt_rand(5, 18) / 100,
                    'o' => mt_rand(35, 92) / 100,
                    'd' => mt_rand(35, 85) / 10,
                    'delay' => mt_rand(0, 50) / 10,
                    'spark' => $i % 12 === 0,
                    'len' => mt_rand(35, 95) / 100,
                ];
            }
        @endphp
        <section class="netkit-home-hero netkit-hero-{{ $heroBackground }} relative overflow-hidden pt-20 pb-4 px-4 sm:px-6 lg:px-8">
            <div class="netkit-hero-night-sky absolute inset-0 pointer-events-none" aria-hidden="true">
                <svg class="netkit-hero-starfield" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid slice" role="img" focusable="false">
                    @foreach ($stars as $s)
                        @if ($s['spark'])
                            <g class="netkit-star netkit-star-spark" style="--star-duration: {{ $s['d'] }}s; --star-delay: -{{ $s['delay'] }}s;" transform="translate({{ $s['x'] }} {{ $s['y'] }})" opacity="{{ $s['o'] }}">
                                <line x1="-{{ $s['len'] }}" y1="0" x2="{{ $s['len'] }}" y2="0" stroke="#ffffff" stroke-width="0.12" />
                                <line x1="0" y1="-{{ $s['len'] }}" x2="0" y2="{{ $s['len'] }}" stroke="#ffffff" stroke-width="0.12" />
                                <circle cx="0" cy="0" r="{{ $s['r'] * 1.25 }}" fill="#ffffff" />
                            </g>
                        @else
                            <circle class="netkit-star" style="--star-duration: {{ $s['d'] }}s; --star-delay: -{{ $s['delay'] }}s;" cx="{{ $s['x'] }}" cy="{{ $s['y'] }}" r="{{ $s['r'] }}" fill="#f8fbff" opacity="{{ $s['o'] }}" />
                        @endif
                    @endforeach
                </svg>
                @if ($heroBackground === 'space')
                <svg class="netkit-hero-planet" viewBox="0 0 440 440" role="img" focusable="false">
                    <defs>
                        <radialGradient id="planetBody" cx="0.38" cy="0.34" r="0.9">
                            <stop offset="0" stop-color="#f5e6c5" />
                            <stop offset="0.4" stop-color="#e3c08a" />
                            <stop offset="0.72" stop-color="#c89b5f" />
                            <stop offset="1" stop-color="#8a6638" />
                        </radialGradient>
                        <radialGradient id="planetHalo" cx="0.5" cy="0.5" r="0.5">
                            <stop offset="0" stop-color="#f5d9a8" stop-opacity="0.5" />
                            <stop offset="0.7" stop-color="#d8b173" stop-opacity="0.18" />
                            <stop offset="1" stop-color="#d8b173" stop-opacity="0" />
                        </radialGradient>
                        <linearGradient id="planetRim" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0" stop-color="#fff4dc" stop-opacity="0.9" />
                            <stop offset="1" stop-color="#c89b5f" stop-opacity="0" />
                        </linearGradient>
                        <clipPath id="planetClip">
                            <circle cx="220" cy="220" r="120" />
                        </clipPath>
                    </defs>

                    <g class="netkit-planet-system">
                        @php
                            $cx = 220; $cy = 220;
                            $palette = ['#f5e6c5', '#e3c08a', '#c89b5f', '#e9cd97', '#d8b173', '#fff4dc', '#b78b50'];
                            $buildRing = function (array $bands, float $seed) use ($cx, $cy, $palette) {
                                $out = '';
                                foreach ($bands as $bi => $band) {
                                    [$radius, $count] = $band;
                                    for ($i = 0; $i < $count; $i++) {
                                        $ang = ($i / $count) * 2 * M_PI + $bi * 0.4 + $seed;
                                        $x = round($cx + $radius * cos($ang), 1);
                                        $y = round($cy + $radius * sin($ang), 1);
                                        $sr = round(1.8 + (($i * 7 + $bi * 3) % 5) * 0.55, 1);
                                        $col = $palette[($i + $bi * 2) % count($palette)];
                                        $op = round(0.5 + (($i * 3 + $bi) % 5) * 0.1, 2);
                                        $out .= "<circle cx=\"{$x}\" cy=\"{$y}\" r=\"{$sr}\" fill=\"{$col}\" opacity=\"{$op}\" />";
                                    }
                                }
                                return $out;
                            };
                        @endphp

                        <!-- atmospheric halo -->
                        <circle class="netkit-planet-glow" cx="220" cy="220" r="180" fill="url(#planetHalo)" />

                        <!-- back half of the particle ring (behind planet) -->
                        <g class="netkit-ring-tilt">
                            <g class="netkit-ring-spin netkit-ring-spin-slow">
                                {!! $buildRing([[152, 32], [172, 36], [192, 28]], 0.0) !!}
                            </g>
                        </g>

                        <!-- planet -->
                        <circle cx="220" cy="220" r="120" fill="url(#planetBody)" />
                        <g clip-path="url(#planetClip)">
                            <ellipse cx="186" cy="178" rx="54" ry="34" fill="#ffffff" opacity="0.16" />
                        </g>

                        <!-- front half of the particle ring (in front of planet) -->
                        <g class="netkit-ring-tilt">
                            <g class="netkit-ring-spin netkit-ring-spin-fast">
                                {!! $buildRing([[160, 34], [180, 38], [200, 30]], 0.35) !!}
                            </g>
                        </g>
                    </g>
                </svg>
                @endif
            </div>
            <div class="relative container mx-auto">
                <div class="text-center max-w-4xl mx-auto mb-4">
                    @if ($heroBackground === 'circuits')
                    <img class="netkit-fill-logo netkit-fill-logo-light" src="{{ $netkitLogoLight }}" data-netkit-intro-logo data-static-src="{{ $netkitLogoStaticLight }}" alt="Netkit" />
                    <img class="netkit-fill-logo netkit-fill-logo-dark" src="{{ $netkitLogoDark }}" data-netkit-intro-logo data-static-src="{{ $netkitLogoStaticDark }}" alt="Netkit" />
                    @elseif ($heroBackground === 'fill_logo')
                    <img class="netkit-fill-logo netkit-fill-logo-light" src="{{ $netkitLogoLight }}" data-netkit-intro-logo data-static-src="{{ $netkitLogoStaticLight }}" alt="Netkit" />
                    <img class="netkit-fill-logo netkit-fill-logo-dark" src="{{ $netkitLogoDark }}" data-netkit-intro-logo data-static-src="{{ $netkitLogoStaticDark }}" alt="Netkit" />
                    @endif
                    <h1 class="netkit-hero-heading leading-tight font-bold text-white py-4 mb-3 nkheader">
                        Expertly crafted tools, one place to manage them all
                    </h1>
                </div>

                
            </div>
        </section>

        <script>
            (() => {
                const COOKIE_NAME = 'netkit_intro_seen';
                const COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24;
                const ANIMATION_DURATION_MS = 3600;

                const hasCookie = () => document.cookie
                    .split(';')
                    .some((cookie) => cookie.trim().startsWith(`${COOKIE_NAME}=`));

                const setCookie = () => {
                    document.cookie = `${COOKIE_NAME}=1; max-age=${COOKIE_MAX_AGE_SECONDS}; path=/; samesite=lax`;
                };

                const showStaticLogo = () => {
                    document.querySelectorAll('[data-netkit-intro-logo]').forEach((logo) => {
                        const staticSrc = logo.getAttribute('data-static-src');
                        if (staticSrc && logo.getAttribute('src') !== staticSrc) {
                            logo.setAttribute('src', staticSrc);
                        }
                    });
                };

                if (hasCookie()) {
                    showStaticLogo();
                    return;
                }

                window.setTimeout(() => {
                    showStaticLogo();
                    setCookie();
                }, ANIMATION_DURATION_MS);
            })();
        </script>

        <!-- Logo Generator Showcase Section -->
        <section id="pdf-features" class="py-20 px-4 sm:px-6 lg:px-8 bg-gradient-to-b from-white via-slate-50 to-white dark:from-gray-900 dark:via-gray-900 dark:to-gray-900">
            <div class="container mx-auto">
                <div class="max-w-7xl mx-auto rounded-3xl border border-slate-200/80 dark:border-gray-700 bg-white/80 dark:bg-gray-800/70 backdrop-blur-xl shadow-[0_25px_60px_-30px_rgba(15,23,42,0.35)] p-5 sm:p-7 lg:p-8">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 lg:gap-10 items-start">
                        <div class="lg:col-span-2 space-y-5">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white">Logo Generator Showcase</h2>
                                </div>
                                <div class="inline-flex rounded-xl border border-slate-200 dark:border-gray-700 bg-slate-100/80 dark:bg-gray-900/70 p-1" id="logo-mode-switch">
                                    <button type="button" class="logo-mode-btn px-4 py-2 text-sm font-semibold rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm" data-mode="vector">Vector</button>
                                    <button type="button" class="logo-mode-btn px-4 py-2 text-sm font-semibold rounded-lg text-gray-600 dark:text-gray-300" data-mode="image">Raster</button>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-slate-200 dark:border-gray-700 bg-gradient-to-br from-slate-100 to-white dark:from-gray-800 dark:to-gray-900 p-4 shadow-inner">
                                <img
                                    id="logo-preview-main"
                                    src="{{ asset('images/home_page_images/vector/vector_lion.svg') }}"
                                    alt="Selected vector logo preview"
                                    class="w-full h-[260px] sm:h-[340px] lg:h-[420px] object-cover rounded-xl"
                                >
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3" id="logo-preview-thumbs">
                                <button type="button" class="logo-thumb group rounded-xl border-2 border-blue-500 p-1 bg-white dark:bg-gray-800 transition" data-mode="vector" data-preview-src="{{ asset('images/home_page_images/vector/vector_lion.svg') }}" data-preview-alt="Vector lion logo preview">
                                    <img src="{{ asset('images/home_page_images/vector/vector_lion.svg') }}" alt="Vector lion logo option" class="w-full h-20 object-cover rounded-lg">
                                </button>
                                <button type="button" class="logo-thumb group rounded-xl border-2 border-transparent hover:border-blue-400 p-1 bg-white dark:bg-gray-800 transition" data-mode="vector" data-preview-src="{{ asset('images/home_page_images/vector/vector_sun_abstract.svg') }}" data-preview-alt="Vector sun abstract logo preview">
                                    <img src="{{ asset('images/home_page_images/vector/vector_sun_abstract.svg') }}" alt="Vector sun abstract logo option" class="w-full h-20 object-cover rounded-lg">
                                </button>
                                <button type="button" class="logo-thumb group rounded-xl border-2 border-transparent hover:border-blue-400 p-1 bg-white dark:bg-gray-800 transition hidden" data-mode="image" data-preview-src="{{ asset('images/home_page_images/image/raster_dragon_photorealistic.webp') }}" data-preview-alt="Raster dragon logo preview">
                                    <img src="{{ asset('images/home_page_images/image/raster_dragon_photorealistic.webp') }}" alt="Raster dragon logo option" class="w-full h-20 object-cover rounded-lg">
                                </button>
                                <button type="button" class="logo-thumb group rounded-xl border-2 border-transparent hover:border-blue-400 p-1 bg-white dark:bg-gray-800 transition hidden" data-mode="image" data-preview-src="{{ asset('images/home_page_images/image/raster_icegiant_fantasy.png') }}" data-preview-alt="Raster ice giant logo preview">
                                    <img src="{{ asset('images/home_page_images/image/raster_icegiant_fantasy.png') }}" alt="Raster ice giant logo option" class="w-full h-20 object-cover rounded-lg">
                                </button>
                            </div>

                            <div class="rounded-2xl border border-blue-200 dark:border-blue-800/60 bg-blue-50/80 dark:bg-blue-900/20 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-blue-900 dark:text-blue-200">Want more logo ideas?</p>
                                    <p class="text-sm text-blue-700 dark:text-blue-300">Explore the full gallery of generated logos and open any design in the editor.</p>
                                </div>
                                <a href="{{ route('browse-logos') }}" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition whitespace-nowrap">
                                    Browse Logos
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                                    </svg>
                                </a>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-slate-200 dark:border-gray-700 bg-white/80 dark:bg-gray-900/70 p-6 shadow-sm">
                            <p class="inline-flex items-center rounded-full bg-blue-100 dark:bg-blue-900/30 px-3 py-1 text-xs font-semibold text-blue-700 dark:text-blue-300 mb-4" id="logo-mode-badge">
                                Viewing: Vector
                            </p>
                            <h3 class="text-2xl md:text-3xl font-bold text-gray-900 dark:text-white mb-4">
                                AI Logo Generator
                            </h3>
                            <p class="text-gray-600 dark:text-gray-300 mb-5">
                                Explore logo concepts with quick switching between clean vector styles and realistic image-based directions.
                            </p>
                            <ul class="list-disc pl-5 space-y-3 text-gray-700 dark:text-gray-200">
                                <li>Instantly toggle between vector and image logo modes</li>
                                <li>Click any icon variation to update the main preview</li>
                                <li>Compare design direction in a polished side-by-side workspace</li>
                                <li>Use this gallery as a fast concept validation step</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- PDF Features Section -->
        <section class="py-20 px-4 sm:px-6 lg:px-8 bg-gray-50 dark:bg-gray-800">
            <div class="container mx-auto">
                <div class="text-center max-w-3xl mx-auto mb-16">
                    <h2 class="text-4xl md:text-5xl font-bold text-gray-900 dark:text-white mb-4">
                        Powerful PDF Editing Features
                    </h2>
                    <p class="text-lg text-gray-600 dark:text-gray-300">
                        Everything you need to edit, annotate, and manage your PDF documents
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 max-w-7xl mx-auto">
                    <!-- Edit Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Edit</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Directly edit text, images, and content within your PDF documents with precision and ease.
                        </p>
                    </div>

                    <!-- AI Generate Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-purple-100 dark:bg-purple-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">AI Generate</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Leverage AI to automatically generate content, summaries, and intelligent document enhancements.
                        </p>
                    </div>

                    <!-- Merge Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Merge</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Combine multiple PDF documents into a single file seamlessly with drag-and-drop simplicity.
                        </p>
                    </div>

                    <!-- Protect Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-red-100 dark:bg-red-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Protect</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Secure your documents with password protection and encryption to keep sensitive data safe.
                        </p>
                    </div>

                    <!-- Draw Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Draw</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Freehand drawing tools to sketch, highlight, and add custom visual elements to your PDFs.
                        </p>
                    </div>

                    <!-- Annotate Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Annotate</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Add comments, notes, and markup to collaborate and provide feedback on PDF documents.
                        </p>
                    </div>

                    <!-- Sign Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-pink-100 dark:bg-pink-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-pink-600 dark:text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Sign</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Add legally binding electronic signatures to documents with full compliance and security.
                        </p>
                    </div>

                    <!-- Domain Search Feature -->
                    <a href="{{ route('domainSearch.index') }}" class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group block">
                        <div class="w-14 h-14 bg-cyan-100 dark:bg-cyan-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-cyan-600 dark:text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Domain Search</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Search for available domain names and generate creative suggestions for your next project.
                        </p>
                    </a>

                    <!-- Get Started CTA Card -->
                    <div class="bg-gradient-to-br from-blue-600 to-purple-600 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group flex flex-col justify-center items-center text-center">
                        <h3 class="text-2xl font-bold text-white mb-3">Ready to Start?</h3>
                        <p class="text-blue-100 text-sm mb-6">
                            Access all features now
                        </p>
                        <a href="{{ route('documents.index') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-white text-blue-600 rounded-lg font-semibold hover:bg-gray-100 transition">
                            Open Editor
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section class="py-20 px-4 sm:px-6 lg:px-8 bg-gradient-to-br from-blue-600 via-blue-700 to-purple-700 text-white">
            <div class="container mx-auto text-center">
                <h2 class="text-4xl md:text-5xl font-bold mb-6">
                    Join thousands using the #1<br>PDF Editor & Admin Dashboard!
                </h2>
                <p class="text-xl text-blue-100 mb-8 max-w-2xl mx-auto">
                    Start building amazing PDF editing experiences and admin panels today
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/admin/login" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 bg-white hover:bg-gray-100 text-blue-600 rounded-lg font-semibold text-lg transition shadow-xl">
                        Login
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                        </svg>
                    </a>
                    <a href="{{ route('documents.index') }}" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 border-2 border-white hover:bg-white/10 text-white rounded-lg font-semibold text-lg transition">
                        Live Preview
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </a>
                </div>
            </div>
        </section>

        <!-- FAQ Section -->
        <section id="faq" class="py-20 px-4 sm:px-6 lg:px-8 bg-white dark:bg-gray-900">
            <div class="container mx-auto">
                <div class="text-center max-w-3xl mx-auto mb-16">
                    <h2 class="text-4xl md:text-5xl font-bold text-gray-900 dark:text-white mb-4">
                        Frequently Asked Questions
                    </h2>
                    <p class="text-lg text-gray-600 dark:text-gray-300">
                        Find answers to common questions about our AI-enabled tools and services
                    </p>
                </div>
                
                <div class="max-w-4xl mx-auto">
                    <div class="space-y-6">
                        <div class="py-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
                                What tools are included in Netkit?
                            </h3>
                            <p class="text-gray-600 dark:text-gray-300">
                                Netkit includes a comprehensive PDF editor, domain search capabilities, and various AI-enabled tools for document management and business operations.
                            </p>
                        </div>
                        
                        <div class="py-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
                                Is Netkit secure and reliable?
                            </h3>
                            <p class="text-gray-600 dark:text-gray-300">
                                Yes, Netkit is built with security as a priority. We implement industry-standard encryption and security practices to protect your data.
                            </p>
                        </div>
                        
                        <div class="py-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
                                How affordable are your tools?
                            </h3>
                            <p class="text-gray-600 dark:text-gray-300">
                                Our tools are designed to be as affordable as they are powerful, with flexible pricing options to suit different needs and budgets.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-12 px-4 sm:px-6 lg:px-8 bg-gray-900 text-gray-300">
            <div class="container mx-auto">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <svg class="h-8 w-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span class="text-xl font-bold text-white">Toolbase</span>
                        </div>
                        <p class="text-sm">Free and Open-Source PDF Editor & Admin Dashboard Template</p>
                    </div>
                    <div>
                        <h3 class="font-semibold text-white mb-4">Useful Links</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="hover:text-white transition">Documentation</a></li>
                            <li><a href="#" class="hover:text-white transition">Blog</a></li>
                            <li><a href="#" class="hover:text-white transition">Update Logs</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold text-white mb-4">About</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="hover:text-white transition">Privacy Policy</a></li>
                            <li><a href="#" class="hover:text-white transition">License</a></li>
                            <li><a href="#" class="hover:text-white transition">Support</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold text-white mb-4">Newsletter</h3>
                        <p class="text-sm mb-4">Subscribe for the latest updates</p>
                        <input type="email" placeholder="Enter your email" class="w-full px-4 py-2 rounded-lg bg-gray-800 border border-gray-700 focus:border-blue-500 focus:outline-none text-sm">
                    </div>
                </div>
                <div class="border-t border-gray-800 pt-8 text-center text-sm">
                    <p>&copy; 2026 Netkit - All Rights Reserved.</p>
                </div>
            </div>
        </footer>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const preview = document.getElementById('logo-preview-main');
                const thumbs = document.querySelectorAll('.logo-thumb');
                const modeButtons = document.querySelectorAll('.logo-mode-btn');
                const modeBadge = document.getElementById('logo-mode-badge');

                if (!preview || !thumbs.length) {
                    return;
                }

                function setActiveThumb(activeThumb) {
                    thumbs.forEach(function (item) {
                        item.classList.remove('border-blue-500');
                        item.classList.add('border-transparent');
                    });

                    activeThumb.classList.remove('border-transparent');
                    activeThumb.classList.add('border-blue-500');
                }

                function setPreviewFromThumb(thumb) {
                    const nextSrc = thumb.getAttribute('data-preview-src');
                    const nextAlt = thumb.getAttribute('data-preview-alt');

                    if (nextSrc) {
                        preview.src = nextSrc;
                    }

                    if (nextAlt) {
                        preview.alt = nextAlt;
                    }
                }

                function setMode(mode) {
                    modeButtons.forEach(function (btn) {
                        const selected = btn.getAttribute('data-mode') === mode;
                        btn.classList.toggle('bg-white', selected);
                        btn.classList.toggle('dark:bg-gray-700', selected);
                        btn.classList.toggle('shadow-sm', selected);
                        btn.classList.toggle('text-gray-900', selected);
                        btn.classList.toggle('dark:text-white', selected);
                        btn.classList.toggle('text-gray-600', !selected);
                        btn.classList.toggle('dark:text-gray-300', !selected);
                    });

                    thumbs.forEach(function (thumb) {
                        const thumbMode = thumb.getAttribute('data-mode');
                        thumb.classList.toggle('hidden', thumbMode !== mode);
                    });

                    const visibleThumb = document.querySelector('.logo-thumb[data-mode="' + mode + '"]');
                    if (visibleThumb) {
                        setPreviewFromThumb(visibleThumb);
                        setActiveThumb(visibleThumb);
                    }

                    if (modeBadge) {
                        const modeLabel = mode === 'image' ? 'Raster' : 'Vector';
                        modeBadge.textContent = 'Viewing: ' + modeLabel;
                    }
                }

                thumbs.forEach(function (thumb) {
                    thumb.addEventListener('click', function () {
                        if (thumb.classList.contains('hidden')) {
                            return;
                        }

                        setPreviewFromThumb(thumb);
                        setActiveThumb(thumb);
                    });
                });

                modeButtons.forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const selectedMode = btn.getAttribute('data-mode') || 'vector';
                        setMode(selectedMode);
                    });
                });

                setMode('vector');
            });
        </script>
    </body>
</html>
