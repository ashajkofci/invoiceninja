<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Services\ProductReservation\ProductReservationService;
use Carbon\CarbonImmutable;
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
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedTinyInteger('status_id');
            $table->string('number')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->boolean('uses_inclusive_taxes')->default(false);
            $table->json('line_items');
            $table->string('custom_value1')->nullable();
            $table->string('custom_value2')->nullable();
            $table->string('custom_value3')->nullable();
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

    public function testCurrentAvailabilityExcludesPastReservations(): void
    {
        CarbonImmutable::setTestNow('2026-09-13');
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
                'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 6]]),
                'custom_value1' => '2026-09-01',
                'custom_value2' => '2026-09-02',
            ],
            [
                'company_id' => $company->id,
                'status_id' => 2,
                'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 2]]),
                'custom_value1' => '2026-09-12',
                'custom_value2' => '2026-09-14',
            ],
        ]);

        $service = new ProductReservationService($company);
        $availability = $service->availability('2026-09-01', '2026-09-30', [], null, null, true, true);

        $this->assertSame(2.0, $availability[0]['reserved_quantity']);
        $this->assertCount(1, $availability[0]['reservations']);
        $this->assertSame([], $service->availability('2026-09-01', '2026-09-12', [], null, null, true, true));

        CarbonImmutable::setTestNow();
    }

    public function testAvailabilityRejectsEmptyDatesInsteadOfUsingToday(): void
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

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reservation dates must be valid dates.');

        (new ProductReservationService($company))->availability(
            '',
            '',
            [['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 1]]
        );
    }

    public function testAvailabilityPeakIsClippedToTheRequestedInterval(): void
    {
        $company = (new Company())->forceFill([
            'id' => 1,
            'reservation_start_custom_field' => 1,
            'reservation_end_custom_field' => 2,
        ]);
        DB::table('products')->insert([
            'company_id' => $company->id,
            'product_key' => 'calendar-item',
            'in_stock_quantity' => 20,
        ]);
        DB::table('invoices')->insert([
            [
                'company_id' => $company->id,
                'status_id' => 2,
                'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 6]]),
                'custom_value1' => '2026-09-01',
                'custom_value2' => '2026-09-10',
            ],
            [
                'company_id' => $company->id,
                'status_id' => 2,
                'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 2]]),
                'custom_value1' => '2026-09-10',
                'custom_value2' => '2026-09-20',
            ],
        ]);

        // Both invoices overlap 2026-09-01..10, but only within the requested
        // interval do they peak at 6 and 6+2=8 respectively.
        $first = (new ProductReservationService($company))->availability(
            '2026-09-01',
            '2026-09-10',
            [['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 1]]
        );

        $this->assertSame(8.0, $first[0]['reserved_quantity']);
        $this->assertSame(12.0, $first[0]['available_quantity']);

        // Requested interval 2026-09-11..20: only the second invoice overlaps,
        // the first invoice's peak outside the interval must not count.
        $second = (new ProductReservationService($company))->availability(
            '2026-09-11',
            '2026-09-20',
            [['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 1]]
        );

        $this->assertSame(2.0, $second[0]['reserved_quantity']);
        $this->assertSame(18.0, $second[0]['available_quantity']);
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

    public function testStatusesCanOverrideTheReservationEndDateWithOrLogic(): void
    {
        $company = (new Company())->forceFill([
            'id' => 1,
            'reservation_start_custom_field' => 1,
            'reservation_end_custom_field' => 2,
            'reservation_status_custom_field' => 3,
            'reservation_statuses' => [
                ['value' => 'Returned', 'color' => '#2563eb'],
                ['value' => 'In use', 'color' => '#16a34a', 'overrides_end_date' => true],
                ['value' => 'Awaiting return', 'color' => '#d97706', 'overrides_end_date' => true],
            ],
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
                'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 2]]),
                'custom_value1' => '2026-09-01',
                'custom_value2' => '2026-09-02',
                'custom_value3' => 'In use',
            ],
            [
                'company_id' => $company->id,
                'status_id' => 2,
                'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 3]]),
                'custom_value1' => '2026-09-03',
                'custom_value2' => '2026-09-04',
                'custom_value3' => 'Awaiting return',
            ],
            [
                'company_id' => $company->id,
                'status_id' => 2,
                'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 1]]),
                'custom_value1' => '2026-10-15',
                'custom_value2' => '2026-10-15',
                'custom_value3' => 'Returned',
            ],
            [
                'company_id' => $company->id,
                'status_id' => 2,
                'line_items' => json_encode([['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 4]]),
                'custom_value1' => '2026-09-01',
                'custom_value2' => '2026-09-02',
                'custom_value3' => 'Returned',
            ],
        ]);

        $service = new ProductReservationService($company);
        $availability = $service->availability(
            '2026-10-15',
            '2026-10-15',
            [['type_id' => 1, 'product_key' => 'calendar-item', 'quantity' => 1]]
        );
        $calendar = $service->calendar('2026-10-01', '2026-10-31');

        $this->assertSame(6.0, $availability[0]['reserved_quantity']);
        $this->assertCount(3, $availability[0]['reservations']);
        $this->assertCount(3, $calendar);
        $this->assertSame(2, collect($calendar)->where('overrides_end_date', true)->count());
        $this->assertSame(
            ['2026-10-31'],
            collect($calendar)->where('overrides_end_date', true)->pluck('end_date')->unique()->values()->all()
        );
    }

    public function testHistoryReturnsRentalTotalsAndSplitsUsageAcrossYears(): void
    {
        $company = (new Company())->forceFill([
            'id' => 1,
            'reservation_start_custom_field' => 1,
            'reservation_end_custom_field' => 2,
            'reservation_status_custom_field' => 3,
            'reservation_statuses' => [['value' => 'Confirmed', 'color' => '#2563eb']],
        ]);
        $productId = DB::table('products')->insertGetId([
            'company_id' => $company->id,
            'product_key' => 'calendar-item',
            'in_stock_quantity' => 10,
        ]);
        DB::table('invoices')->insert([
            [
                'company_id' => $company->id,
                'status_id' => 2,
                'number' => '0001',
                'uses_inclusive_taxes' => false,
                'line_items' => json_encode([[
                    'type_id' => 1,
                    'product_key' => 'calendar-item',
                    'quantity' => 2,
                    'cost' => 25,
                    'discount' => 10,
                    'is_amount_discount' => false,
                    'time_coefficient' => 2,
                    'line_total' => 90,
                    'gross_line_total' => 99,
                    'tax_amount' => 9,
                ]]),
                'custom_value1' => '2025-12-30',
                'custom_value2' => '2026-01-02',
                'custom_value3' => 'Archived',
            ],
            [
                'company_id' => $company->id,
                'status_id' => 2,
                'number' => '0002',
                'uses_inclusive_taxes' => true,
                'line_items' => json_encode([[
                    'type_id' => 1,
                    'product_key' => 'calendar-item',
                    'quantity' => 1,
                    'cost' => 40,
                    'discount' => 5,
                    'is_amount_discount' => true,
                    'line_total' => 38.5,
                    'gross_line_total' => 38.5,
                    'tax_amount' => 3.5,
                ]]),
                'custom_value1' => '2026-02-01',
                'custom_value2' => '2026-02-03',
                'custom_value3' => 'Archived',
            ],
        ]);

        $result = (new ProductReservationService($company))->history($productId);

        $this->assertSame(2, $result['statistics']['total_rentals']);
        $this->assertSame(7, $result['statistics']['total_days']);
        $this->assertSame(3.5, $result['statistics']['average_days']);
        $this->assertSame(3.0, $result['statistics']['total_quantity']);
        $this->assertSame(5, $result['statistics']['by_year'][0]['total_days']);
        $this->assertSame(2, $result['statistics']['by_year'][1]['total_days']);
        $this->assertSame(35.0, $result['history'][0]['unit_price']);
        $this->assertSame(22.5, $result['history'][1]['unit_price']);
        $this->assertSame(125.0, $result['statistics']['totals_by_currency'][0]['total_price']);
    }
}
