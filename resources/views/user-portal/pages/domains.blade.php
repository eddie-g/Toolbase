<x-filament-panels::page>
    <style>
        .tb-domains-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgb(186 230 253);
            border-radius: 16px;
            padding: 28px;
            background:
                radial-gradient(120% 140% at 100% 0%, rgba(56, 189, 248, 0.18), transparent 55%),
                linear-gradient(135deg, rgb(240 249 255), rgb(238 242 255));
            box-shadow: 0 10px 30px -18px rgba(2, 132, 199, 0.45);
        }

        .dark .tb-domains-hero {
            border-color: rgba(14, 116, 144, 0.45);
            background:
                radial-gradient(120% 140% at 100% 0%, rgba(8, 145, 178, 0.25), transparent 55%),
                linear-gradient(135deg, rgba(8, 47, 73, 0.55), rgba(30, 27, 75, 0.45));
            box-shadow: 0 16px 40px -24px rgba(2, 6, 23, 0.9);
        }

        .tb-domains-hero__row {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        @media (min-width: 768px) {
            .tb-domains-hero__row {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .tb-domains-hero__badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 9999px;
            background: rgba(2, 132, 199, 0.12);
            color: rgb(3 105 161);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .dark .tb-domains-hero__badge {
            background: rgba(56, 189, 248, 0.16);
            color: rgb(125 211 252);
        }

        .tb-domains-hero__title {
            margin-top: 12px;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.2;
            color: rgb(8 47 73);
        }

        .dark .tb-domains-hero__title {
            color: rgb(224 242 254);
        }

        .tb-domains-hero__text {
            margin-top: 8px;
            max-width: 38rem;
            font-size: 14px;
            line-height: 1.55;
            color: rgb(3 105 161);
        }

        .dark .tb-domains-hero__text {
            color: rgb(186 230 253);
        }
    </style>

    @php
        $selectedSearch = $this->selectedSearchRecord();
        $selectedRows = $selectedSearch ? $this->resultRowsFor($selectedSearch) : [];
    @endphp

    @if ($selectedSearch)
        <div class="space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold tracking-tight text-gray-950 dark:text-white">
                        Results
                    </h2>
                    <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">
                        {{ str($selectedSearch->prompt)->limit(120) }}
                    </p>
                </div>

                <x-filament::button
                    tag="a"
                    :href="\App\UserPortal\Pages\Domains::getUrl(panel: 'user')"
                    color="gray"
                    icon="heroicon-m-arrow-left"
                >
                    Back to domains
                </x-filament::button>
            </div>

            @include('user-portal.widgets.domain-search-results', [
                'rows' => $selectedRows,
            ])
        </div>
    @else

        <section class="tb-domains-hero">
            <div class="tb-domains-hero__row">
                <div>
                    <span class="tb-domains-hero__badge">
                        <x-filament::icon icon="heroicon-m-globe-alt" class="h-3.5 w-3.5" />
                        Workspace
                    </span>
                    <h2 class="tb-domains-hero__title">Find more domain ideas</h2>
                    <p class="tb-domains-hero__text">
                        Generate names, check availability, and save promising domains to this workspace.
                    </p>
                </div>

                <x-filament::button
                    tag="a"
                    :href="route('domainSearch.index')"
                    size="lg"
                    icon="heroicon-m-sparkles"
                >
                    Open Domain Generator
                </x-filament::button>
            </div>
        </section>

        <div class="mt-6">
            @livewire(\App\UserPortal\Widgets\UserRecentDomainSearchesWidget::class)
        </div>

        <div class="mt-6">
            @livewire(\App\UserPortal\Widgets\UserFavoriteDomainsWidget::class)
        </div>
    @endif
</x-filament-panels::page>
