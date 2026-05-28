<div id="enpv-encrypt-modal" class="enpv-convert-modal" hidden>
    <section class="enpv-convert-card enpv-encrypt-card" role="dialog" aria-modal="true" aria-labelledby="enpv-encrypt-title">
        <header class="enpv-convert-header">
            <div class="enpv-convert-heading">
                <div id="enpv-encrypt-icon" class="enpv-convert-icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                </div>
                <div>
                    <h2 id="enpv-encrypt-title">Add a password</h2>
                    <p>Password protect this PDF before downloading it.</p>
                </div>
            </div>
            <button type="button" id="enpv-encrypt-close" class="enpv-convert-close" aria-label="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </header>

        <main class="enpv-convert-body">
            <section class="enpv-convert-panel">
                <div class="enpv-field-group">
                    <label>Document Opening Settings</label>
                    <div class="enpv-convert-two-col">
                        <label class="enpv-password-field">Password
                            <span><input id="enpv-encrypt-password" type="password" autocomplete="new-password"><button type="button" data-enpv-toggle-password="enpv-encrypt-password" aria-label="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></button></span>
                        </label>
                        <label class="enpv-password-field">Confirm password
                            <span><input id="enpv-encrypt-confirm" type="password" autocomplete="new-password"><button type="button" data-enpv-toggle-password="enpv-encrypt-confirm" aria-label="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></button></span>
                        </label>
                    </div>
                    <span id="enpv-encrypt-error" class="enpv-help-text is-error" hidden></span>
                </div>

                <div class="enpv-field-group">
                    <label>Encryption Settings</label>
                    <div class="enpv-choice-grid enpv-choice-grid-2">
                        <button type="button" class="enpv-choice is-active" data-enpv-encryption="aes-128"><strong>128-bit AES</strong><span>Compatible</span></button>
                        <button type="button" class="enpv-choice" data-enpv-encryption="aes-256"><strong>256-bit AES</strong><span>Stronger</span></button>
                    </div>
                    <span class="enpv-help-text">The password will be required to open the downloaded PDF.</span>
                </div>
            </section>

            <div id="enpv-encrypt-progress" class="enpv-convert-progress" hidden>
                <div class="enpv-convert-progress-row"><span id="enpv-encrypt-progress-label">Encrypting PDF...</span><span id="enpv-encrypt-progress-pct">0%</span></div>
                <div class="enpv-convert-progress-track"><div id="enpv-encrypt-progress-bar"></div></div>
            </div>
        </main>

        <footer class="enpv-convert-footer">
            <span id="enpv-encrypt-status">Ready to protect your PDF.</span>
            <div class="enpv-convert-actions enpv-encrypt-actions">
                <button type="button" id="enpv-encrypt-cancel" class="enpv-secondary-button">Cancel</button>
                <button type="button" id="enpv-encrypt-accept" class="enpv-primary-button">Encrypt</button>
            </div>
        </footer>
    </section>
</div>
