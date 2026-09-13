<?php

namespace Tests\Unit;

use App\Factory\InvoiceItemFactory;
use App\Helpers\Invoice\InvoiceItemGroup;
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

        [$items] = InvoiceItemGroup::prepare([$header, $child], false);

        $this->assertSame(80.0, $items[0]->cost);
        $this->assertSame(60.0, $items[1]->line_total);
    }
}
