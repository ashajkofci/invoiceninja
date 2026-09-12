<?php

namespace Tests\Unit;

use App\DataMapper\InvoiceItem;
use App\Factory\InvoiceItemFactory;
use App\Helpers\Invoice\InvoiceItemSum;
use App\Helpers\Invoice\InvoiceItemSumInclusive;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;

class TimeCoefficientTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();
    }

    public function testDefaultsToOneForNewAndLegacyItems(): void
    {
        $this->assertSame(1, (new InvoiceItem())->time_coefficient);
        $this->assertSame(1, InvoiceItemFactory::create()->time_coefficient);

        $item = InvoiceItemFactory::create();
        unset($item->time_coefficient);
        $item->quantity = 2;
        $item->cost = 15;
        $this->invoice->line_items = [$item];

        $calculator = (new InvoiceItemSum($this->invoice))->process();

        $this->assertSame(30.0, (float) $calculator->getLineItems()[0]->line_total);
    }

    public function testExclusiveTaxAppliesAfterCoefficient(): void
    {
        $item = InvoiceItemFactory::create();
        $item->quantity = 2;
        $item->cost = 10;
        $item->time_coefficient = 3;
        $item->tax_name1 = 'VAT';
        $item->tax_rate1 = 10;
        $this->invoice->line_items = [$item];

        $calculator = (new InvoiceItemSum($this->invoice))->process();

        $this->assertSame(60.0, (float) $calculator->getLineTotal());
        $this->assertSame(6.0, (float) $calculator->getTotalTaxes());
        $this->assertSame(66.0, (float) $calculator->getGrossLineTotal());
    }

    public function testInclusiveTaxAppliesAfterCoefficient(): void
    {
        $item = InvoiceItemFactory::create();
        $item->quantity = 2;
        $item->cost = 11;
        $item->time_coefficient = 3;
        $item->tax_name1 = 'VAT';
        $item->tax_rate1 = 10;
        $this->invoice->line_items = [$item];

        $calculator = (new InvoiceItemSumInclusive($this->invoice))->process();

        $this->assertSame(66.0, (float) $calculator->getLineTotal());
        $this->assertSame(6.0, (float) $calculator->getTotalTaxes());
    }

    public function testDiscountAppliesAfterCoefficient(): void
    {
        $item = InvoiceItemFactory::create();
        $item->quantity = 2;
        $item->cost = 10;
        $item->time_coefficient = 3;
        $item->discount = 25;
        $this->invoice->is_amount_discount = false;
        $this->invoice->line_items = [$item];

        $calculator = (new InvoiceItemSum($this->invoice))->process();

        $this->assertSame(45.0, (float) $calculator->getLineTotal());
    }

    public function testAutomaticGroupUsesChildCoefficientsAndGroupCoefficient(): void
    {
        $header = InvoiceItemFactory::create();
        $header->type_id = '7';
        $header->group_id = 'rental';
        $header->time_coefficient = 2;

        $child = InvoiceItemFactory::create();
        $child->group_id = 'rental';
        $child->quantity = 2;
        $child->cost = 10;
        $child->time_coefficient = 3;

        $this->invoice->line_items = [$header, $child];
        $calculator = (new InvoiceItemSum($this->invoice))->process();

        $this->assertSame(120.0, (float) $calculator->getLineItems()[0]->line_total);
        $this->assertSame(60.0, (float) $calculator->getLineItems()[1]->line_total);
    }

    public function testFixedPriceGroupUsesGroupCoefficient(): void
    {
        $header = InvoiceItemFactory::create();
        $header->type_id = '7';
        $header->group_id = 'rental';
        $header->group_has_price = true;
        $header->group_price = 80;
        $header->time_coefficient = 2.5;

        $this->invoice->line_items = [$header];
        $calculator = (new InvoiceItemSum($this->invoice))->process();

        $this->assertSame(200.0, (float) $calculator->getLineTotal());
    }
}
