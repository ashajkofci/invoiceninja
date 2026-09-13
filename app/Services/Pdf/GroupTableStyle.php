<?php

namespace App\Services\Pdf;

/** Shared presentation for both invoice PDF renderers. Never changes table geometry. */
final class GroupTableStyle
{
    public static function row(array $element): array
    {
        $class = $element['properties']['class'] ?? '';

        if ($class === 'group-header') {
            $element['properties']['style'] = 'break-after: avoid; page-break-after: avoid;';
        }

        foreach ($element['elements'] as &$cell) {
            $style = $cell['properties']['style'] ?? '';
            $ref = $cell['properties']['data-ref'] ?? '';

            if ($class === 'group-header') {
                $style .= ' font-weight: 700 !important; color: #1f2937 !important; background-color: #e8edf3 !important; border-top: 2px solid #94a3b8;';
            } elseif ($class === 'group-item') {
                $style .= ' font-weight: 400; color: #475569 !important; background-color: #f8fafc !important;';

                // Indent the label content, not the cell: wrapped text aligns and
                // reordered/hidden columns retain exactly the same boundaries.
                if (preg_match('/\.(product_key|item|service)-td$/', $ref)) {
                    $cell['elements'] = [[
                        'element' => 'div',
                        'properties' => ['style' => 'margin-left: 0.9rem; font-style: italic; border-left: 2px solid #cbd5e1; padding-left: 0.5rem;'],
                        'content' => $cell['content'],
                    ]];
                    unset($cell['content']);
                }
            }

            $cell['properties']['style'] = trim($style);
        }
        unset($cell);

        $element['elements'] = self::columns($element['elements']);

        return $element;
    }

    public static function columns(array $cells): array
    {
        foreach ($cells as &$cell) {
            $ref = $cell['properties']['data-ref'] ?? '';
            if (preg_match('/\.(quantity|hours|time_coefficient|unit_cost|cost|rate|line_total|gross_line_total|tax_amount|discount|tax[123]|tax_rate[123])-(td|th)$/', $ref)) {
                $style = $cell['properties']['style'] ?? '';
                $cell['properties']['style'] = trim($style.' text-align: right !important; padding-right: 0.75rem !important; font-variant-numeric: tabular-nums;');
            }
        }
        unset($cell);

        return $cells;
    }
}