<?php

return [
    // Adobe bills Export PDF in Document Transactions, rounded up per 50 pages.
    'pages_per_transaction' => (int) env('DOCUMENT_CONVERSION_PAGES_PER_TRANSACTION', 50),
    'price_usd_per_transaction' => (float) env('DOCUMENT_CONVERSION_PRICE_USD', 0.10),
];
