<x-filament-panels::page>
    {{-- Styles: resources/css/user-portal.css (nk-* classes). Each section is
         its own Livewire form, so errors and saves stay local to it. --}}
    <div class="nk-page">

        {{-- Profile --}}
        <section class="nk-card nk-settings">
            <div class="nk-settings-intro">
                <h3 class="nk-heading">Profile</h3>
                <p class="nk-muted nk-mt-1">The name shown on your account and on receipts.</p>
                <dl class="nk-facts nk-mt-4">
                    <div><dt>Member since</dt><dd>{{ $memberSince?->format('M j, Y') ?? '—' }}</dd></div>
                    <div><dt>Last sign-in</dt><dd>{{ $lastLogin?->format('M j, Y g:ia') ?? '—' }}</dd></div>
                </dl>
            </div>
            <form class="nk-form" wire:submit="saveProfile">
                <div class="nk-field">
                    <label class="nk-label" for="settings-name">Name</label>
                    <input id="settings-name" type="text" class="nk-input" wire:model="name" autocomplete="name" required>
                    @error('name') <p class="nk-error">{{ $message }}</p> @enderror
                </div>
                <div class="nk-form-actions">
                    <button type="submit" class="nk-btn nk-btn-primary nk-btn-auto" wire:loading.attr="disabled" wire:target="saveProfile">Save name</button>
                </div>
            </form>
        </section>

        {{-- Email --}}
        <section class="nk-card nk-settings">
            <div class="nk-settings-intro">
                <h3 class="nk-heading">Email address</h3>
                <p class="nk-muted nk-mt-1">Where sign-in codes, receipts and verification links go.</p>
                <p class="nk-mt-4">
                    <span class="nk-strong">{{ $email }}</span>
                    @if($emailVerified)
                        <span class="nk-badge nk-badge-lime nk-ml-2"><x-heroicon-s-check-circle /> Verified</span>
                    @else
                        <span class="nk-badge nk-badge-amber nk-ml-2">Unverified</span>
                    @endif
                </p>
                @unless($emailVerified)
                    <p class="nk-small nk-mt-1">Open the verification link we sent to this address to confirm it.</p>
                @endunless
            </div>
            <form class="nk-form" wire:submit="changeEmail">
                <div class="nk-field">
                    <label class="nk-label" for="settings-new-email">New email address</label>
                    <input id="settings-new-email" type="email" class="nk-input" wire:model="newEmail" autocomplete="email" placeholder="you@example.com">
                    @error('newEmail') <p class="nk-error">{{ $message }}</p> @enderror
                </div>
                <div class="nk-field">
                    <label class="nk-label" for="settings-email-password">Current password</label>
                    <input id="settings-email-password" type="password" class="nk-input" wire:model="emailPassword" autocomplete="current-password">
                    @error('emailPassword') <p class="nk-error">{{ $message }}</p> @enderror
                    <p class="nk-help">Needed to change the address. The new address must be verified before it is used.</p>
                </div>
                <div class="nk-form-actions">
                    <button type="submit" class="nk-btn nk-btn-primary nk-btn-auto" wire:loading.attr="disabled" wire:target="changeEmail">Change email</button>
                </div>
            </form>
        </section>

        {{-- Password --}}
        <section class="nk-card nk-settings">
            <div class="nk-settings-intro">
                <h3 class="nk-heading">Password</h3>
                <p class="nk-muted nk-mt-1">At least 12 characters. Other devices stay signed in unless you log them out below.</p>
            </div>
            <form class="nk-form" wire:submit="changePassword">
                <div class="nk-field">
                    <label class="nk-label" for="settings-current-password">Current password</label>
                    <input id="settings-current-password" type="password" class="nk-input" wire:model="currentPassword" autocomplete="current-password">
                    @error('currentPassword') <p class="nk-error">{{ $message }}</p> @enderror
                </div>
                <div class="nk-field">
                    <label class="nk-label" for="settings-new-password">New password</label>
                    <input id="settings-new-password" type="password" class="nk-input" wire:model="newPassword" autocomplete="new-password">
                    @error('newPassword') <p class="nk-error">{{ $message }}</p> @enderror
                </div>
                <div class="nk-field">
                    <label class="nk-label" for="settings-new-password-confirmation">Confirm new password</label>
                    <input id="settings-new-password-confirmation" type="password" class="nk-input" wire:model="newPasswordConfirmation" autocomplete="new-password">
                    @error('newPasswordConfirmation') <p class="nk-error">{{ $message }}</p> @enderror
                </div>
                <div class="nk-form-actions">
                    <button type="submit" class="nk-btn nk-btn-primary nk-btn-auto" wire:loading.attr="disabled" wire:target="changePassword">Change password</button>
                </div>
            </form>
        </section>

        {{-- Timezone --}}
        <section class="nk-card nk-settings">
            <div class="nk-settings-intro">
                <h3 class="nk-heading">Timezone</h3>
                <p class="nk-muted nk-mt-1">Dates and times across the portal are shown in this zone.</p>
                <p class="nk-small nk-mt-4">Right now it is <span class="nk-strong">{{ $now->format('g:ia') }}</span> in {{ str_replace('_', ' ', $now->timezoneName) }}.</p>
            </div>
            <form class="nk-form" wire:submit="saveTimezone">
                <div class="nk-field">
                    <label class="nk-label" for="settings-timezone">Timezone</label>
                    <select id="settings-timezone" class="nk-input nk-select" wire:model="timezone">
                        @foreach($timezoneGroups as $region => $zones)
                            <optgroup label="{{ $region }}">
                                @foreach($zones as $zone)
                                    <option value="{{ $zone }}">{{ str_replace('_', ' ', $zone) }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('timezone') <p class="nk-error">{{ $message }}</p> @enderror
                </div>
                <div class="nk-form-actions">
                    <button type="submit" class="nk-btn nk-btn-primary nk-btn-auto" wire:loading.attr="disabled" wire:target="saveTimezone">Save timezone</button>
                </div>
            </form>
        </section>

        {{-- Sessions --}}
        <section class="nk-card nk-settings">
            <div class="nk-settings-intro">
                <h3 class="nk-heading">Other devices</h3>
                <p class="nk-muted nk-mt-1">Sign out every other browser and device that is logged in to this account. This one stays signed in.</p>
            </div>
            <form class="nk-form" wire:submit="logoutOtherDevices">
                <div class="nk-field">
                    <label class="nk-label" for="settings-logout-password">Current password</label>
                    <input id="settings-logout-password" type="password" class="nk-input" wire:model="logoutPassword" autocomplete="current-password">
                    @error('logoutPassword') <p class="nk-error">{{ $message }}</p> @enderror
                </div>
                <div class="nk-form-actions">
                    <button type="submit" class="nk-btn nk-btn-outline nk-btn-auto" wire:loading.attr="disabled" wire:target="logoutOtherDevices">
                        <x-heroicon-o-device-phone-mobile class="nk-btn-icon" /> Log out other devices
                    </button>
                </div>
            </form>
        </section>

        {{-- Delete --}}
        <section class="nk-card nk-settings nk-card-danger">
            <div class="nk-settings-intro">
                <h3 class="nk-heading nk-heading-danger">Delete account</h3>
                <p class="nk-muted nk-mt-1">Cancels any active plan and permanently removes your account, including remaining credits. This cannot be undone.</p>
            </div>
            <form
                class="nk-form"
                x-data
                @submit.prevent="if (confirm('Delete your account? Any active plan is cancelled and the account, with its remaining credits, is permanently removed.')) $wire.deleteAccount()"
            >
                <div class="nk-field">
                    <label class="nk-label" for="settings-delete-password">Current password</label>
                    <input id="settings-delete-password" type="password" class="nk-input" wire:model="deletePassword" autocomplete="current-password">
                    @error('deletePassword') <p class="nk-error">{{ $message }}</p> @enderror
                </div>
                <div class="nk-form-actions">
                    <button type="submit" class="nk-btn nk-btn-danger-solid nk-btn-auto" wire:loading.attr="disabled" wire:target="deleteAccount">
                        <x-heroicon-o-trash class="nk-btn-icon" /> Delete my account
                    </button>
                </div>
            </form>
        </section>
    </div>
</x-filament-panels::page>
