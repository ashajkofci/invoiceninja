<?php

namespace App\Helpers\Invoice;

/**
 * Shared rules for grouped invoice and quote line items.
 *
 * A type 7 line is the billable group header. Normal product lines with the
 * same group_id are descriptive children and must not be billed a second time.
 */
class InvoiceItemGroup
{
    public const TYPE_GROUP = '7';

    public static function headers(iterable $items): array
    {
        $headers = [];

        foreach ($items as $item) {
            if (self::isHeader($item) && !empty($item->group_id)) {
                $headers[(string) $item->group_id] = $item;
            }
        }

        return $headers;
    }

    public static function isHeader(object $item): bool
    {
        return (string) ($item->type_id ?? '') === self::TYPE_GROUP;
    }

    public static function isChild(object $item, array $headers): bool
    {
        $group_id = (string) ($item->group_id ?? '');

        return $group_id !== '' && !self::isHeader($item) && isset($headers[$group_id]);
    }

    public static function prepare(iterable $items, bool $amount_discount): array
    {
        $items = is_array($items) ? $items : iterator_to_array($items);
        $headers = self::headers($items);
        $totals = array_fill_keys(array_keys($headers), 0.0);

        foreach ($items as $item) {
            if (!self::isChild($item, $headers)) {
                continue;
            }

            $line_total = (float) ($item->cost ?? 0)
                * (float) ($item->quantity ?? 0)
                * (float) ($item->time_coefficient ?? 1);
            $discount = (float) ($item->discount ?? 0);
            $line_total -= $amount_discount ? $discount : ($line_total * $discount / 100);
            $totals[(string) $item->group_id] += $line_total;

            // Children show useful values in editors/PDFs but carry no tax;
            // the group header is the single accounting/tax line.
            $item->line_total = round($line_total + 0.000000000000004, 2);
            $item->gross_line_total = $item->line_total;
            $item->tax_amount = 0;
        }

        foreach ($headers as $group_id => $header) {
            $header->quantity = 1;
            $header->discount = 0;
            if (!empty($header->group_has_price)) {
                $header->group_hide_item_prices = true;
            }
            $header->cost = !empty($header->group_has_price)
                ? (float) ($header->group_price ?? 0)
                : round($totals[$group_id] ?? 0, 4);
            $header->product_key = (string) (
                ($header->group_title ?? '') ?: ($header->product_key ?? '')
            );
        }

        return [$items, $headers];
    }
}
