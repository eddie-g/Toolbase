<?php

use App\Models\GuidedTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        GuidedTemplate::updateOrCreate(
            ['slug' => 'lease_extension'],
            [
                'name'        => 'Lease Extension',
                'type'        => 'realestate',
                'description' => 'Residential lease extension agreement with parties, dates, rent, and signatures',
                'sort_order'  => 0,
                'is_active'   => true,
                'defaults'    => [
                    'glex-agreement-date' => '[DATE]',
                    'glex-landlord-name' => '[YOUR COMPANY NAME]',
                    'glex-landlord-state' => '[STATE/PROVINCE]',
                    'glex-landlord-address' => '[YOUR COMPLETE ADDRESS]',
                    'glex-tenant1-name' => '[TENANT NAME]',
                    'glex-tenant2-name' => '',
                    'glex-tenant-state' => '[STATE/PROVINCE]',
                    'glex-tenant-address' => '[COMPLETE ADDRESS]',
                    'glex-premises-description' => '[DESCRIBE]',
                    'glex-property-address' => '[ADDRESS]',
                    'glex-original-lease-date' => '[DATE]',
                    'glex-extension-period' => '[TIME PERIOD]',
                    'glex-extension-start-date' => '[DATE]',
                    'glex-extension-end-date' => '[DATE]',
                    'glex-new-rent' => '[AMOUNT]',
                    'glex-governing-law' => 'state in which the property is located',
                ],
                'preview_html' => '<svg viewBox="0 0 300 210" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="300" height="210" fill="#fff"/><rect x="28" y="18" width="244" height="174" fill="#fff" stroke="#d1d5db"/><text x="150" y="38" font-size="9" fill="#111827" font-weight="bold" font-family="serif" text-anchor="middle">EXTENSION OF LEASE AGREEMENT</text><rect x="48" y="52" width="204" height="1" fill="#111827"/><text x="48" y="68" font-size="5.5" fill="#374151" font-family="serif">This Agreement is entered into on __________ by and between:</text><text x="48" y="84" font-size="5.5" fill="#111827" font-weight="bold" font-family="serif">LANDLORD:</text><rect x="92" y="80" width="118" height="5" rx="1" fill="#e5e7eb"/><text x="48" y="99" font-size="5.5" fill="#111827" font-weight="bold" font-family="serif">TENANT(S):</text><rect x="92" y="95" width="128" height="5" rx="1" fill="#e5e7eb"/><text x="48" y="114" font-size="5.5" fill="#111827" font-weight="bold" font-family="serif">PROPERTY:</text><rect x="92" y="110" width="142" height="5" rx="1" fill="#e5e7eb"/><rect x="48" y="126" width="204" height="1" fill="#111827"/><text x="48" y="141" font-size="5.5" fill="#374151" font-family="serif">The Original Lease is extended from ________ to ________.</text><text x="48" y="156" font-size="5.5" fill="#374151" font-family="serif">Monthly rent shall be $________ per month.</text><rect x="48" y="176" width="76" height="1" fill="#111827"/><rect x="176" y="176" width="76" height="1" fill="#111827"/><text x="48" y="185" font-size="5" fill="#6b7280" font-family="serif">Landlord Signature</text><text x="176" y="185" font-size="5" fill="#6b7280" font-family="serif">Tenant Signature</text></svg>',
            ]
        );
    }

    public function down(): void
    {
        GuidedTemplate::where('slug', 'lease_extension')->delete();
    }
};
