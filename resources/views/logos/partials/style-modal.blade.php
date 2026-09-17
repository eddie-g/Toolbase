{{-- The style and theme picker of the Logo Lab, in the site's own visual language. Opened by showStyleModal; every binding is logoGenerator()'s. --}}
        <!-- Style Selection Modal -->
        <div x-show="showStyleModal" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/60 backdrop-blur-sm p-4" @click.self="showStyleModal = false" @keydown.escape.window="showStyleModal = false">
            <div class="relative flex max-h-[calc(100vh-2rem)] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white dark:bg-zinc-900 shadow-xl" @click.stop
                x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
                <div class="flex items-center justify-between px-5 py-4 border-b border-zinc-200 dark:border-zinc-800">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-50">Choose Style</h3>
                    <button @click="showStyleModal = false" class="p-1 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                        <svg class="w-5 h-5 text-zinc-500 dark:text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div class="overflow-y-auto p-5">
                    <div class="mb-5 grid grid-cols-2 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-950 p-1">
                        <button type="button" @click="styleModalTab = 'style'"
                            class="rounded-md px-3 py-2 text-xs font-bold uppercase tracking-wider transition"
                            :class="styleModalTab === 'style' ? 'bg-white dark:bg-zinc-900 text-blue-700 dark:text-blue-300 shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'">
                            Style
                        </button>
                        <button type="button" @click="styleModalTab = 'theme'"
                            class="rounded-md px-3 py-2 text-xs font-bold uppercase tracking-wider transition"
                            :class="styleModalTab === 'theme' ? 'bg-white dark:bg-zinc-900 text-blue-700 dark:text-blue-300 shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'">
                            Theme
                        </button>
                    </div>

                    <!-- DALL-E styles -->
                    <div x-show="styleModalTab === 'style' && selectedModel === 'dalle'">
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <button type="button" @click="selectStyle('default')"
                                class="group rounded-xl border p-3 transition-all text-center"
                                :class="logoStyle === 'default' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'default' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4.5 7.5h15m-15 4.5h15m-15 4.5h15"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'default' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Default</div>
                                <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">No style bias</div>
                            </button>
                            <button type="button" @click="selectStyle('professional')"
                                class="group rounded-xl border p-3 transition-all text-center"
                                :class="logoStyle === 'professional' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'professional' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'professional' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Professional</div>
                                <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Clean & modern</div>
                            </button>
                            <button type="button" @click="selectStyle('fantasy')"
                                class="group rounded-xl border p-3 transition-all text-center"
                                :class="logoStyle === 'fantasy' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'fantasy' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'fantasy' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Fantasy</div>
                                <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Magical & ornate</div>
                            </button>
                            <button type="button" @click="selectStyle('future')"
                                class="group rounded-xl border p-3 transition-all text-center"
                                :class="logoStyle === 'future' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'future' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'future' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Future</div>
                                <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Techy & sci-fi</div>
                            </button>
                            <button type="button" @click="selectStyle('retro')" x-show="logoMode !== 'icon_only'"
                                class="group rounded-xl border p-3 transition-all text-center"
                                :class="logoStyle === 'retro' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'retro' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'retro' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Retro</div>
                                <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Vintage & classic</div>
                            </button>
                            <button type="button" @click="selectStyle('greetingcard')"
                                class="group rounded-xl border p-3 transition-all text-center"
                                :class="logoStyle === 'greetingcard' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'greetingcard' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'greetingcard' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Watercolor</div>
                                <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Watercolor & gouache</div>
                            </button>
                            <button type="button" @click="selectStyle('photorealistic')"
                                class="group rounded-xl border p-3 transition-all text-center"
                                :class="logoStyle === 'photorealistic' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'photorealistic' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'photorealistic' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Photorealistic</div>
                                <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Lifelike & detailed</div>
                            </button>
                        </div>
                        <div class="mt-5 mb-4">
                            <div class="w-full h-px bg-gradient-to-r from-transparent via-blue-400/70 to-transparent"></div>
                            <div class="text-center -mt-3">
                                <span class="inline-flex px-3 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider bg-white dark:bg-zinc-900 text-blue-600 dark:text-blue-400">Dalle3 specific styles</span>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <button type="button" @click="selectStyle('chrome')" x-show="logoMode !== 'icon_only'"
                                class="group rounded-xl border p-2 transition-all text-center"
                                :class="logoStyle === 'chrome' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                                <div class="aspect-square rounded-lg overflow-hidden bg-zinc-100 dark:bg-zinc-800 mb-2">
                                    <img src="/images/chrome-preview.svg" alt="Chrome style" class="w-full h-full object-cover" />
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'chrome' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Chrome (Chome)</div>
                                <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">3D metallic render</div>
                                <span class="inline-block mt-1 px-1.5 py-0.5 rounded text-[9px] font-semibold bg-amber-100 text-amber-700">Icon Only</span>
                            </button>
                            <button type="button" @click="selectStyle('dotmatrix')" x-show="logoMode !== 'icon_only'"
                                class="group rounded-xl border p-3 transition-all text-center"
                                :class="logoStyle === 'dotmatrix' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'dotmatrix' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="currentColor" viewBox="0 0 24 24"><circle cx="6" cy="6" r="1.5"/><circle cx="12" cy="6" r="1.5"/><circle cx="18" cy="6" r="1.5"/><circle cx="6" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="18" cy="12" r="1.5"/><circle cx="6" cy="18" r="1.5"/><circle cx="12" cy="18" r="1.5"/><circle cx="18" cy="18" r="1.5"/></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'dotmatrix' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Dot Matrix</div>
                                <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Stipple art</div>
                                <span class="inline-block mt-1 px-1.5 py-0.5 rounded text-[9px] font-semibold bg-amber-100 text-amber-700">Icon Only</span>
                            </button>
                            <button type="button" @click="selectStyle('8bit')" x-show="logoMode !== 'icon_only'"
                                class="group rounded-xl border p-3 transition-all text-center"
                                :class="logoStyle === '8bit' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === '8bit' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === '8bit' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">8-Bit</div>
                                <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Fantasy RPG</div>
                            </button>
                        </div>
                    </div>

                    <!-- Flux/Recraft styles -->
                    <div x-show="styleModalTab === 'style' && selectedModel !== 'dalle'" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <button type="button" @click="selectStyle('default')"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'default' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'default' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4.5 7.5h15m-15 4.5h15m-15 4.5h15"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'default' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Default</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">No style bias</div>
                        </button>
                        <!-- Image styles: shown in raster/image mode -->
                        <button type="button" @click="selectStyle('professional')" x-show="outputFormat !== 'vector'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'professional' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'professional' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'professional' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Professional</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Clean & modern</div>
                        </button>
                        <button type="button" @click="selectStyle('fantasy')" x-show="outputFormat !== 'vector'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'fantasy' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'fantasy' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'fantasy' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Fantasy</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Magical & ornate</div>
                        </button>
                        <button type="button" @click="selectStyle('future')" x-show="outputFormat !== 'vector'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'future' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'future' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'future' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Future</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Techy & sci-fi</div>
                        </button>
                        <button type="button" @click="selectStyle('retro')" x-show="outputFormat !== 'vector'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'retro' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'retro' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'retro' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Retro</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Vintage & classic</div>
                        </button>
                        <button type="button" @click="selectStyle('greetingcard')" x-show="outputFormat !== 'vector'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'greetingcard' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'greetingcard' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'greetingcard' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Watercolor</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Watercolor & gouache</div>
                        </button>
                        <button type="button" @click="selectStyle('photorealistic')" x-show="outputFormat !== 'vector'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'photorealistic' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'photorealistic' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'photorealistic' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Photorealistic</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Lifelike & detailed</div>
                        </button>
                        <!-- Vector styles: shown in vector/logo mode -->
                        <button type="button" @click="selectStyle('minimal_geometric')" x-show="outputFormat === 'vector' && logoMode !== 'text_only'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'minimal_geometric' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                <template x-if="isSampledVectorMode()">
                                    <img :src="getVectorSampleUrl('minimal_geometric')" alt="Minimal Geometric sample" class="style-sample-image w-full h-full object-cover" loading="lazy" />
                                </template>
                                <template x-if="!isSampledVectorMode()">
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg class="w-6 h-6" :class="logoStyle === 'minimal_geometric' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"></path></svg>
                                    </div>
                                </template>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'minimal_geometric' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Minimal Geometric</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Clean shapes</div>
                        </button>
                        <button type="button" @click="selectStyle('abstract')" x-show="outputFormat === 'vector' && logoMode !== 'text_only'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'abstract' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                <template x-if="isSampledVectorMode()">
                                    <img :src="getVectorSampleUrl('abstract')" alt="Abstract sample" class="style-sample-image w-full h-full object-cover" loading="lazy" />
                                </template>
                                <template x-if="!isSampledVectorMode()">
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg class="w-6 h-6" :class="logoStyle === 'abstract' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                                    </div>
                                </template>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'abstract' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Abstract</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Dynamic shapes</div>
                        </button>
                        <button type="button" @click="selectStyle('monoline')" x-show="outputFormat === 'vector' && logoMode !== 'text_only'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'monoline' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                <template x-if="isSampledVectorMode()">
                                    <img :src="getVectorSampleUrl('monoline')" alt="Monoline sample" class="style-sample-image w-full h-full object-cover" loading="lazy" />
                                </template>
                                <template x-if="!isSampledVectorMode()">
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg class="w-6 h-6" :class="logoStyle === 'monoline' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 12 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    </div>
                                </template>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'monoline' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Monoline</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Continuous line</div>
                        </button>
                        <button type="button" @click="selectStyle('negative_space')" x-show="outputFormat === 'vector' && logoMode !== 'text_only'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'negative_space' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                <template x-if="isSampledVectorMode()">
                                    <img :src="getVectorSampleUrl('negative_space')" alt="Negative Space sample" class="style-sample-image w-full h-full object-cover" loading="lazy" />
                                </template>
                                <template x-if="!isSampledVectorMode()">
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg class="w-6 h-6" :class="logoStyle === 'negative_space' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"></path></svg>
                                    </div>
                                </template>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'negative_space' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Negative Space</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Hidden symbol</div>
                        </button>
                        <button type="button" @click="selectStyle('tech_gradient')" x-show="outputFormat === 'vector' && logoMode !== 'text_only'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'tech_gradient' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                <template x-if="isSampledVectorMode()">
                                    <img :src="getVectorSampleUrl('tech_gradient')" alt="Tech Gradient sample" class="style-sample-image w-full h-full object-cover" loading="lazy" />
                                </template>
                                <template x-if="!isSampledVectorMode()">
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg class="w-6 h-6" :class="logoStyle === 'tech_gradient' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path></svg>
                                    </div>
                                </template>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'tech_gradient' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Tech Gradient</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Futuristic</div>
                        </button>
                        <button type="button" @click="selectStyle('modern_sans')" x-show="outputFormat === 'vector' && logoMode === 'text_only'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'modern_sans' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <span class="text-lg font-semibold tracking-tight" :class="logoStyle === 'modern_sans' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-700 dark:text-zinc-300'">Aa</span>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'modern_sans' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Modern Sans</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Clean sans-serif</div>
                        </button>
                        <button type="button" @click="selectStyle('bold_geometric')" x-show="outputFormat === 'vector' && logoMode === 'text_only'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'bold_geometric' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <span class="text-xl font-black tracking-tight" :class="logoStyle === 'bold_geometric' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-700 dark:text-zinc-300'">Aa</span>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'bold_geometric' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Bold Geometric</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Strong structure</div>
                        </button>
                        <button type="button" @click="selectStyle('elegant_serif')" x-show="outputFormat === 'vector' && logoMode === 'text_only'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'elegant_serif' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <span class="text-xl font-serif tracking-tight" :class="logoStyle === 'elegant_serif' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-700 dark:text-zinc-300'">Aa</span>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'elegant_serif' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Elegant Serif</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Refined strokes</div>
                        </button>
                        <button type="button" @click="selectStyle('script_signature')" x-show="outputFormat === 'vector' && logoMode === 'text_only'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'script_signature' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <span class="text-xl italic tracking-tight" :class="logoStyle === 'script_signature' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-700 dark:text-zinc-300'">Aa</span>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'script_signature' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Script Signature</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Flowing curves</div>
                        </button>
                        <button type="button" @click="selectStyle('tech_mono')" x-show="outputFormat === 'vector' && logoMode === 'text_only'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'tech_mono' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <span class="text-lg font-mono tracking-tight" :class="logoStyle === 'tech_mono' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-700 dark:text-zinc-300'">Aa</span>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'tech_mono' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Tech Mono</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Precision spacing</div>
                        </button>
                        <button type="button" @click="selectStyle('minimal_light')" x-show="outputFormat === 'vector' && logoMode === 'text_only'"
                            class="group rounded-xl border p-3 transition-all text-center"
                            :class="logoStyle === 'minimal_light' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <span class="text-lg font-light tracking-tight" :class="logoStyle === 'minimal_light' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-700 dark:text-zinc-300'">Aa</span>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'minimal_light' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400'">Minimal Light</div>
                            <div class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-0.5">Thin clean letterforms</div>
                        </button>
                    </div>

                    <div x-show="styleModalTab === 'theme'" class="space-y-3">
                        <button type="button" @click="selectTheme('')"
                            class="w-full rounded-xl border p-4 text-left transition-all"
                            :class="logoTheme === '' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="text-sm font-semibold" :class="logoTheme === '' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-800'">No Theme</div>
                            <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Use only the selected style and description.</div>
                        </button>
                        <button type="button" @click="selectTheme('real_estate')"
                            class="group w-full rounded-xl border p-3 text-left transition-all"
                            :class="logoTheme === 'real_estate' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="flex gap-3">
                                <div class="h-20 w-28 flex-shrink-0 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center overflow-hidden">
                                    <svg class="w-24 h-14" viewBox="0 0 112 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <path d="M10 39C31 28 62 57 102 34" stroke="#1d4ed8" stroke-width="8" stroke-linecap="round"/>
                                        <path d="M14 45C39 37 55 67 94 55" stroke="#38bdf8" stroke-width="4" stroke-linecap="round"/>
                                        <path d="M30 35L51 13L72 35H61L51 24L41 35H30Z" fill="#1e3a8a"/>
                                        <path d="M72 32L88 17L104 32H95L88 25L81 32H72Z" fill="#f97316"/>
                                        <path d="M54 12H61V25H54V12Z" fill="#2563eb"/>
                                    </svg>
                                </div>
                                <div class="min-w-0 py-1">
                                    <div class="text-sm font-semibold" :class="logoTheme === 'real_estate' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-800'">Real Estate</div>
                                    <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Architectural cues, rooflines, buildings, and clean property-brand geometry.</div>
                                </div>
                            </div>
                        </button>
                        <button type="button" @click="selectTheme('nature')"
                            class="group w-full rounded-xl border p-3 text-left transition-all"
                            :class="logoTheme === 'nature' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="flex gap-3">
                                <div class="h-20 w-28 flex-shrink-0 rounded-lg bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                    <img src="/images/ray_vector_samples/ray_evergreen_silhouette_vector.svg" alt="Nature sample" class="style-sample-image h-full w-full object-cover" loading="lazy" />
                                </div>
                                <div class="min-w-0 py-1">
                                    <div class="text-sm font-semibold" :class="logoTheme === 'nature' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-800'">Nature</div>
                                    <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Outdoor cues, trees, leaves, landforms, and clean organic silhouettes.</div>
                                </div>
                            </div>
                        </button>
                        <button type="button" @click="selectTheme('fantasy')"
                            class="group w-full rounded-xl border p-3 text-left transition-all"
                            :class="logoTheme === 'fantasy' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="flex gap-3">
                                <div class="h-20 w-28 flex-shrink-0 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center overflow-hidden">
                                    <svg class="h-full w-full" viewBox="0 0 112 80" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <rect width="112" height="80" rx="8" fill="#1e1b4b"/>
                                        <path d="M14 62L31 36L42 50L54 25L75 62H14Z" fill="#312e81"/>
                                        <path d="M57 62L73 32L96 62H57Z" fill="#4338ca"/>
                                        <path d="M58 14L63 25L75 26L66 34L69 46L58 39L47 46L50 34L41 26L53 25L58 14Z" fill="#facc15"/>
                                        <path d="M26 62C34 49 42 43 50 44C61 46 65 58 78 62H26Z" fill="#7c3aed"/>
                                        <path d="M25 63H88" stroke="#c4b5fd" stroke-width="4" stroke-linecap="round"/>
                                        <path d="M35 53L41 48L47 53M69 53L75 48L81 53" stroke="#fef3c7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="min-w-0 py-1">
                                    <div class="text-sm font-semibold" :class="logoTheme === 'fantasy' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-800'">Fantasy</div>
                                    <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Magic, quests, creatures, castles, weapons, and dramatic adventure silhouettes.</div>
                                </div>
                            </div>
                        </button>
                        <button type="button" @click="selectTheme('technology')"
                            class="group w-full rounded-xl border p-3 text-left transition-all"
                            :class="logoTheme === 'technology' ? 'border-blue-500 ring-1 ring-blue-600/20 bg-blue-50 dark:bg-blue-500/10' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700'">
                            <div class="flex gap-3">
                                <div class="h-20 w-28 flex-shrink-0 rounded-lg bg-slate-950 flex items-center justify-center overflow-hidden">
                                    <svg class="h-full w-full" viewBox="0 0 112 80" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <rect width="112" height="80" rx="8" fill="#0f172a"/>
                                        <path d="M18 23H31L39 31M18 57H31L39 49M94 23H81L73 31M94 57H81L73 49M56 12V22M56 58V68" stroke="#38bdf8" stroke-width="3" stroke-linecap="round"/>
                                        <circle cx="17" cy="23" r="4" fill="#22d3ee"/>
                                        <circle cx="17" cy="57" r="4" fill="#8b5cf6"/>
                                        <circle cx="95" cy="23" r="4" fill="#8b5cf6"/>
                                        <circle cx="95" cy="57" r="4" fill="#22d3ee"/>
                                        <circle cx="56" cy="11" r="3" fill="#a78bfa"/>
                                        <circle cx="56" cy="69" r="3" fill="#22d3ee"/>
                                        <rect x="37" y="21" width="38" height="38" rx="9" fill="#2563eb"/>
                                        <path d="M48 45L42 40L48 35M64 35L70 40L64 45M59 31L53 49" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="min-w-0 py-1">
                                    <div class="text-sm font-semibold" :class="logoTheme === 'technology' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-800'">Technology</div>
                                    <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Software, AI, hardware, networks, cybersecurity, and clean digital geometry.</div>
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>

