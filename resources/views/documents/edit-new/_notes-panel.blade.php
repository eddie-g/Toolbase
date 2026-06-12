<aside class="enpv-notes-panel" id="enpv-notes-panel" aria-hidden="true" hidden>
    <div class="enpv-notes-panel__header">
        <div>
            <h2>Notes</h2>
            <p id="enpv-notes-count">No notes yet</p>
        </div>
        <button type="button" class="enpv-notes-panel__close" id="enpv-notes-close" aria-label="Close notes">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18"></path>
                <path d="m6 6 12 12"></path>
            </svg>
        </button>
    </div>
    <form class="enpv-notes-compose" id="enpv-notes-form">
        <label class="enpv-notes-compose__label" for="enpv-notes-body">Add note</label>
        <textarea id="enpv-notes-body" rows="4" maxlength="20000" placeholder="Write a note for this PDF"></textarea>
        <label class="enpv-notes-page-toggle">
            <input type="checkbox" id="enpv-notes-current-page" checked>
            <span>Attach to current page</span>
        </label>
        <div class="enpv-notes-anchor-row">
            <span class="enpv-notes-anchor-status" id="enpv-notes-anchor-status">Drag a saved note onto the PDF to pin it</span>
        </div>
        <div class="enpv-notes-compose__actions">
            <button type="submit" class="enpv-notes-save" id="enpv-notes-save">Save Note</button>
        </div>
    </form>
    <div class="enpv-notes-status" id="enpv-notes-status" role="status" aria-live="polite"></div>
    <div class="enpv-notes-list" id="enpv-notes-list"></div>
</aside>
