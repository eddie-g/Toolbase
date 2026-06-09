<?php

namespace Database\Seeders;

use App\Models\GuidedTemplate;
use Illuminate\Database\Seeder;

class GuidedTemplateSeeder extends Seeder
{
    public function run(): void
    {
        GuidedTemplate::updateOrCreate(
            ['slug' => 'security_deposit_return'],
            [
                'name'        => 'Security Deposit Return',
                'description' => 'Itemized deposit refund statement with deductions table',
                'type'        => 'realestate',
                'sort_order'  => 2,
                'is_active'   => true,
                'defaults'    => [
                    'landlord_name'    => 'Landlord Name',
                    'landlord_address' => "1234 Landlord Ave.\nCity, ST 12345",
                    'tenant_name'      => 'Tenant Name',
                    'tenant_address'   => "456 Tenant St., Apt 2",
                    'city_prov_postal' => 'City, Province  A1B 2C3',
                    'tenancy_began'    => '',
                    'keys_turned_in'   => '',
                    'total_deposits'   => '0.00',
                ],
                'preview_html' => '<svg viewBox="0 0 300 210" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="300" height="210" fill="#f8f9fa"/><rect y="0" width="300" height="40" fill="#1e3a5f"/><text x="150" y="16" font-size="8" fill="white" font-weight="bold" font-family="sans-serif" text-anchor="middle">SECURITY DEPOSIT</text><text x="150" y="27" font-size="7" fill="rgba(255,255,255,0.8)" font-family="sans-serif" text-anchor="middle">REFUND FORM</text><rect x="24" y="50" width="80" height="5" rx="1" fill="#aaa"/><rect x="24" y="60" width="60" height="4" rx="1" fill="#ccc"/><rect x="24" y="80" width="252" height="12" rx="2" fill="#1e3a5f"/><text x="30" y="89" font-size="5" fill="white" font-family="sans-serif">TYPE</text><text x="80" y="89" font-size="5" fill="white" font-family="sans-serif">DESCRIPTION</text><text x="240" y="89" font-size="5" fill="white" font-family="sans-serif">COST</text><rect x="24" y="94" width="252" height="8" fill="#f0f4f8"/><rect x="30" y="97" width="30" height="3" rx="1" fill="#888"/><rect x="80" y="97" width="80" height="3" rx="1" fill="#888"/><rect x="238" y="97" width="30" height="3" rx="1" fill="#888"/><rect x="24" y="104" width="252" height="8" fill="#fff"/><rect x="30" y="107" width="25" height="3" rx="1" fill="#bbb"/><rect x="80" y="107" width="65" height="3" rx="1" fill="#bbb"/><rect x="238" y="107" width="30" height="3" rx="1" fill="#bbb"/><rect x="160" y="128" width="116" height="1.5" fill="#1e3a5f"/><text x="164" y="140" font-size="6" fill="#1e3a5f" font-family="sans-serif">Total Deductions</text><text x="248" y="140" font-size="7" fill="#333" font-weight="bold" font-family="sans-serif">$0.00</text><text x="24" y="160" font-size="6" fill="#555" font-family="sans-serif">Amount Enclosed: $____________</text></svg>',
            ]
        );


        GuidedTemplate::updateOrCreate(
            ['slug' => 'default'],
            [
                'name'        => 'Clean Modern',
                'description' => 'Dark header, clean layout — guided form',
                'type'        => 'invoice',
                'sort_order'  => 1,
                'is_active'   => true,
                'defaults'    => [
                    'company_name'     => 'Your Company Inc.',
                    'company_address'  => "1234 Company St.\nCompany Town ST 12345",
                    'customer_name'    => 'Customer Name',
                    'customer_address' => "1234 Customer St.\nCustomer Town ST 12345",
                    'invoice_number'   => '0001001',
                    'terms'            => '',
                ],
                'preview_html' => '<svg viewBox="0 0 300 210" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="300" height="210" fill="#f8f9fa"/><rect y="0" width="300" height="3" fill="#1f2937"/><rect x="24" y="20" width="120" height="10" rx="2" fill="#1f2937"/><rect x="24" y="34" width="90" height="5" rx="1" fill="#aaa"/><text x="210" y="29" font-size="12" fill="#1f2937" font-weight="bold" font-family="sans-serif">INVOICE</text><rect x="24" y="58" width="40" height="5" rx="1" fill="#1f2937"/><rect x="24" y="68" width="80" height="6" rx="1" fill="#444"/><rect x="24" y="78" width="70" height="4" rx="1" fill="#bbb"/><rect x="24" y="100" width="252" height="14" rx="2" fill="#1f2937"/><text x="30" y="110" font-size="6" fill="white" font-family="sans-serif">QTY</text><text x="70" y="110" font-size="6" fill="white" font-family="sans-serif">Description</text><text x="200" y="110" font-size="6" fill="white" font-family="sans-serif">Price</text><text x="248" y="110" font-size="6" fill="white" font-family="sans-serif">Amount</text><rect x="24" y="118" width="252" height="0.5" fill="#e8e8e8"/><rect x="30" y="123" width="20" height="5" rx="1" fill="#777"/><rect x="70" y="123" width="100" height="5" rx="1" fill="#555"/><rect x="248" y="123" width="28" height="5" rx="1" fill="#555"/><rect x="24" y="134" width="252" height="0.5" fill="#e8e8e8"/><rect x="200" y="162" width="76" height="1.5" fill="#1f2937"/><text x="204" y="176" font-size="7" fill="#1f2937" font-family="sans-serif">Total</text><text x="248" y="176" font-size="8" fill="#333" font-weight="bold" font-family="sans-serif">$0.00</text></svg>',
            ]
        );

        GuidedTemplate::updateOrCreate(
            ['slug' => 'bold_red'],
            [
                'name'        => 'Bold Red',
                'description' => 'Corporate style with red header — guided form',
                'type'        => 'invoice',
                'sort_order'  => 2,
                'is_active'   => true,
                'defaults'    => [
                    'company_name'     => 'Your Company Name',
                    'company_address'  => "123 Business Avenue, New York, NY 10001\n(555) 123-4567 | billing@yourcompany.com",
                    'customer_name'    => 'Client Name',
                    'customer_address' => "456 Client Street\nLos Angeles, CA 90001",
                    'invoice_number'   => 'INV-2026-001',
                    'terms'            => '',
                ],
                'preview_html' => '<svg viewBox="0 0 300 210" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="300" height="210" fill="#f8f9fa"/><rect y="0" width="300" height="55" fill="#cc3333"/><rect x="24" y="16" width="110" height="10" rx="2" fill="white"/><rect x="24" y="30" width="160" height="5" rx="1" fill="rgba(255,255,255,0.6)"/><text x="210" y="34" font-size="12" fill="white" font-weight="bold" font-family="sans-serif">INVOICE</text><rect x="24" y="70" width="32" height="5" rx="1" fill="#cc3333"/><rect x="24" y="80" width="80" height="6" rx="1" fill="#444"/><rect x="24" y="90" width="70" height="4" rx="1" fill="#bbb"/><rect x="24" y="110" width="252" height="14" rx="2" fill="#cc3333"/><text x="30" y="120" font-size="6" fill="white" font-family="sans-serif">Description</text><text x="150" y="120" font-size="6" fill="white" font-family="sans-serif">Qty</text><text x="200" y="120" font-size="6" fill="white" font-family="sans-serif">Unit Price</text><text x="248" y="120" font-size="6" fill="white" font-family="sans-serif">Amount</text><rect x="24" y="126" width="252" height="14" fill="#fdf0f0"/><rect x="30" y="130" width="100" height="5" rx="1" fill="#555"/><rect x="248" y="130" width="28" height="5" rx="1" fill="#555"/><rect x="30" y="146" width="80" height="5" rx="1" fill="#555"/><rect x="248" y="146" width="28" height="5" rx="1" fill="#555"/><rect x="200" y="178" width="76" height="18" rx="3" fill="#cc3333"/><text x="206" y="190" font-size="7" fill="white" font-family="sans-serif">TOTAL DUE</text><text x="252" y="190" font-size="7" fill="white" font-weight="bold" font-family="sans-serif">$0</text></svg>',
            ]
        );
    }
}
