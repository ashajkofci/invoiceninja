<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Services\ProductReservation\ProductReservationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductReservationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedTinyInteger('status_id');
            $table->boolean('is_deleted')->default(false);
            $table->json('line_items');
            $table->string('custom_value1')->nullable();
            $table->string('custom_value2')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('product_key');
            $table->string('notes')->nullable();
            $table->integer('in_stock_quantity')->default(0);
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function testEditAvailabilityUsesTheInvoiceCalendarDates(): void
    {
        $company = (new Company())->forceFill([
            'id' => 1,
            'enabled_modules' => Company::MODULE_PRODUCT_RESERVATIONS,
            'reservation_start_custom_field' => 1,
            'reservation_end_custom_field' => 2,
        ]);
        DB::table('products')->insert([
            'company_id' => $company->id,
            'product_key' => 'calendar-item',
            'in_stock_quantity' => 10,
        ]);
        DB::table('invoices')->insert([
            'id' => 42,
            'company_id' => $company->id,
            'status_id' => 2,
            'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 2]]),
            'custom_value1' => '2020-01-01',
            'custom_value2' => '2020-01-02',
        ]);

        $availability = (new ProductReservationService($company))->availability(
            '2020-01-01',
            '2020-01-02',
            [['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 2]],
            42
        );

        $this->assertSame('calendar-item', $availability[0]['product_key']);
        $this->assertSame(2.0, $availability[0]['requested_quantity']);
        $this->assertSame(10.0, $availability[0]['available_quantity']);
    }
}
