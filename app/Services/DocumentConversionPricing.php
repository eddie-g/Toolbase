<?php

namespace App\Services;

use InvalidArgumentException;

class DocumentConversionPricing
{
    public function quote(int $pageCount): array
    {
        if ($pageCount < 1) {
            throw new InvalidArgumentException('A conversion must contain at least one page.');
        }

        $pagesPerTransaction = max(1, (int) config('document-conversion.pages_per_transaction', 50));
        $pricePerTransaction = max(0, (float) config('document-conversion.price_usd_per_transaction', 0.10));
        $transactions = (int) ceil($pageCount / $pagesPerTransaction);

        return [
            'page_count' => $pageCount,
            'pages_per_transaction' => $pagesPerTransaction,
            'transactions' => $transactions,
            'price_per_transaction' => $pricePerTransaction,
            'charge_usd' => round($transactions * $pricePerTransaction, 4),
        ];
    }
}
