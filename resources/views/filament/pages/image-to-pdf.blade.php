<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <form wire:submit.prevent="convert" class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Source image
                    </label>
                    <input
                        type="file"
                        wire:model="upload"
                        accept=".jpg,.jpeg,.png,.webp,.bmp,.tif,.tiff,image/*"
                        class="mt-2 block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-primary-600 file:px-3 file:py-2 file:text-white file:cursor-pointer hover:file:bg-primary-700 dark:text-gray-300"
                    />
                    @error('upload')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Conversion mode
                        </label>
                        <select
                            wire:model.defer="mode"
                            class="mt-2 block w-full rounded-md border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        >
                            <option value="reconstruct">Editable reconstruction</option>
                            <option value="image-backed">Exact image background</option>
                        </select>
                        @error('mode')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Page size
                        </label>
                        <select
                            wire:model.defer="pageSize"
                            class="mt-2 block w-full rounded-md border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        >
                            <option value="letter">Letter, auto orientation</option>
                            <option value="a4">A4, auto orientation</option>
                            <option value="legal">Legal, auto orientation</option>
                            <option value="source">Match image aspect</option>
                        </select>
                        @error('pageSize')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Max reconstructed shapes
                        </label>
                        <input
                            type="number"
                            min="0"
                            max="800"
                            step="1"
                            wire:model.defer="maxShapes"
                            class="mt-2 block w-full rounded-md border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        />
                        @error('maxShapes')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                    Editable reconstruction creates a blank PDF with OCR text boxes and detected layout shapes. Exact image background embeds the upload as a non-editable page image and adds OCR handles only when Tesseract is available.
                </div>

                <div>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="convert,upload"
                        class="inline-flex items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-70"
                    >
                        <span wire:loading.remove wire:target="convert,upload">Convert and open editor</span>
                        <span wire:loading wire:target="convert,upload">Converting...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-filament-panels::page>
