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

namespace App\Http\Requests\Product;

use App\Http\Requests\Request;
use App\Models\Product;

class StoreProductRequest extends Request
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return $user->can('create', Product::class);
    }

    public function rules()
    {
        if ($this->file('documents') && is_array($this->file('documents'))) {
            $rules['documents.*'] = $this->fileValidation();
        } elseif ($this->file('documents')) {
            $rules['documents'] = $this->fileValidation();
        } else {
            $rules['documents'] = 'bail|sometimes|array';
        }

        if ($this->file('file') && is_array($this->file('file'))) {
            $rules['file.*'] = $this->fileValidation();
        } elseif ($this->file('file')) {
            $rules['file'] = $this->fileValidation();
        }

        $rules['cost'] = 'sometimes|numeric';
        $rules['price'] = 'sometimes|numeric';
        $rules['quantity'] = 'sometimes|numeric';
        $rules['in_stock_quantity'] = 'sometimes|numeric';
        $rules['stock_notification_threshold'] = 'sometimes|numeric';
        $rules['stock_notification'] = 'sometimes|bool';
        $rules['is_group'] = 'sometimes|bool';
        $rules['group_hide_item_prices'] = 'sometimes|bool';
        $rules['group_has_price'] = 'sometimes|bool';
        $rules['group_price'] = 'sometimes|numeric';
        $rules['group_items'] = 'sometimes|array';
        $rules['group_items.*.product_id'] = 'required';
        $rules['group_items.*.quantity'] = 'required|numeric|min:0.0001';

        $rules['tax_rate1'] = 'bail|sometimes|numeric';
        $rules['tax_rate2'] = 'bail|sometimes|numeric';
        $rules['tax_rate3'] = 'bail|sometimes|numeric';


        return $rules;
    }

    public function prepareForValidation()
    {
        $input = $this->all();

        if (! isset($input['quantity'])) {
            $input['quantity'] = 1;
        }

        if (array_key_exists('assigned_user_id', $input) && is_string($input['assigned_user_id'])) {
            $input['assigned_user_id'] = $this->decodePrimaryKey($input['assigned_user_id']);
        }

        $input['tax_name1'] =  $input['tax_name1'] ?? '';
        $input['tax_name2'] =  $input['tax_name2'] ?? '';
        $input['tax_name3'] =  $input['tax_name3'] ?? '';

        foreach ($input['group_items'] ?? [] as &$item) {
            if (isset($item['product_id']) && is_string($item['product_id'])) {
                $item['product_id'] = $this->decodePrimaryKey($item['product_id']);
            }
        }

        $this->replace($input);
    }
}
