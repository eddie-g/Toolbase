<!-- Image Import Modal -->
<div class="image-import-modal" id="image-import-modal" aria-hidden="true" role="dialog" aria-labelledby="image-import-title">
    <div class="image-import-modal__scrim" id="image-import-scrim"></div>
    <div class="image-import-modal__card">
        <button type="button" class="image-import-modal__close" id="image-import-close" aria-label="Close image modal">×</button>
        <div class="image-import-modal__header">
            <span class="image-import-modal__eyebrow">Insert</span>
            <h2 class="image-import-modal__title" id="image-import-title">Import an image</h2>
            <p class="image-import-modal__subtitle">Drop an image, paste from clipboard, or pick a file. JPG, PNG, GIF, WebP, or SVG.</p>
        </div>
        <div class="image-import-tabs" role="tablist" aria-label="Image import source">
            <button type="button" class="image-import-tab is-active" id="image-import-tab-upload" data-image-import-tab="upload" role="tab" aria-selected="true" aria-controls="image-import-upload-panel">Upload</button>
            <button type="button" class="image-import-tab" id="image-import-tab-logos" data-image-import-tab="logos" role="tab" aria-selected="false" aria-controls="image-import-logos-panel">Generated logos</button>
        </div>
        <div class="image-import-modal__body">
            <label class="image-import-dropzone image-import-panel is-active" id="image-import-dropzone" tabindex="0" data-image-import-panel="upload" role="tabpanel" aria-labelledby="image-import-tab-upload">
                <input type="file" id="image-import-file" accept=".jpg,.jpeg,.png,.gif,.webp,.svg,image/png,image/jpeg,image/gif,image/webp,image/svg+xml" hidden>
                <div class="image-import-dropzone__inner" id="image-import-dropzone-inner">
                    <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="3"/>
                        <circle cx="8.5" cy="9" r="1.6"/>
                        <path d="m21 16-5-5-9 9"/>
                    </svg>
                    <div class="image-import-dropzone__title">Drop your image here</div>
                    <div class="image-import-dropzone__subtitle">or <span class="image-import-dropzone__link">browse to upload</span></div>
                    <div class="image-import-dropzone__hint">You can also paste an image (Ctrl/⌘ + V)</div>
                </div>
                <div class="image-import-preview" id="image-import-preview" hidden>
                    <img id="image-import-preview-img" alt="Selected image preview">
                    <div class="image-import-preview__meta">
                        <div class="image-import-preview__name" id="image-import-preview-name"></div>
                        <div class="image-import-preview__dims" id="image-import-preview-dims"></div>
                    </div>
                    <button type="button" class="image-import-preview__remove" id="image-import-clear" aria-label="Remove selected image">×</button>
                </div>
            </label>
            <section class="image-import-account image-import-panel" id="image-import-logos-panel" data-image-import-panel="logos" role="tabpanel" aria-labelledby="image-import-tab-logos">
                <div class="image-import-account__header">
                    <div>
                        <h3 class="image-import-account__title" id="image-import-account-title">Generated logos</h3>
                        <p class="image-import-account__subtitle">Vector and raster images from your account</p>
                    </div>
                    <button type="button" class="image-import-account__refresh" id="image-import-logo-refresh" aria-label="Refresh generated logos">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 12a9 9 0 0 1-15.4 6.4L3 16"></path>
                            <path d="M3 21v-5h5"></path>
                            <path d="M3 12a9 9 0 0 1 15.4-6.4L21 8"></path>
                            <path d="M21 3v5h-5"></path>
                        </svg>
                    </button>
                </div>
                <div class="image-import-account__status" id="image-import-logo-status">Loading logos...</div>
                <div class="image-import-account__grid" id="image-import-logo-grid" aria-live="polite"></div>
            </section>
        </div>
        <div class="image-import-modal__footer">
            <div class="image-import-modal__status" id="image-import-status">Drop or choose an image to insert.</div>
            <div class="image-import-modal__actions">
                <button type="button" class="signature-btn" id="image-import-cancel">Cancel</button>
                <button type="button" class="signature-btn signature-btn--primary" id="image-import-apply" disabled>Insert image</button>
            </div>
        </div>
    </div>
</div>
