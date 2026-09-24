{{-- The gear menu of one generated image on the portal Images page: reuse
     its prompt in the Logo Studio. "Open in editor" (documents.createFromGeneratedImage)
     is left out until the PDF editor handles images and vectors natively.
     Needs the page's logoGallery() Alpine scope (openInStudio). --}}
@if ($image['preset'])
{{-- Fixed to the viewport next to the gear: the card and the list's scroll
     box both clip overflow, and a menu inside them would be cut off. --}}
<div class="nk-menu-wrap {{ $class ?? '' }}"
    x-data="{
        open: false,
        style: '',
        toggle() {
            if (this.open) { this.open = false; return; }
            this.open = true;
            this.$nextTick(() => {
                const gear = this.$refs.gear.getBoundingClientRect();
                const menu = this.$refs.menu.getBoundingClientRect();
                const below = gear.bottom + 6 + menu.height <= window.innerHeight - 8;
                const top = below ? gear.bottom + 6 : Math.max(8, gear.top - 6 - menu.height);
                const left = Math.min(Math.max(8, gear.right - menu.width), window.innerWidth - menu.width - 8);
                this.style = `top: ${top}px; left: ${left}px;`;
            });
        },
    }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    @scroll.window="open = false"
    @resize.window="open = false"
>
    <button type="button" class="nk-gear" x-ref="gear" @click="toggle()" :aria-expanded="open ? 'true' : 'false'" aria-haspopup="menu" aria-label="Image options" title="Options">
        <x-filament::icon icon="heroicon-m-cog-6-tooth" />
    </button>
    <div class="nk-menu" x-ref="menu" x-show="open" x-cloak :style="style" role="menu">
            <button type="button" class="nk-menu-item" role="menuitem" @click="open = false; openInStudio(@js($image['preset']))">
                <x-filament::icon icon="heroicon-m-sparkles" />
                <span>
                    Use this prompt
                    <span class="nk-menu-hint">Open the Logo Studio with this prompt and its settings</span>
                </span>
            </button>
    </div>
</div>
@endif
