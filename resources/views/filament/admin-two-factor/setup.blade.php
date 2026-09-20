<x-filament-panels::page.simple>
    @if ($this->recoveryCodes === [])
        <div class="flex flex-col items-center gap-3">
            <div class="rounded-lg bg-white p-3">{!! $this->qrCode() !!}</div>
            <p class="text-center text-sm text-gray-500">
                Cannot scan it? Enter this key instead:<br>
                <code class="font-mono text-gray-950 dark:text-white">{{ $this->manualKey() }}</code>
            </p>
        </div>

        <x-filament-panels::form id="form" wire:submit="confirm">
            {{ $this->form }}

            <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="true" />
        </x-filament-panels::form>
    @else
        <ul class="grid grid-cols-2 gap-2 rounded-lg bg-gray-50 p-4 font-mono text-sm dark:bg-white/5">
            @foreach ($this->recoveryCodes as $code)
                <li>{{ $code }}</li>
            @endforeach
        </ul>

        <x-filament::button wire:click="finish" class="w-full">I have saved them</x-filament::button>
    @endif
</x-filament-panels::page.simple>
