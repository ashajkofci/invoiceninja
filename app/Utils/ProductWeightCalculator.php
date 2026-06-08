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

namespace App\Utils;

class ProductWeightCalculator
{
    public static function calculate($custom_fields, iterable $line_items): array
    {
        $field = self::findPoidsField($custom_fields);

        if (!$field) {
            return [
                'label' => '',
                'total' => 0.0,
                'has_total' => false,
            ];
        }

        $total = 0.0;
        $has_total = false;

        foreach ($line_items as $item) {
            $weight = self::parseFloat(self::getValue($item, $field['value_field']));

            if (is_null($weight)) {
                continue;
            }

            $quantity = (float) (self::getValue($item, 'quantity') ?? 0);
            $total += $weight * $quantity;
            $has_total = true;
        }

        return [
            'label' => $field['label'],
            'total' => $total,
            'has_total' => $has_total,
        ];
    }

    private static function findPoidsField($custom_fields): ?array
    {
        if (!is_object($custom_fields) && !is_array($custom_fields)) {
            return null;
        }

        for ($i = 1; $i <= 4; $i++) {
            $field = 'product' . $i;

            $field_config = self::getValue($custom_fields, $field);

            if (is_null($field_config)) {
                continue;
            }

            $label = trim(explode('|', (string) $field_config)[0] ?? '');

            if (strcasecmp($label, 'poids') !== 0) {
                continue;
            }

            return [
                'label' => $label,
                'value_field' => 'custom_value' . $i,
            ];
        }

        return null;
    }

    private static function getValue($data, string $key)
    {
        if (is_array($data)) {
            return $data[$key] ?? null;
        }

        if (is_object($data) && property_exists($data, $key)) {
            return $data->{$key};
        }

        return null;
    }

    private static function parseFloat($value): ?float
    {
        if (is_null($value)) {
            return null;
        }

        $value = trim(strip_tags((string) $value));

        if ($value === '') {
            return null;
        }

        $value = str_replace(["\xc2\xa0", ' '], '', $value);
        $value = preg_replace('/[^\d,.\-+]/', '', $value);

        if (!$value || !preg_match('/\d/', $value)) {
            return null;
        }

        $last_dot = strrpos($value, '.');
        $last_comma = strrpos($value, ',');
        $decimal_position = max($last_dot === false ? -1 : $last_dot, $last_comma === false ? -1 : $last_comma);

        if ($decimal_position >= 0) {
            $integer = preg_replace('/[,.]/', '', substr($value, 0, $decimal_position));
            $decimal = preg_replace('/[,.]/', '', substr($value, $decimal_position + 1));
            $value = $integer . ($decimal === '' ? '' : '.' . $decimal);
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
