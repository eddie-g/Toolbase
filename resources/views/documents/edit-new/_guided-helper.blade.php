@if(($guided ?? false) && (($document->template_slug ?? null) === 'lease_extension'))
    <div class="enpv-guided-helper-rail" id="enpv-guided-helper-rail">
        <button type="button"
                class="enpv-guided-helper-open"
                id="enpv-guided-helper-open"
                title="Open helper"
                aria-expanded="false"
                aria-controls="enpv-guided-helper-panel">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M9.1 9a3 3 0 1 1 4.8 2.4c-.9.6-1.4 1.2-1.4 2.1"></path>
                <path d="M12 17h.01"></path>
            </svg>
            <span>Helper</span>
        </button>
    </div>

    <aside class="enpv-guided-helper-panel" id="enpv-guided-helper-panel" aria-hidden="true" hidden>
        <div class="enpv-guided-helper-header">
            <div>
                <h2>Helper</h2>
                <p>Extension of Lease Agreement</p>
            </div>
            <button type="button" class="enpv-guided-helper-close" id="enpv-guided-helper-close" aria-label="Close helper">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 6 6 18"></path>
                    <path d="m6 6 12 12"></path>
                </svg>
            </button>
        </div>

        <div class="enpv-guided-helper-body">
            <section class="enpv-helper-section">
                <h3>Header</h3>
                <div class="enpv-helper-item">
                    <h4>[DATE]</h4>
                    <p>Enter the date the extension is signed by all parties.</p>
                    <p class="enpv-helper-example">Example: May 15, 2026</p>
                </div>
            </section>

            <section class="enpv-helper-section">
                <h3>Landlord Section</h3>
                <div class="enpv-helper-item">
                    <h4>[YOUR COMPANY NAME]</h4>
                    <p>Enter the landlord's legal name.</p>
                    <p class="enpv-helper-example">Example: ABC Property Management LLC</p>
                </div>
                <div class="enpv-helper-item">
                    <h4>[STATE/PROVINCE]</h4>
                    <p>State where the landlord entity is organized.</p>
                    <p class="enpv-helper-example">Example: Texas</p>
                </div>
                <div class="enpv-helper-item">
                    <h4>[YOUR COMPLETE ADDRESS]</h4>
                    <p>Landlord's mailing or business address.</p>
                    <pre>ABC Property Management LLC
123 Main Street
Dallas, TX 75001</pre>
                </div>
            </section>

            <section class="enpv-helper-section">
                <h3>Tenant Section</h3>
                <div class="enpv-helper-item">
                    <h4>[TENANT NAME]</h4>
                    <p>Your full legal name exactly as it appears on the original lease.</p>
                    <p class="enpv-helper-example">Example: Eddie Gray</p>
                </div>
                <div class="enpv-helper-item">
                    <h4>[STATE/PROVINCE]</h4>
                    <p>State where the tenant entity is organized. If the tenant is an individual, use the state where they live or leave it blank if it does not apply.</p>
                    <p class="enpv-helper-example">Example: Texas</p>
                </div>
                <div class="enpv-helper-item">
                    <h4>[COMPLETE ADDRESS]</h4>
                    <p>Tenant's mailing address. For an individual tenant, this is usually the rental property's address.</p>
                    <pre>123 Oak Drive
Apartment 4B
Dallas, TX 75001</pre>
                </div>
            </section>

            <section class="enpv-helper-section">
                <h3>Recitals</h3>
                <div class="enpv-helper-item">
                    <h4>premises known as [DESCRIBE]</h4>
                    <p>Brief description of the property.</p>
                    <p class="enpv-helper-example">Example: Residential apartment unit #4B</p>
                </div>
                <div class="enpv-helper-item">
                    <h4>located at [ADDRESS]</h4>
                    <p>Full rental property address.</p>
                    <p class="enpv-helper-example">Example: 123 Oak Drive, Apartment 4B, Dallas, TX 75001</p>
                </div>
                <div class="enpv-helper-item">
                    <h4>dated [DATE]</h4>
                    <p>Original lease execution date, not the move-in date unless they are the same.</p>
                    <p class="enpv-helper-example">Example: June 15, 2025</p>
                </div>
            </section>

            <section class="enpv-helper-section">
                <h3>Terms</h3>
                <div class="enpv-helper-item">
                    <h4>for a period of [TIME PERIOD]</h4>
                    <p>Enter the length of the lease extension.</p>
                    <p class="enpv-helper-example">Example: 12 months</p>
                </div>
                <div class="enpv-helper-item">
                    <h4>commencing on [DATE]</h4>
                    <p>Use the first day of the new extended term.</p>
                    <p class="enpv-helper-example">Example: July 1, 2026</p>
                    <div class="enpv-helper-callout">If the original lease ends June 30, 2026, use July 1, 2026 as the commencement date to avoid overlap.</div>
                </div>
                <div class="enpv-helper-item">
                    <h4>terminating on [DATE]</h4>
                    <p>Use the final day of the extended term.</p>
                    <p class="enpv-helper-example">Example: June 30, 2027</p>
                </div>
                <div class="enpv-helper-item">
                    <h4>rent of [AMOUNT]</h4>
                    <p>Enter the rent due during the extended term.</p>
                    <p class="enpv-helper-example">Example: $2,500.00 per month</p>
                    <p class="enpv-helper-clause">During the extended term, Tenant shall pay Landlord rent of $2,500.00 per month, payable in advance.</p>
                </div>
            </section>

            <section class="enpv-helper-section">
                <h3>Signatures</h3>
                <div class="enpv-helper-item">
                    <h4>LANDLORD - Authorized Signature</h4>
                    <p>Landlord or property manager signs on this line.</p>
                </div>
                <div class="enpv-helper-item">
                    <h4>LANDLORD - Print Name and Title</h4>
                    <p>Print the landlord signer name and role.</p>
                    <p class="enpv-helper-example">Example: John Smith, Property Manager</p>
                </div>
                <div class="enpv-helper-item">
                    <h4>TENANT - Authorized Signature</h4>
                    <p>Tenant signs on this line.</p>
                </div>
                <div class="enpv-helper-item">
                    <h4>TENANT - Print Name and Title</h4>
                    <p>Print the tenant name and title. If the tenant is an individual, use Tenant as the title.</p>
                    <p class="enpv-helper-example">Example: Eddie Gray, Tenant</p>
                </div>
            </section>

            <section class="enpv-helper-section">
                <h3>Quick Reference</h3>
                <table class="enpv-helper-table">
                    <tbody>
                        <tr><th>Original Lease Ends</th><td>June 30, 2026</td></tr>
                        <tr><th>Extension Start</th><td>July 1, 2026</td></tr>
                        <tr><th>Extension End</th><td>June 30, 2027</td></tr>
                        <tr><th>New Monthly Rent</th><td>$2,500.00</td></tr>
                    </tbody>
                </table>
            </section>
        </div>
    </aside>
@endif
