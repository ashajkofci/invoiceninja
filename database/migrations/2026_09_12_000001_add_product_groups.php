<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_group')->default(false);
            $table->boolean('group_hide_item_prices')->default(false);
            $table->boolean('group_has_price')->default(false);
            $table->decimal('group_price', 16, 4)->default(0);
        });

        Schema::create('product_group_items', function (Blueprint $table) {
            $table->unsignedInteger('group_product_id');
            $table->unsignedInteger('product_id');
            $table->decimal('quantity', 16, 4)->default(1);
            $table->unsignedInteger('sort_id')->default(0);
            $table->primary(['group_product_id', 'product_id']);
            $table->foreign('group_product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_group_items');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_group', 'group_hide_item_prices', 'group_has_price', 'group_price']);
        });
    }
};
