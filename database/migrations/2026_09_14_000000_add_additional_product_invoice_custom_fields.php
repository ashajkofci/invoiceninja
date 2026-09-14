<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLES = [
        'products',
        'invoices',
        'quotes',
        'credits',
        'purchase_orders',
        'recurring_invoices',
        'recurring_quotes',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                foreach (range(5, 8) as $field_number) {
                    $table->text("custom_value{$field_number}")->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->dropColumn([
                    'custom_value5',
                    'custom_value6',
                    'custom_value7',
                    'custom_value8',
                ]);
            });
        }
    }
};
