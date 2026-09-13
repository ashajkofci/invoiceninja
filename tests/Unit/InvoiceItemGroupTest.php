<?php

namespace Tests\Unit;

use App\Factory\InvoiceItemFactory;
use App\Helpers\Invoice\InvoiceItemGroup;
use App\Utils\Helpers;
use Tests\TestCase;

class InvoiceItemGroupTest extends TestCase
{
    public function testFixedPriceOverridesTheChildTotals(): void
    {
        $header = InvoiceItemFactory::create();
        $header->type_id = InvoiceItemGroup::TYPE_GROUP;
        $header->group_id = 'fixed';
        $header->group_has_price = true;
        $header->group_price = 80;

        $child = InvoiceItemFactory::create();
        $child->group_id = 'fixed';
        $child->quantity = 2;
        $child->cost = 30;

        $ungrouped = InvoiceItemFactory::create();
        $ungrouped->line_total = 20;
        $ungrouped->gross_line_total = 20;

        [$items] = InvoiceItemGroup::prepare([$header, $child, $ungrouped], false);
        $items[0]->line_total = 80;
        $items[0]->gross_line_total = 80;

        $this->assertSame(80.0, $items[0]->cost);
        $this->assertTrue($items[0]->group_hide_item_prices);
        $this->assertSame(60.0, $items[1]->line_total);
        $this->assertSame(100.0, Helpers::lineItemsTotal($items));
    }
}
