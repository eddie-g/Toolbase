<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Generated Images</h2>
                <p class="text-sm text-gray-500">All images and vectors generated from Logo Generator.</p>
            </div>

            <div class="flex items-center gap-2">
                <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1">
                    <button
                        type="button"
                        wire:click="setFormatFilter('all')"
                        @class([
                            'rounded-md px-3 py-1.5 text-sm font-medium transition',
                            'bg-gray-900 text-white' => $this->formatFilter === 'all',
                            'text-gray-600 hover:text-gray-900' => $this->formatFilter !== 'all',
                        ])
                    >
                        All
                    </button>
                    <button
                        type="button"
                        wire:click="setFormatFilter('raster')"
                        @class([
                            'rounded-md px-3 py-1.5 text-sm font-medium transition',
                            'bg-gray-900 text-white' => $this->formatFilter === 'raster',
                            'text-gray-600 hover:text-gray-900' => $this->formatFilter !== 'raster',
                        ])
                    >
                        Raster
                    </button>
                    <button
                        type="button"
                        wire:click="setFormatFilter('vector')"
                        @class([
                            'rounded-md px-3 py-1.5 text-sm font-medium transition',
                            'bg-gray-900 text-white' => $this->formatFilter === 'vector',
                            'text-gray-600 hover:text-gray-900' => $this->formatFilter !== 'vector',
                        ])
                    >
                        Vector
                    </button>
                </div>
                <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1">
                    <button
                        type="button"
                        wire:click="setViewMode('grid')"
                        @class([
                            'rounded-md px-3 py-1.5 text-sm font-medium transition',
                            'bg-gray-900 text-white' => $this->viewMode === 'grid',
                            'text-gray-600 hover:text-gray-900' => $this->viewMode !== 'grid',
                        ])
                    >
                        Grid
                    </button>
                    <button
                        type="button"
                        wire:click="setViewMode('table')"
                        @class([
                            'rounded-md px-3 py-1.5 text-sm font-medium transition',
                            'bg-gray-900 text-white' => $this->viewMode === 'table',
                            'text-gray-600 hover:text-gray-900' => $this->viewMode !== 'table',
                        ])
                    >
                        Table
                    </button>
                </div>
            </div>
        </div>

        @if ($this->requests->count() === 0)
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-6 py-10 text-center text-sm text-gray-500">
                No generated images found yet.
            </div>
        @elseif ($this->viewMode === 'grid')
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($this->requests as $request)
                    @php
                        $urls = is_array($request->image_urls) ? array_values(array_filter($request->image_urls)) : [];
                        $cover = $urls[0] ?? null;
                        $cost = $request->latest_cost_usd ?? null;
                    @endphp

                    <article class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <div class="aspect-square bg-gray-100">
                            @if ($cover)
                                <img src="{{ $cover }}" alt="Generated image" class="h-full w-full object-cover" loading="lazy" />
                            @else
                                <div class="flex h-full items-center justify-center text-sm text-gray-400">No preview</div>
                            @endif
                        </div>

                        <div class="space-y-2 p-3">
                            <div class="flex items-center justify-between text-xs text-gray-500 gap-2">
                                <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 font-semibold">{{ strtoupper((string) ($request->output_format ?? 'image')) }}</span>
                                <span class="inline-flex rounded-full bg-blue-100 px-2 py-0.5 font-semibold text-blue-700">{{ $this->modelLabel($request->model) }}</span>
                                <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-emerald-700">
                                    ${{ number_format((float) ($cost ?? 0), 4) }}
                                </span>
                                <span>{{ optional($request->created_at)->format('M d, Y H:i') }}</span>
                            </div>
                            @if ($this->editingRequestId === (int) $request->id)
                                <div class="flex items-center gap-2">
                                    <input
                                        type="text"
                                        wire:model="editingName"
                                        class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                                    />
                                    <button wire:click="saveRename({{ $request->id }})" class="rounded-lg bg-gray-900 px-2 py-1.5 text-xs font-semibold text-white">Save</button>
                                    <button wire:click="cancelRename" class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs font-semibold text-gray-600">Cancel</button>
                                </div>
                            @else
                                <div class="flex items-center justify-between gap-2">
                                    <p class="truncate text-sm font-medium text-gray-900">{{ $request->domain ?: 'Untitled' }}</p>
                                    <button wire:click="startRename({{ $request->id }})" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-600">Edit</button>
                                </div>
                            @endif

                            <p class="line-clamp-2 text-xs text-gray-500">{{ $request->original_prompt ?: 'Prompt hidden' }}</p>

                            @if (count($urls) > 1)
                                <div class="grid grid-cols-4 gap-1.5 pt-1">
                                    @foreach (array_slice($urls, 0, 4) as $thumb)
                                        <img src="{{ $thumb }}" alt="Thumbnail" class="h-12 w-full rounded-md border border-gray-200 object-cover" loading="lazy" />
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Preview</th>
                            <th class="px-4 py-3 text-left font-semibold">Domain</th>
                            <th class="px-4 py-3 text-left font-semibold">Your Prompt</th>
                            <th class="px-4 py-3 text-left font-semibold">Model</th>
                            <th class="px-4 py-3 text-left font-semibold">Cost</th>
                            <th class="px-4 py-3 text-left font-semibold">Type</th>
                            <th class="px-4 py-3 text-left font-semibold">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($this->requests as $request)
                            @php
                                $urls = is_array($request->image_urls) ? array_values(array_filter($request->image_urls)) : [];
                                $cover = $urls[0] ?? null;
                            @endphp
                            <tr>
                                <td class="px-4 py-3">
                                    @if ($cover)
                                        <img src="{{ $cover }}" alt="Generated image" class="h-12 w-12 rounded-md border border-gray-200 object-cover" loading="lazy" />
                                    @else
                                        <div class="h-12 w-12 rounded-md border border-gray-200 bg-gray-100"></div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    @if ($this->editingRequestId === (int) $request->id)
                                        <div class="flex items-center gap-2">
                                            <input
                                                type="text"
                                                wire:model="editingName"
                                                class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm"
                                            />
                                            <button wire:click="saveRename({{ $request->id }})" class="rounded-lg bg-gray-900 px-2 py-1.5 text-xs font-semibold text-white">Save</button>
                                            <button wire:click="cancelRename" class="rounded-lg border border-gray-300 px-2 py-1.5 text-xs font-semibold text-gray-600">Cancel</button>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-2">
                                            <span>{{ $request->domain ?: '—' }}</span>
                                            <button wire:click="startRename({{ $request->id }})" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-600">Edit</button>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    <span class="block max-w-md truncate">{{ $request->original_prompt ?: 'Prompt hidden' }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ $this->modelLabel($request->model) }}</td>
                                <td class="px-4 py-3 text-gray-600">${{ number_format((float) ($request->latest_cost_usd ?? 0), 4) }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ strtoupper((string) ($request->output_format ?? 'image')) }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ optional($request->created_at)->format('M d, Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div>
            {{ $this->requests->links() }}
        </div>
    </div>
</x-filament-panels::page>
