<?php

/**
 * Invoice Ninja (https://clientninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2022. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Services\Quickbooks\Transformers;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\DataMapper\InvoiceItem;

/**
 * Class InvoiceTransformer.
 */
class InvoiceTransformer extends BaseTransformer
{
    public function qbToNinja(mixed $qb_data)
    {
        return $this->transform($qb_data);
    }

    public function ninjaToQb()
    {
    }

    public function transform($qb_data)
    {
        $client_id = $this->getClientId(data_get($qb_data, 'CustomerRef', null));

        return $client_id ? [
            'id' => data_get($qb_data, 'Id', false),
            'client_id' => $client_id,
            'number' => data_get($qb_data, 'DocNumber', false),
            'date' => data_get($qb_data, 'TxnDate', now()->format('Y-m-d')),
            'private_notes' => data_get($qb_data, 'PrivateNote', ''),
            'public_notes' => data_get($qb_data, 'CustomerMemo', false),
            'due_date' => data_get($qb_data, 'DueDate', null),
            'po_number' => data_get($qb_data, 'PONumber', ""),
            'partial' => (float)data_get($qb_data, 'Deposit', 0),
            'line_items' => $this->getLineItems(data_get($qb_data, 'Line', []), data_get($qb_data, 'ApplyTaxAfterDiscount', 'true')),
            'payment_ids' => $this->getPayments($qb_data),
            'status_id' => Invoice::STATUS_SENT,
            'tax_rate1' => $rate = $this->calculateTotalTax($qb_data),
            'tax_name1' => $rate > 0 ? "Sales Tax" : "",
            'custom_surcharge1' => $this->checkIfDiscountAfterTax($qb_data),

        ] : false;
    }

    private function checkIfDiscountAfterTax($qb_data)
    {

        if (data_get($qb_data, 'ApplyTaxAfterDiscount') == 'true') {
            return 0;
        }

        foreach (data_get($qb_data, 'Line', []) as $line) {

            if (data_get($line, 'DetailType') == 'DiscountLineDetail') {

                if (!isset($this->company->custom_fields->surcharge1)) {
                    $this->company->custom_fields->surcharge1 = ctrans('texts.discount');
                    $this->company->save();
                }

                return (float)data_get($line, 'Amount', 0) * -1;
            }
        }

        return 0;
    }

    private function calculateTotalTax($qb_data)
    {
        $taxLines = data_get($qb_data, 'TxnTaxDetail.TaxLine', []);

        if (!is_array($taxLines)) {
            $taxLines = [$taxLines];
        }

        $totalTaxRate = 0;

        foreach ($taxLines as $taxLine) {
            $taxRate = data_get($taxLine, 'TaxLineDetail.TaxPercent', 0);
            $totalTaxRate += $taxRate;
        }

        return (float)$totalTaxRate;
    }


    private function getPayments(mixed $qb_data)
    {
        $payments = [];

        $qb_payments = data_get($qb_data, 'LinkedTxn', false);

        if (!$qb_payments) {
            return [];
        }

        if (!is_array($qb_payments) && data_get($qb_payments, 'TxnType', false) == 'Payment') {
            return [data_get($qb_payments, 'TxnId', false)];
        }


        foreach ($qb_payments as $payment) {
            if (data_get($payment, 'TxnType', false) == 'Payment') {
                $payments[] = data_get($payment, 'TxnId', false);
            }
        }

        return $payments;

    }

    private function getLineItems(mixed $qb_items, string $include_discount = 'true')
    {
        $items = [];

        foreach ($qb_items as $qb_item) {

            if (data_get($qb_item, 'DetailType') == 'SalesItemLineDetail') {
                $item = new InvoiceItem();
                $item->product_key = data_get($qb_item, 'SalesItemLineDetail.ItemRef.name', '');
                $item->notes = data_get($qb_item, 'Description', '');
                $item->quantity = (float)data_get($qb_item, 'SalesItemLineDetail.Qty', 0);
                $item->cost = (float)data_get($qb_item, 'SalesItemLineDetail.UnitPrice', 0);
                $item->discount = (float)data_get($item, 'DiscountRate', data_get($qb_item, 'DiscountAmount', 0));
                $item->is_amount_discount = data_get($qb_item, 'DiscountAmount', 0) > 0 ? true : false;
                $item->type_id = stripos(data_get($qb_item, 'ItemAccountRef.name') ?? '', 'Service') !== false ? '2' : '1';
                $item->tax_id = data_get($qb_item, 'TaxCodeRef', '') == 'NON' ? Product::PRODUCT_TYPE_EXEMPT : $item->type_id;
                $item->tax_rate1 = (float)data_get($qb_item, 'TxnTaxDetail.TaxLine.TaxLineDetail.TaxPercent', 0);
                $item->tax_name1 = $item->tax_rate1 > 0 ? "Sales Tax" : "";
                $items[] = (object)$item;
            }

            if (data_get($qb_item, 'DetailType') == 'DiscountLineDetail' && $include_discount == 'true') {

                $item = new InvoiceItem();
                $item->product_key = ctrans('texts.discount');
                $item->notes = ctrans('texts.discount');
                $item->quantity = 1;
                $item->cost = (float)data_get($qb_item, 'Amount', 0) * -1;
                $item->discount = 0;
                $item->is_amount_discount = true;
                $item->type_id = '1';
                $item->tax_id = Product::PRODUCT_TYPE_PHYSICAL;
                $items[] = (object)$item;

            }
        }

        return $items;

    }

}
