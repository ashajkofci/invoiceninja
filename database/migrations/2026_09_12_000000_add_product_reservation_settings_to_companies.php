<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('reservation_start_custom_field')->default(0);
            $table->unsignedTinyInteger('reservation_end_custom_field')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'reservation_start_custom_field',
                'reservation_end_custom_field',
            ]);
        });
    }
};
