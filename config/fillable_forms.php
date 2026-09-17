<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fillable forms
    |--------------------------------------------------------------------------
    |
    | Official forms and in-house templates that carry their own AcroForm
    | fields. Each is offered from the PDF editor page and has a detail page at
    | /forms/{slug}; "Fill out now" copies the PDF into a new document for the
    | visitor and opens it in the editor.
    |
    | Official PDFs live in resources/forms as downloaded; the templates are
    | built by python/pdf-editor/generate_fillable_forms.py. Previews are
    | rendered into public/images/forms.
    |
    */

    'forms' => [
        'irs-form-w9' => [
            'title' => 'Form W-9',
            'subtitle' => 'Request for Taxpayer Identification Number and Certification',
            'year' => 2024,
            'issuer' => 'Internal Revenue Service',
            'category' => 'Tax forms',
            'popular' => true,
            'file' => 'fw9.pdf',
            'document_name' => 'Form W-9 (2024).pdf',
            'pages' => 6,
            'fields' => 23,
            'previews' => [
                'images/forms/irs-form-w9-page-1.webp',
            ],
            'summary' => 'Form W-9 is what a business asks a contractor, freelancer or vendor to complete so it can report payments to the IRS. It collects your name, business name, federal tax classification, address and taxpayer identification number.',
            'instructions' => 'Type your name and business name, tick your tax classification, enter your address and your SSN or EIN, then sign and date the certification. Only the first page is the form; the rest are the IRS instructions.',
            'source_url' => 'https://www.irs.gov/forms-pubs/about-form-w-9',
        ],

        'irs-form-w2' => [
            'title' => 'Form W-2',
            'subtitle' => 'Wage and Tax Statement',
            'year' => 2026,
            'issuer' => 'Internal Revenue Service',
            'category' => 'Tax forms',
            'popular' => true,
            'file' => 'fw2.pdf',
            'document_name' => 'Form W-2 (2026).pdf',
            'pages' => 11,
            'fields' => 568,
            'previews' => [
                'images/forms/irs-form-w2-page-2.webp',
                'images/forms/irs-form-w2-page-3.webp',
            ],
            'summary' => 'Form W-2 reports an employee\'s annual wages and the taxes withheld from them. Employers complete one for every employee and send copies to the employee and to the Social Security Administration.',
            'instructions' => 'Fill in the employer and employee details, wages, withholdings and state information on the fillable copies (Copies B, C, D, 1 and 2). Copy A in this PDF is for information only; the SSA requires its own scannable version of Copy A.',
            'source_url' => 'https://www.irs.gov/forms-pubs/about-form-w-2',
        ],

        'irs-form-1099-nec' => [
            'title' => 'Form 1099-NEC',
            'subtitle' => 'Nonemployee Compensation',
            'year' => 2026,
            'issuer' => 'Internal Revenue Service',
            'category' => 'Tax forms',
            'popular' => true,
            'file' => 'f1099nec.pdf',
            'document_name' => 'Form 1099-NEC (2026).pdf',
            'pages' => 6,
            'fields' => 140,
            'previews' => [
                'images/forms/irs-form-1099-nec-page-2.webp',
                'images/forms/irs-form-1099-nec-page-3.webp',
            ],
            'summary' => 'Form 1099-NEC reports payments of $600 or more made to a nonemployee, such as an independent contractor, during the year. The payer completes it and sends copies to the recipient and to the IRS.',
            'instructions' => 'Enter the payer and recipient details, the nonemployee compensation in box 1 and any tax withheld on the fillable copies. Copy A in this PDF is for information only; the IRS requires its own scannable version of Copy A.',
            'source_url' => 'https://www.irs.gov/forms-pubs/about-form-1099-nec',
        ],

        'irs-form-1040' => [
            'title' => 'Form 1040',
            'subtitle' => 'U.S. Individual Income Tax Return',
            'year' => 2025,
            'issuer' => 'Internal Revenue Service',
            'category' => 'Tax forms',
            'popular' => false,
            'file' => 'f1040.pdf',
            'document_name' => 'Form 1040 (2025).pdf',
            'pages' => 2,
            'fields' => 199,
            'previews' => [
                'images/forms/irs-form-1040-page-1.webp',
                'images/forms/irs-form-1040-page-2.webp',
            ],
            'summary' => 'Form 1040 is the annual income tax return for individuals in the United States. It reports your income, claims your deductions and credits, and works out the tax you owe or the refund you are due.',
            'instructions' => 'Open the form in the editor and type straight into its boxes: names, Social Security numbers, income lines, deductions, credits and the signature block. Check boxes are clickable. When you are done, download the PDF with your entries written into the form\'s own fields.',
            'source_url' => 'https://www.irs.gov/forms-pubs/about-form-1040',
        ],

        'lease-agreement' => [
            'title' => 'Lease Agreement',
            'subtitle' => 'Residential lease between a landlord and tenants',
            'year' => 2026,
            'issuer' => 'Netkit template',
            'category' => 'Legal',
            'popular' => true,
            'file' => 'lease-agreement.pdf',
            'document_name' => 'Lease Agreement.pdf',
            'pages' => 3,
            'fields' => 50,
            'previews' => [
                'images/forms/lease-agreement-page-1.webp',
                'images/forms/lease-agreement-page-2.webp',
            ],
            'summary' => 'A fixed-term residential lease that sets out who is renting what, for how long, for how much, and on what terms: rent and deposit, utilities, occupants and pets, maintenance and entry, default and additional terms.',
            'instructions' => 'Fill in the landlord and tenant details, the premises, the term and the rent, tick the options that apply, add any extra terms, then sign and date. The template is general; have it checked against the law where the property is.',
            'source_url' => null,
        ],

        'invoice' => [
            'title' => 'Invoice',
            'subtitle' => 'Fillable invoice for goods or services',
            'year' => 2026,
            'issuer' => 'Netkit template',
            'category' => 'Business',
            'popular' => true,
            'file' => 'invoice-fillable.pdf',
            'document_name' => 'Invoice.pdf',
            'pages' => 2,
            'fields' => 58,
            'previews' => [
                'images/forms/invoice-page-1.webp',
                'images/forms/invoice-page-2.webp',
            ],
            'summary' => 'A clean invoice with your details, the customer\'s details, eight line items, totals and payment terms, each as a fillable field.',
            'instructions' => 'Enter the invoice number and dates, your business and the customer, then the line items with quantities and rates. Fill in the totals and payment terms and download the finished invoice.',
            'source_url' => null,
        ],

        'bill-of-sale' => [
            'title' => 'Bill of Sale',
            'subtitle' => 'Transfer of ownership of personal property',
            'year' => 2026,
            'issuer' => 'Netkit template',
            'category' => 'Business',
            'popular' => true,
            'file' => 'bill-of-sale.pdf',
            'document_name' => 'Bill of Sale.pdf',
            'pages' => 2,
            'fields' => 34,
            'previews' => [
                'images/forms/bill-of-sale-page-1.webp',
                'images/forms/bill-of-sale-page-2.webp',
            ],
            'summary' => 'A general bill of sale for a vehicle, equipment or other personal property: seller and buyer, a description with make, model and serial number, the price and how it is paid, the condition of the item, and signatures.',
            'instructions' => 'Complete both parties, describe the item, enter the price and tick how it is paid and whether it is sold as is, then sign and date with a witness if you have one.',
            'source_url' => null,
        ],

        'nda' => [
            'title' => 'NDA',
            'subtitle' => 'Mutual non-disclosure agreement',
            'year' => 2026,
            'issuer' => 'Netkit template',
            'category' => 'Business',
            'popular' => false,
            'file' => 'nda.pdf',
            'document_name' => 'NDA.pdf',
            'pages' => 2,
            'fields' => 18,
            'previews' => [
                'images/forms/nda-page-1.webp',
                'images/forms/nda-page-2.webp',
            ],
            'summary' => 'A mutual non-disclosure agreement in which both parties agree to protect the confidential information they share for a stated purpose, with the usual exceptions, a term, and remedies.',
            'instructions' => 'Enter both parties, the effective date and the purpose, set the term and the governing law, then have each party sign.',
            'source_url' => null,
        ],

        'power-of-attorney' => [
            'title' => 'Power of Attorney',
            'subtitle' => 'General power of attorney',
            'year' => 2026,
            'issuer' => 'Netkit template',
            'category' => 'Legal',
            'popular' => false,
            'file' => 'power-of-attorney.pdf',
            'document_name' => 'Power of Attorney.pdf',
            'pages' => 2,
            'fields' => 43,
            'previews' => [
                'images/forms/power-of-attorney-page-1.webp',
                'images/forms/power-of-attorney-page-2.webp',
            ],
            'summary' => 'A general power of attorney appointing an agent to act for you in the matters you choose, with an optional alternate agent, limits, an effective date, durability and a notary block.',
            'instructions' => 'Enter the principal and agent details, tick the powers granted, add any limits, choose when it takes effect and whether it is durable, then sign before witnesses and a notary as your jurisdiction requires.',
            'source_url' => null,
        ],

        'liability-waiver' => [
            'title' => 'Liability Waiver',
            'subtitle' => 'Release and waiver of liability',
            'year' => 2026,
            'issuer' => 'Netkit template',
            'category' => 'Legal',
            'popular' => false,
            'file' => 'liability-waiver.pdf',
            'document_name' => 'Liability Waiver.pdf',
            'pages' => 2,
            'fields' => 23,
            'previews' => [
                'images/forms/liability-waiver-page-1.webp',
                'images/forms/liability-waiver-page-2.webp',
            ],
            'summary' => 'A release and waiver of liability for participation in an activity or event: the participant assumes the risks, releases the host, consents to emergency treatment and agrees to the rules.',
            'instructions' => 'Fill in the activity and the participant details, tick the acknowledgements, then sign; a parent or guardian signs for anyone under 18.',
            'source_url' => null,
        ],

        'employment-contract' => [
            'title' => 'Employment Contract',
            'subtitle' => 'Employment agreement',
            'year' => 2026,
            'issuer' => 'Netkit template',
            'category' => 'Business',
            'popular' => false,
            'file' => 'employment-contract.pdf',
            'document_name' => 'Employment Contract.pdf',
            'pages' => 2,
            'fields' => 41,
            'previews' => [
                'images/forms/employment-contract-page-1.webp',
                'images/forms/employment-contract-page-2.webp',
            ],
            'summary' => 'An employment agreement covering the position, start date and hours, employment type, pay and benefits, confidentiality, notice periods and governing law.',
            'instructions' => 'Enter the employer and employee, the role, dates and hours, tick the employment type and benefits, set the pay and notice periods, then both parties sign.',
            'source_url' => null,
        ],

    ],

];
