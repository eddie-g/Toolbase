<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Explanation --}}
        <div class="rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800 p-4 text-sm text-amber-900 dark:text-amber-200">
            <p class="font-semibold mb-1">Why this exists</p>
            <p>
                When the PDF editor restamps text on a promoted source annotation, it tries to use the
                <em>runtime-extracted</em> copy of the source font. Those extractions are themselves <strong>glyph subsets</strong>
                (they only contain the characters that appeared in the source PDF), so introducing new characters renders as
                missing-glyph boxes. Uploading the <strong>full</strong> OTF/TTF here lets the writer prefer it over the subset
                and achieve true visual fidelity for any character.
            </p>
            <p class="mt-2">
                Filename = the PostScript name as it appears in the source PDF (e.g.
                <code>ITCFranklinGothicStd-Demi.otf</code>, <code>HelveticaNeueLTStd-Md.otf</code>).
                Storage location (server-side, not public):
                <code class="text-xs">{{ $dir }}</code>
            </p>
        </div>

        {{-- Upload form --}}
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">Upload font</h3>
            <form wire:submit.prevent="save" class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Font file (.otf or .ttf, max 20 MB)</label>
                    <input
                        type="file"
                        wire:model="upload"
                        accept=".otf,.ttf"
                        class="block w-full text-sm text-gray-700 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-primary-600 file:text-white file:cursor-pointer hover:file:bg-primary-700"
                    />
                    @error('upload') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        PostScript name override <span class="text-gray-400 font-normal">(optional — defaults to filename stem)</span>
                    </label>
                    <input
                        type="text"
                        wire:model.defer="psnameOverride"
                        placeholder="e.g. ITCFranklinGothicStd-Demi"
                        class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm text-sm px-3 py-2"
                    />
                    @error('psnameOverride') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <button
                    type="submit"
                    class="inline-flex items-center px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
                    wire:loading.attr="disabled"
                    wire:target="save,upload"
                >
                    <span wire:loading.remove wire:target="save,upload">Save</span>
                    <span wire:loading wire:target="save,upload">Uploading…</span>
                </button>
            </form>
        </div>

        {{-- Existing fonts --}}
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">Installed full fonts</h3>
            @if (empty($items))
                <p class="text-sm text-gray-500 dark:text-gray-400">No full fonts uploaded yet.</p>
            @else
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4">PostScript name</th>
                            <th class="py-2 pr-4">File</th>
                            <th class="py-2 pr-4">Size</th>
                            <th class="py-2 pr-4">Uploaded</th>
                            <th class="py-2 pr-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($items as $item)
                        <tr class="text-gray-800 dark:text-gray-200">
                            <td class="py-2 pr-4 font-mono">{{ $item['psname'] }}</td>
                            <td class="py-2 pr-4">{{ $item['name'] }}</td>
                            <td class="py-2 pr-4 tabular-nums">{{ number_format($item['size_kb']) }} KB</td>
                            <td class="py-2 pr-4 text-gray-500">{{ \Carbon\Carbon::createFromTimestamp($item['mtime'])->diffForHumans() }}</td>
                            <td class="py-2 pr-4">
                                <button
                                    wire:click="delete('{{ $item['name'] }}')"
                                    wire:confirm="Remove {{ $item['name'] }}?"
                                    class="text-red-600 hover:text-red-700 text-xs font-medium"
                                >
                                    Delete
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

    </div>
</x-filament-panels::page>
