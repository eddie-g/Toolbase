<?php

namespace Tests\Unit;

use App\Services\DocumentConversionPricing;
use Tests\TestCase;

class DocumentConversionPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('document-conversion.pages_per_transaction', 50);
        config()->set('document-conversion.price_usd_per_transaction', 0.10);
    }

    public function test_it_charges_ten_cents_for_up_to_fifty_pages(): void
    {
        $pricing = app(DocumentConversionPricing::class);

        $this->assertSame(0.10, $pricing->quote(1)['charge_usd']);
        $this->assertSame(0.10, $pricing->quote(50)['charge_usd']);
    }

    public function test_it_rounds_adobe_transactions_up_for_each_fifty_pages(): void
    {
        $quote = app(DocumentConversionPricing::class)->quote(101);

        $this->assertSame(3, $quote['transactions']);
        $this->assertSame(0.30, $quote['charge_usd']);
    }
}
