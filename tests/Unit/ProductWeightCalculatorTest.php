<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Unit;

use App\Utils\ProductWeightCalculator;
use Tests\TestCase;

class ProductWeightCalculatorTest extends TestCase
{
    public function testCalculatesPoidsTotalForObjectCustomFieldsAndMixedLineItems()
    {
        $custom_fields = (object) [
            'product2' => 'Poids|single_line_text',
        ];

        $line_items = [
            (object) ['quantity' => 3, 'custom_value2' => '2.5'],
            ['quantity' => 2, 'custom_value2' => '1,25'],
            ['quantity' => 2, 'custom_value2' => 'not numeric'],
        ];

        $result = ProductWeightCalculator::calculate($custom_fields, $line_items);

        $this->assertSame('Poids', $result['label']);
        $this->assertTrue($result['has_total']);
        $this->assertEquals(10.0, $result['total']);
    }

    public function testCalculatesPoidsTotalForArrayCustomFieldsAndMixedLineItems()
    {
        $custom_fields = [
            'product1' => 'POIDS|single_line_text',
        ];

        $line_items = [
            ['quantity' => 4, 'custom_value1' => '1.5'],
            (object) ['quantity' => 1, 'custom_value1' => '0,5'],
        ];

        $result = ProductWeightCalculator::calculate($custom_fields, $line_items);

        $this->assertSame('POIDS', $result['label']);
        $this->assertTrue($result['has_total']);
        $this->assertEquals(6.5, $result['total']);
    }

    public function testReturnsEmptyResultWhenPoidsFieldIsMissing()
    {
        $result = ProductWeightCalculator::calculate(['product2' => 'Weight|single_line_text'], [
            ['quantity' => 3, 'custom_value2' => '2.5'],
        ]);

        $this->assertSame('', $result['label']);
        $this->assertFalse($result['has_total']);
        $this->assertSame(0.0, $result['total']);
    }
}