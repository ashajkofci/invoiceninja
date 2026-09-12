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

namespace App\Repositories;

use App\Models\Product;
use App\Utils\Traits\SavesDocuments;

class ProductRepository extends BaseRepository
{
    use SavesDocuments;

    /**
     * @param array $data
     * @param Product $product
     * @return Product|null
     */
    public function save(array $data, Product $product): ?Product
    {
        $product->fill($data);
        $product->save();

        if (array_key_exists('group_items', $data)) {
            $allowed_product_ids = Product::query()
                ->where('company_id', $product->company_id)
                ->where('id', '!=', $product->id)
                ->where('is_group', false)
                ->whereIn('id', collect($data['group_items'] ?? [])->pluck('product_id'))
                ->pluck('id')
                ->all();

            $items = collect($data['group_items'] ?? [])
                ->whereIn('product_id', $allowed_product_ids)
                ->mapWithKeys(function ($item, $sort_id) {
                return [(int) $item['product_id'] => [
                    'quantity' => (float) ($item['quantity'] ?? 1),
                    'sort_id' => $sort_id,
                ]];
            })->all();

            $product->group_products()->sync($product->is_group ? $items : []);
        } elseif (!$product->is_group) {
            $product->group_products()->detach();
        }

        if (array_key_exists('documents', $data)) {
            $this->saveDocuments($data['documents'], $product);
        }

        return $product;
    }
}
