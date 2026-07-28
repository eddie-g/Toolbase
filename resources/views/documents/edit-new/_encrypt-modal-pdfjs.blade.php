<div id="enpv-encrypt-modal" class="enpv-convert-modal enpv-encrypt-modal" hidden>
    <section class="enpv-encrypt-card" role="dialog" aria-modal="true" aria-labelledby="enpv-encrypt-title" aria-describedby="enpv-encrypt-description">
        <header class="enpv-encrypt-header">
            <h2 id="enpv-encrypt-title">Password</h2>
            <button type="button" id="enpv-encrypt-close" class="enpv-encrypt-close" aria-label="Close password dialog">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M18 6 6 18M6 6l12 12"></path>
                </svg>
            </button>
        </header>

        <div id="enpv-encrypt-tabs" class="enpv-encrypt-tabs" role="tablist" aria-label="Password action">
            <button type="button"
                    id="enpv-encrypt-set-tab"
                    class="enpv-encrypt-tab is-active"
                    role="tab"
                    aria-selected="true"
                    aria-controls="enpv-encrypt-set-panel"
                    data-enpv-password-tab="set">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="8" cy="15" r="3"></circle>
                    <path d="m10.2 12.8 7.1-7.1a2.4 2.4 0 1 1 3.4 3.4l-1.1 1.1-1.8-1.8-1.8 1.8 1.8 1.8-2 2-1.8-1.8-1.8 1.8"></path>
                </svg>
                <span>Set</span>
            </button>
            <button type="button"
                    id="enpv-encrypt-remove-tab"
                    class="enpv-encrypt-tab"
                    role="tab"
                    aria-selected="false"
                    aria-controls="enpv-encrypt-remove-panel"
                    tabindex="-1"
                    data-enpv-password-tab="remove">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="4" y="10" width="16" height="11" rx="2"></rect>
                    <path d="M8 10V7a4 4 0 0 1 7.6-1.8"></path>
                </svg>
                <span>Remove</span>
            </button>
        </div>

        <div class="enpv-encrypt-notices" id="enpv-encrypt-description">
            <p>
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 20h9"></path>
                    <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5Z"></path>
                </svg>
                <span>Keep the password safely. There is no recovery method.</span>
            </p>
            <p>
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"></path>
                    <path d="m9 12 2 2 4-4"></path>
                </svg>
                <span>Strong AES-128 encryption.</span>
            </p>
        </div>

        <main class="enpv-encrypt-body">
            <section id="enpv-encrypt-set-panel" role="tabpanel" aria-labelledby="enpv-encrypt-set-tab">
                <label id="enpv-encrypt-current-wrap" class="enpv-password-field" hidden>
                    <span class="enpv-password-label">Current password</span>
                    <input id="enpv-encrypt-current-password"
                           type="password"
                           maxlength="255"
                           autocomplete="current-password"
                           placeholder="Enter password">
                </label>
                <label class="enpv-password-field">
                    <span class="enpv-password-label">New password</span>
                    <input id="enpv-encrypt-password"
                           type="password"
                           maxlength="255"
                           autocomplete="new-password"
                           placeholder="Enter new password">
                </label>
                <label id="enpv-encrypt-confirm-wrap" class="enpv-password-field">
                    <span class="enpv-password-label">Confirm password</span>
                    <input id="enpv-encrypt-confirm"
                           type="password"
                           maxlength="255"
                           autocomplete="new-password"
                           placeholder="Confirm new password">
                </label>
            </section>

            <section id="enpv-encrypt-remove-panel" role="tabpanel" aria-labelledby="enpv-encrypt-remove-tab" hidden>
                <label class="enpv-password-field">
                    <span class="enpv-password-label">Current password</span>
                    <input id="enpv-encrypt-remove-password"
                           type="password"
                           maxlength="255"
                           autocomplete="current-password"
                           placeholder="Enter password">
                </label>
                <p id="enpv-encrypt-remove-help" class="enpv-encrypt-remove-help">
                    Remove password protection from this PDF.
                </p>
            </section>

            <section id="enpv-encrypt-unlock-panel" role="tabpanel" hidden>
                <label class="enpv-password-field">
                    <span class="enpv-password-label">PDF password</span>
                    <input id="enpv-encrypt-unlock-password"
                           type="password"
                           maxlength="255"
                           autocomplete="current-password"
                           placeholder="Enter password">
                </label>
                <p class="enpv-encrypt-remove-help">
                    Enter the password to open this protected PDF.
                </p>
            </section>

            <p id="enpv-encrypt-error" class="enpv-encrypt-message is-error" role="alert" hidden></p>
            <p id="enpv-encrypt-status" class="enpv-encrypt-message" aria-live="polite"></p>
        </main>

        <footer class="enpv-encrypt-footer">
            <button type="button" id="enpv-encrypt-accept" class="enpv-encrypt-submit" disabled>
                <span id="enpv-encrypt-accept-label">Set password</span>
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M5 12h14"></path>
                    <path d="m13 6 6 6-6 6"></path>
                </svg>
            </button>
        </footer>
    </section>
</div>
