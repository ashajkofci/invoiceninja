<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2024. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\DataMapper;

class InvoiceItem
{
    public $quantity = 0;

    /** Multiplies quantity and unit cost for time-based rental billing. */
    public $time_coefficient = 1;

    /** The user-facing name of the selected time coefficient. */
    public $time_coefficient_name = '';

    public $cost = 0;

    public $product_key = '';

    public $product_cost = 0;

    public $notes = '';

    public $discount = 0;

    public $is_amount_discount = false;

    public $tax_name1 = '';

    public $tax_rate1 = 0;

    public $tax_name2 = '';

    public $tax_rate2 = 0;

    public $tax_name3 = '';

    public $tax_rate3 = 0;

    public $sort_id = '0';

    public $line_total = 0;

    public $gross_line_total = 0;

    public $tax_amount = 0;

    public $date = '';

    public $custom_value1 = '';

    public $custom_value2 = '';

    public $custom_value3 = '';

    public $custom_value4 = '';

    public $type_id = '1'; //1 = product, 2 = service, 3 unpaid gateway fee, 4 paid gateway fee, 5 late fee, 6 expense

    public $tax_id = '';

    public $task_id = '';

    public $expense_id = '';

    public $unit_code = 'C62';

    /** A stable client generated identifier shared by a group header and its children. */
    public $group_id = '';

    public $group_title = '';

    public $group_hide_item_prices = false;

    /** Whether group_price replaces the calculated sum of the child items. */
    public $group_has_price = false;

    public $group_price = 0;

    public static $casts = [
        'task_id' => 'string',
        'expense_id' => 'string',
        'tax_id' => 'string',
        'type_id' => 'string',
        'quantity' => 'float',
        'time_coefficient' => 'float',
        'time_coefficient_name' => 'string',
        'cost' => 'float',
        'product_cost' => 'float',
        'product_key' => 'string',
        'notes' => 'string',
        'discount' => 'float',
        'is_amount_discount' => 'bool',
        'tax_name1' => 'string',
        'tax_name2' => 'string',
        'tax_name3' => 'string',
        'tax_rate1' => 'float',
        'tax_rate2' => 'float',
        'tax_rate3' => 'float',
        'sort_id' => 'string',
        'line_total' => 'float',
        'gross_line_total' => 'float',
        'tax_amount' => 'float',
        'date' => 'string',
        'custom_value1' => 'string',
        'custom_value2' => 'string',
        'custom_value3' => 'string',
        'custom_value4' => 'string',
        'unit_code' => 'string',
        'group_id' => 'string',
        'group_title' => 'string',
        'group_hide_item_prices' => 'bool',
        'group_has_price' => 'bool',
        'group_price' => 'float',
    ];
}
