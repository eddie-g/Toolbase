<?php

use App\Models\GuidedTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Newsletter Templates ──────────────────────────────────────

        GuidedTemplate::updateOrCreate(
            ['slug' => 'newsletter_classic'],
            [
                'name'        => 'Classic Newsletter',
                'type'        => 'newsletter',
                'description' => 'Clean two-column layout with header banner — great for company updates',
                'sort_order'  => 10,
                'is_active'   => true,
                'defaults'    => [
                    'newsletter_title'   => 'Monthly Newsletter',
                    'edition'            => 'Vol. 1 — Issue 1',
                    'date'               => date('F Y'),
                    'company_name'       => 'Your Company Inc.',
                    'headline'           => 'Big Exciting Headline Goes Here',
                    'intro_text'         => "Welcome to this month's newsletter! We're excited to share the latest news, updates, and insights from our team.",
                    'section1_title'     => 'Featured Story',
                    'section1_body'      => "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation.",
                    'section2_title'     => 'Upcoming Events',
                    'section2_body'      => "• Annual Company Retreat — March 15\n• Product Launch Webinar — March 22\n• Community Meetup — April 5",
                    'footer_text'        => '© 2026 Your Company Inc. All rights reserved.',
                ],
                'preview_html' => '<svg viewBox="0 0 300 210" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="300" height="210" fill="#f8f9fa"/><rect y="0" width="300" height="48" fill="#2563eb"/><text x="24" y="26" font-size="14" fill="white" font-weight="bold" font-family="sans-serif">NEWSLETTER</text><text x="24" y="40" font-size="8" fill="rgba(255,255,255,0.7)" font-family="sans-serif">Vol. 1 — February 2026</text><rect x="200" y="14" width="76" height="22" rx="4" fill="rgba(255,255,255,0.15)"/><text x="212" y="29" font-size="8" fill="white" font-family="sans-serif">Your Co.</text><rect x="24" y="60" width="180" height="8" rx="2" fill="#1e293b"/><rect x="24" y="74" width="252" height="5" rx="1" fill="#94a3b8"/><rect x="24" y="83" width="230" height="5" rx="1" fill="#94a3b8"/><rect x="24" y="92" width="200" height="5" rx="1" fill="#94a3b8"/><rect x="24" y="110" width="116" height="80" rx="4" fill="#eff6ff" stroke="#bfdbfe" stroke-width="0.5"/><text x="32" y="126" font-size="7" fill="#2563eb" font-weight="bold" font-family="sans-serif">Featured Story</text><rect x="32" y="132" width="100" height="4" rx="1" fill="#64748b"/><rect x="32" y="140" width="90" height="4" rx="1" fill="#64748b"/><rect x="32" y="148" width="95" height="4" rx="1" fill="#64748b"/><rect x="32" y="156" width="80" height="4" rx="1" fill="#64748b"/><rect x="160" y="110" width="116" height="80" rx="4" fill="#eff6ff" stroke="#bfdbfe" stroke-width="0.5"/><text x="168" y="126" font-size="7" fill="#2563eb" font-weight="bold" font-family="sans-serif">Upcoming Events</text><rect x="168" y="132" width="100" height="4" rx="1" fill="#64748b"/><rect x="168" y="140" width="90" height="4" rx="1" fill="#64748b"/><rect x="168" y="148" width="95" height="4" rx="1" fill="#64748b"/><rect x="24" y="198" width="252" height="4" rx="1" fill="#cbd5e1"/></svg>',
            ]
        );

        GuidedTemplate::updateOrCreate(
            ['slug' => 'newsletter_modern'],
            [
                'name'        => 'Modern Digest',
                'type'        => 'newsletter',
                'description' => 'Bold gradient header with card-based sections — perfect for tech & startups',
                'sort_order'  => 11,
                'is_active'   => true,
                'defaults'    => [
                    'newsletter_title'   => 'The Weekly Digest',
                    'edition'            => 'Issue #42',
                    'date'               => date('F j, Y'),
                    'company_name'       => 'Startup Labs',
                    'headline'           => 'This Week in Innovation',
                    'intro_text'         => "Here's your weekly roundup of the biggest stories, product updates, and insider tips curated just for you.",
                    'section1_title'     => 'Product Update',
                    'section1_body'      => "We shipped three major features this week: dark mode support, real-time collaboration, and improved export options. Read on for the full breakdown.",
                    'section2_title'     => 'Quick Tips',
                    'section2_body'      => "1. Use keyboard shortcuts to speed up your workflow\n2. Try the new template gallery for instant designs\n3. Enable notifications to stay in the loop",
                    'footer_text'        => 'Startup Labs · hello@startuplabs.io · Unsubscribe',
                ],
                'preview_html' => '<svg viewBox="0 0 300 210" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="300" height="210" fill="#f8f9fa"/><defs><linearGradient id="hdrGrad" x1="0" y1="0" x2="300" y2="0" gradientUnits="userSpaceOnUse"><stop stop-color="#7c3aed"/><stop offset="1" stop-color="#2563eb"/></linearGradient></defs><rect y="0" width="300" height="55" fill="url(#hdrGrad)"/><text x="24" y="28" font-size="16" fill="white" font-weight="bold" font-family="sans-serif">The Weekly Digest</text><text x="24" y="44" font-size="9" fill="rgba(255,255,255,0.75)" font-family="sans-serif">Issue #42 · February 2026</text><rect x="220" y="18" width="56" height="20" rx="10" fill="rgba(255,255,255,0.2)"/><text x="230" y="32" font-size="8" fill="white" font-family="sans-serif">Read →</text><rect x="24" y="68" width="200" height="9" rx="2" fill="#1e293b"/><rect x="24" y="82" width="252" height="5" rx="1" fill="#94a3b8"/><rect x="24" y="91" width="220" height="5" rx="1" fill="#94a3b8"/><rect x="24" y="106" width="252" height="42" rx="6" fill="white" stroke="#e2e8f0" stroke-width="0.75"/><rect x="30" y="112" width="4" height="30" rx="2" fill="#7c3aed"/><text x="42" y="123" font-size="7" fill="#1e293b" font-weight="bold" font-family="sans-serif">Product Update</text><rect x="42" y="128" width="180" height="4" rx="1" fill="#64748b"/><rect x="42" y="136" width="160" height="4" rx="1" fill="#64748b"/><rect x="24" y="156" width="252" height="42" rx="6" fill="white" stroke="#e2e8f0" stroke-width="0.75"/><rect x="30" y="162" width="4" height="30" rx="2" fill="#2563eb"/><text x="42" y="173" font-size="7" fill="#1e293b" font-weight="bold" font-family="sans-serif">Quick Tips</text><rect x="42" y="178" width="180" height="4" rx="1" fill="#64748b"/><rect x="42" y="186" width="160" height="4" rx="1" fill="#64748b"/></svg>',
            ]
        );

        // ── Business Templates ────────────────────────────────────────

        GuidedTemplate::updateOrCreate(
            ['slug' => 'nda_agreement'],
            [
                'name'        => 'NDA Agreement',
                'type'        => 'business',
                'description' => 'Formal Non-Disclosure & Confidentiality Agreement — professional legal format with recitals & signature blocks',
                'sort_order'  => 20,
                'is_active'   => true,
                'defaults'    => [
                    'document_title'     => 'NON-DISCLOSURE AND CONFIDENTIALITY AGREEMENT',
                    'effective_date'     => date('F j, Y'),
                    'party1_name'        => 'Your Company Inc.',
                    'party1_address'     => "1234 Company St.\nCompany Town, ST 12345",
                    'party2_name'        => 'Recipient Name',
                    'party2_address'     => "5678 Recipient Rd.\nRecipient City, ST 67890",
                    'purpose'            => 'exploring a potential business relationship between the parties',
                    'term_years'         => '2',
                    'governing_law'      => 'State of Delaware',
                ],
                'preview_html' => '<svg viewBox="0 0 300 210" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="300" height="210" fill="#fff"/><text x="150" y="24" font-size="9" fill="#111" font-weight="bold" font-family="serif" text-anchor="middle">NON-DISCLOSURE AND</text><text x="150" y="35" font-size="9" fill="#111" font-weight="bold" font-family="serif" text-anchor="middle">CONFIDENTIALITY AGREEMENT</text><text x="150" y="48" font-size="6" fill="#444" font-family="serif" text-anchor="middle">Effective Date: February 7, 2026</text><rect x="30" y="54" width="240" height="0.75" fill="#111"/><rect x="30" y="62" width="240" height="4" rx="1" fill="#555"/><rect x="30" y="69" width="220" height="4" rx="1" fill="#555"/><rect x="30" y="76" width="230" height="4" rx="1" fill="#555"/><text x="30" y="93" font-size="6.5" fill="#111" font-weight="bold" font-family="serif">RECITALS</text><rect x="42" y="98" width="228" height="3.5" rx="1" fill="#666"/><rect x="42" y="104" width="210" height="3.5" rx="1" fill="#666"/><rect x="42" y="110" width="220" height="3.5" rx="1" fill="#666"/><text x="30" y="125" font-size="6.5" fill="#111" font-weight="bold" font-family="serif">1. DEFINITION OF CONFIDENTIAL INFORMATION</text><rect x="30" y="130" width="240" height="3.5" rx="1" fill="#666"/><rect x="30" y="136" width="220" height="3.5" rx="1" fill="#666"/><text x="30" y="151" font-size="6.5" fill="#111" font-weight="bold" font-family="serif">2. OBLIGATIONS OF RECEIVING PARTY</text><rect x="30" y="156" width="240" height="3.5" rx="1" fill="#666"/><rect x="30" y="162" width="210" height="3.5" rx="1" fill="#666"/><rect x="30" y="178" width="100" height="0.75" fill="#111"/><text x="30" y="186" font-size="5" fill="#444" font-family="serif">Disclosing Party Signature</text><rect x="170" y="178" width="100" height="0.75" fill="#111"/><text x="170" y="186" font-size="5" fill="#444" font-family="serif">Receiving Party Signature</text><text x="30" y="196" font-size="5" fill="#444" font-family="serif">Name: _______________</text><text x="170" y="196" font-size="5" fill="#444" font-family="serif">Name: _______________</text></svg>',
            ]
        );

        GuidedTemplate::updateOrCreate(
            ['slug' => 'purchase_order'],
            [
                'name'        => 'Purchase Order',
                'type'        => 'business',
                'description' => 'Standard PO template — track purchases with vendor details & line items',
                'sort_order'  => 21,
                'is_active'   => true,
                'defaults'    => [
                    'document_title'     => 'PURCHASE ORDER',
                    'po_number'          => 'PO-2026-001',
                    'po_date'            => date('m-d-Y'),
                    'delivery_date'      => date('m-d-Y', strtotime('+30 days')),
                    'company_name'       => 'Your Company Inc.',
                    'company_address'    => "1234 Company St.\nCompany Town, ST 12345",
                    'vendor_name'        => 'Vendor Name',
                    'vendor_address'     => "5678 Vendor Rd.\nVendor City, ST 67890",
                    'ship_to'            => "Your Company Inc.\n1234 Company St.\nCompany Town, ST 12345",
                    'terms'              => 'Net 30',
                    'notes'              => '',
                ],
                'preview_html' => '<svg viewBox="0 0 300 210" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="300" height="210" fill="#f8f9fa"/><rect y="0" width="300" height="44" fill="#0f766e"/><text x="24" y="22" font-size="13" fill="white" font-weight="bold" font-family="sans-serif">PURCHASE ORDER</text><text x="24" y="36" font-size="8" fill="rgba(255,255,255,0.7)" font-family="sans-serif">PO-2026-001</text><rect x="200" y="10" width="76" height="24" rx="4" fill="rgba(255,255,255,0.15)"/><text x="212" y="20" font-size="6" fill="rgba(255,255,255,0.8)" font-family="sans-serif">Date</text><text x="212" y="30" font-size="7" fill="white" font-weight="bold" font-family="sans-serif">02-07-2026</text><rect x="24" y="54" width="116" height="36" rx="4" fill="#f0fdfa" stroke="#99f6e4" stroke-width="0.5"/><text x="32" y="66" font-size="6" fill="#0f766e" font-weight="bold" font-family="sans-serif">VENDOR</text><rect x="32" y="72" width="80" height="5" rx="1" fill="#64748b"/><rect x="32" y="80" width="70" height="4" rx="1" fill="#94a3b8"/><rect x="160" y="54" width="116" height="36" rx="4" fill="#f0fdfa" stroke="#99f6e4" stroke-width="0.5"/><text x="168" y="66" font-size="6" fill="#0f766e" font-weight="bold" font-family="sans-serif">SHIP TO</text><rect x="168" y="72" width="80" height="5" rx="1" fill="#64748b"/><rect x="168" y="80" width="70" height="4" rx="1" fill="#94a3b8"/><rect x="24" y="100" width="252" height="14" rx="2" fill="#0f766e"/><text x="30" y="110" font-size="6" fill="white" font-family="sans-serif">Item</text><text x="140" y="110" font-size="6" fill="white" font-family="sans-serif">Qty</text><text x="190" y="110" font-size="6" fill="white" font-family="sans-serif">Unit Price</text><text x="248" y="110" font-size="6" fill="white" font-family="sans-serif">Amount</text><rect x="24" y="118" width="252" height="0.5" fill="#e2e8f0"/><rect x="30" y="123" width="100" height="5" rx="1" fill="#555"/><rect x="248" y="123" width="28" height="5" rx="1" fill="#555"/><rect x="24" y="134" width="252" height="0.5" fill="#e2e8f0"/><rect x="30" y="140" width="90" height="5" rx="1" fill="#555"/><rect x="248" y="140" width="28" height="5" rx="1" fill="#555"/><rect x="200" y="165" width="76" height="18" rx="3" fill="#0f766e"/><text x="206" y="177" font-size="7" fill="white" font-family="sans-serif">TOTAL</text><text x="248" y="177" font-size="8" fill="white" font-weight="bold" font-family="sans-serif">$0.00</text><rect x="24" y="195" width="130" height="4" rx="1" fill="#94a3b8"/></svg>',
            ]
        );
    }

    public function down(): void
    {
        GuidedTemplate::whereIn('slug', [
            'newsletter_classic',
            'newsletter_modern',
            'nda_agreement',
            'purchase_order',
        ])->delete();
    }
};
