<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fillable forms
    |--------------------------------------------------------------------------
    |
    | Official forms that carry their own AcroForm fields. Each one is listed
    | on /forms, has a detail page at /forms/{slug}, and "Fill out now" copies
    | the PDF into a new document for the visitor and opens it in the editor,
    | where pdf.js renders the fields and the download writes their values.
    |
    | Files live in resources/forms; previews in public/images/forms.
    |
    */

    'forms' => [
        'irs-form-1040' => [
            'title' => 'Form 1040',
            'subtitle' => 'U.S. Individual Income Tax Return',
            'year' => 2025,
            'issuer' => 'Internal Revenue Service',
            'category' => 'Tax forms',
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
    ],

];
