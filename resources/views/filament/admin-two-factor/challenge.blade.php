<x-filament-panels::page.simple>
    <x-filament-panels::form id="form" wire:submit="verify">
        {{ $this->form }}

        <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="true" />
    </x-filament-panels::form>

    <form method="POST" action="{{ $this->signOutUrl() }}" class="text-center text-sm">
        @csrf
        <button type="submit" class="text-gray-500 hover:underline">Sign out</button>
    </form>
</x-filament-panels::page.simple>
