<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Repositories\ProductRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductGroupPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('product_key');
            $table->boolean('is_group')->default(false);
            $table->boolean('group_hide_item_prices')->default(false);
            $table->boolean('group_has_price')->default(false);
            $table->decimal('group_price', 16, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_group_items', function (Blueprint $table) {
            $table->unsignedBigInteger('group_product_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 16, 4);
            $table->unsignedInteger('sort_id');
        });
    }

    public function testGroupMembersAreReturnedAfterSaveWithLoadedRelation(): void
    {
        $groupId = DB::table('products')->insertGetId([
            'company_id' => 1,
            'product_key' => 'group',
        ]);
        $childId = DB::table('products')->insertGetId([
            'company_id' => 1,
            'product_key' => 'child',
        ]);
        $group = Product::query()->findOrFail($groupId);
        $this->assertCount(0, $group->group_products);

        $savedGroup = Product::withoutEvents(fn () => (new ProductRepository())->save([
            'is_group' => true,
            'group_items' => [[
                'product_id' => $childId,
                'quantity' => 2.5,
            ]],
        ], $group));

        $member = $savedGroup->group_products->first();

        $this->assertSame($childId, $member->id);
        $this->assertSame(2.5, (float) $member->pivot->quantity);
    }

    public function testGroupMembersAreSavedWhenTheGroupIsCreated(): void
    {
        $childId = DB::table('products')->insertGetId([
            'company_id' => 1,
            'product_key' => 'child',
        ]);
        $group = (new Product())->forceFill([
            'company_id' => 1,
            'product_key' => 'group',
        ]);

        $savedGroup = Product::withoutEvents(fn () => (new ProductRepository())->save([
            'is_group' => true,
            'group_items' => [[
                'product_id' => $childId,
                'quantity' => 2.5,
            ]],
        ], $group));

        $member = $savedGroup->group_products->first();

        $this->assertTrue($savedGroup->exists);
        $this->assertSame($childId, $member->id);
        $this->assertSame(2.5, (float) $member->pivot->quantity);
    }
}
