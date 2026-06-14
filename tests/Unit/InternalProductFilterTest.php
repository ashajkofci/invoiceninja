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

use App\Utils\InternalProductFilter;
use Tests\TestCase;

class InternalProductFilterTest extends TestCase
{
    public function testInterneSwitchOnlyFiltersTruthySwitchValues()
    {
        $custom_fields = (object) [
            'product2' => 'Interne|switch',
        ];

        $line_items = [
            (object) ['product_key' => 'yes', 'custom_value2' => 'yes'],
            (object) ['product_key' => 'one', 'custom_value2' => '1'],
            (object) ['product_key' => 'true', 'custom_value2' => 'true'],
            (object) ['product_key' => 'on', 'custom_value2' => 'on'],
            (object) ['product_key' => 'no', 'custom_value2' => 'no'],
            (object) ['product_key' => 'zero', 'custom_value2' => '0'],
            (object) ['product_key' => 'two', 'custom_value2' => '2'],
            (object) ['product_key' => 'decimal', 'custom_value2' => '0.5'],
            (object) ['product_key' => 'false', 'custom_value2' => 'false'],
            (object) ['product_key' => 'off', 'custom_value2' => 'off'],
            (object) ['product_key' => 'empty', 'custom_value2' => ''],
            (object) ['product_key' => 'missing'],
        ];

        $filtered = InternalProductFilter::filter($custom_fields, $line_items);

        $this->assertSame(['no', 'zero', 'two', 'decimal', 'false', 'off', 'empty', 'missing'], array_map(function ($item) {
            return $item->product_key;
        }, $filtered));
    }

    public function testInterneFieldMustBeSwitch()
    {
        $line_items = [
            (object) ['product_key' => 'text-field', 'custom_value2' => 'yes'],
        ];

        $filtered = InternalProductFilter::filter((object) ['product2' => 'Interne|single_line_text'], $line_items);

        $this->assertSame($line_items, $filtered);
    }
}
