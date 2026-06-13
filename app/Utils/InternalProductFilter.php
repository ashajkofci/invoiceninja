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

class InternalProductFilter
{
    public static function filter($custom_fields, iterable $line_items): array
    {
        $field = self::findInterneField($custom_fields);

        if (!$field) {
            if (is_array($line_items)) {
                return $line_items;
            }

            return iterator_to_array($line_items);
        }

        $filtered = [];

        foreach ($line_items as $item) {
            if (!self::isInterne($item, $field)) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    public static function isInterne($item, array $field): bool
    {
        $value = self::getValue($item, $field['value_field']);

        if (is_null($value) || $value === '') {
            return false;
        }

        $value = trim(strip_tags((string) $value));

        if ($value === '' || $value === '0' || strcasecmp($value, 'false') === 0) {
            return false;
        }

        return true;
    }

    private static function findInterneField($custom_fields): ?array
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

            if (strcasecmp($label, 'interne') !== 0) {
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
}
