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

    public function testReservationTrackingAndStockTrackingAreMutuallyExclusive(): void
    {
        $company = (new Company())->forceFill([
            'enabled_modules' => Company::MODULE_PRODUCT_RESERVATIONS,
            'track_inventory' => true,
            'reservation_start_custom_field' => 1,
            'reservation_end_custom_field' => 2,
        ]);

        $this->assertFalse((new ProductReservationService($company))->enabled());
    }

    public function testAvailabilityOnlyReturnsProductsOnTheInvoice(): void
    {
        $company = (new Company())->forceFill([
            'id' => 1,
            'reservation_start_custom_field' => 1,
            'reservation_end_custom_field' => 2,
        ]);
        DB::table('products')->insert([
            [
                'company_id' => $company->id,
                'product_key' => 'requested-item',
                'in_stock_quantity' => 10,
            ],
            [
                'company_id' => $company->id,
                'product_key' => 'unrelated-item',
                'in_stock_quantity' => 1,
            ],
        ]);
        DB::table('invoices')->insert([
            'company_id' => $company->id,
            'status_id' => 2,
            'line_items' => json_encode([['type_id' => 1, 'product_key' => 'unrelated-item', 'quantity' => 2]]),
            'custom_value1' => '2026-09-13',
            'custom_value2' => '2026-09-14',
        ]);

        $availability = (new ProductReservationService($company))->availability(
            '2026-09-13',
            '2026-09-14',
            [['type_id' => 1, 'product_key' => 'requested-item', 'quantity' => 1]]
        );

        $this->assertSame(['requested-item'], array_column($availability, 'product_key'));
    }

    public function testAvailabilityUsesPeakReservationsAcrossTheInvoicePeriod(): void
    {
        $company = (new Company())->forceFill([
            'id' => 1,
            'reservation_start_custom_field' => 1,
            'reservation_end_custom_field' => 2,
        ]);
        DB::table('products')->insert([
            'company_id' => $company->id,
            'product_key' => 'calendar-item',
            'in_stock_quantity' => 10,
        ]);
        DB::table('invoices')->insert([
            [
                'company_id' => $company->id,
                'status_id' => 2,
                'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 4]]),
                'custom_value1' => '2026-09-01',
                'custom_value2' => '2026-09-02',
            ],
            [
                'company_id' => $company->id,
                'status_id' => 2,
                'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 6]]),
                'custom_value1' => '2026-09-03',
                'custom_value2' => '2026-09-04',
            ],
            [
                'company_id' => $company->id,
                'status_id' => 2,
                'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 2]]),
                'custom_value1' => '2026-09-02',
                'custom_value2' => '2026-09-03',
            ],
        ]);

        $availability = (new ProductReservationService($company))->availability(
            '2026-09-01',
            '2026-09-04',
            [['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 1]]
        );

        $this->assertSame(8.0, $availability[0]['reserved_quantity']);
        $this->assertSame(2.0, $availability[0]['available_quantity']);
        $this->assertSame(9.0, $availability[0]['total_quantity']);
    }

    public function testSingleDayAvailabilityUsesConfiguredStockCapacity(): void
    {
        $company = (new Company())->forceFill([
            'id' => 1,
            'reservation_start_custom_field' => 1,
            'reservation_end_custom_field' => 2,
        ]);
        DB::table('products')->insert([
            'company_id' => $company->id,
            'product_key' => 'calendar-item',
            'in_stock_quantity' => 12,
        ]);
        DB::table('invoices')->insert([
            'company_id' => $company->id,
            'status_id' => 2,
            'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 4]]),
            'custom_value1' => '2026-09-13',
            'custom_value2' => '2026-09-13',
        ]);

        $availability = (new ProductReservationService($company))->availability(
            '2026-10-31',
            '2026-10-31',
            [['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 1]]
        );

        $this->assertSame(0.0, $availability[0]['reserved_quantity']);
        $this->assertSame(12.0, $availability[0]['stock_quantity']);
        $this->assertSame(12.0, $availability[0]['available_quantity']);

        $today = (new ProductReservationService($company))->availability(
            '2026-09-13',
            '2026-09-13',
            [['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 1]]
        );

        $this->assertSame(4.0, $today[0]['reserved_quantity']);
        $this->assertSame(8.0, $today[0]['available_quantity']);
    }
}
