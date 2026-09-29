<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketing_excluded_quotes', function (Blueprint $table) {
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('quote_id');
            $table->timestamp('created_at');
            $table->primary(['company_id', 'quote_id']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_excluded_quotes');
    }
};
