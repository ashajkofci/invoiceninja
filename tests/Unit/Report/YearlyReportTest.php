<?php

namespace Tests\Unit\Report;

use App\Models\Expense;
use App\Models\Payment;
use App\Services\Report\YearlyReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class YearlyReportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createReportTables();
    }

    public function test_it_groups_payments_and_expenses_by_month_currency_and_category(): void
    {
        DB::table('payments')->insert([
            'company_id' => 123,
            'is_deleted' => false,
            'status_id' => Payment::STATUS_COMPLETED,
            'currency_id' => 1,
            'amount' => 120,
            'refunded' => 20,
            'exchange_rate' => 1,
            'date' => '2099-01-15',
        ]);

        DB::table('payments')->insert([
            'company_id' => 123,
            'is_deleted' => false,
            'status_id' => Payment::STATUS_COMPLETED,
            'currency_id' => 2,
            'amount' => 50,
            'exchange_rate' => 2,
            'date' => '2099-02-15',
        ]);

        DB::table('payments')->insert([
            'company_id' => 123,
            'is_deleted' => true,
            'status_id' => Payment::STATUS_COMPLETED,
            'amount' => 999,
            'refunded' => 0,
            'currency_id' => 1,
            'date' => '2099-01-15',
            'exchange_rate' => 9,
        ]);

        DB::table('payments')->insert([
            'company_id' => 123,
            'is_deleted' => false,
            'status_id' => Payment::STATUS_COMPLETED,
            'amount' => 999,
            'refunded' => 0,
            'currency_id' => 1,
            'date' => '2099-01-15',
            'exchange_rate' => 9,
            'deleted_at' => now(),
        ]);

        DB::table('expenses')->insert([
            'company_id' => 123,
            'user_id' => 1,
            'is_deleted' => false,
            'currency_id' => 1,
            'category_id' => 77,
            'amount' => 30,
            'exchange_rate' => 1,
            'date' => '2099-01-20',
        ]);

        DB::table('expenses')->insert([
            'company_id' => 123,
            'user_id' => 1,
            'is_deleted' => false,
            'currency_id' => 2,
            'amount' => 15,
            'exchange_rate' => 2,
            'date' => '2099-03-20',
        ]);

        DB::table('expenses')->insert([
            'company_id' => 123,
            'user_id' => 1,
            'is_deleted' => true,
            'currency_id' => 1,
            'amount' => 999,
            'exchange_rate' => 9,
            'date' => '2099-01-20',
        ]);

        DB::table('expenses')->insert([
            'company_id' => 123,
            'user_id' => 1,
            'is_deleted' => false,
            'currency_id' => 1,
            'amount' => 999,
            'exchange_rate' => 9,
            'date' => '2099-01-20',
            'deleted_at' => now(),
        ]);

        $company = new \App\Models\Company();
        $company->forceFill([
            'id' => 123,
            'settings' => (object) ['currency_id' => '1'],
        ]);

        $report = (new YearlyReport($company, 2099))->run();
        $usd = collect($report['currencies'])->firstWhere('currency_id', '1');
        $gbp = collect($report['currencies'])->firstWhere('currency_id', '2');

        $this->assertCount(2, $report['currencies']);
        $this->assertSame(100.0, collect($usd['payments'])->firstWhere('month', 1)['total']);
        $this->assertSame(50.0, collect($gbp['payments'])->firstWhere('month', 2)['total']);
        $this->assertSame(30.0, $usd['expenses'][0]['total']);
        $this->assertSame(15.0, $gbp['expenses'][0]['months'][2]);

        $converted = (new YearlyReport($company, 2099, true))->run();
        $mainCurrency = $converted['currencies'][0];

        $this->assertCount(1, $converted['currencies']);
        $this->assertSame('1', $mainCurrency['currency_id']);
        $this->assertSame(100.0, collect($mainCurrency['payments'])->firstWhere('month', 2)['total']);
        $this->assertSame(30.0, collect($mainCurrency['expenses'])->firstWhere('category_id', null)['months'][2]);
        $this->assertSame(30.0, collect($mainCurrency['expenses'])->firstWhere('category_id', 77)['total']);
    }

    private function createReportTables(): void
    {
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->boolean('is_deleted')->default(false);
                $table->unsignedInteger('status_id');
                $table->date('date')->nullable();
                $table->decimal('amount', 20, 6)->default(0);
                $table->decimal('refunded', 20, 6)->default(0);
                $table->decimal('exchange_rate', 20, 10)->default(1);
                $table->unsignedInteger('currency_id')->nullable();
                $table->timestamp('deleted_at')->nullable();
            });
        }

        if (! Schema::hasTable('expenses')) {
            Schema::create('expenses', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->boolean('is_deleted')->default(false);
                $table->date('date')->nullable();
                $table->decimal('amount', 20, 6)->default(0);
                $table->decimal('exchange_rate', 20, 10)->default(1);
                $table->unsignedInteger('currency_id')->nullable();
                $table->unsignedBigInteger('category_id')->nullable();
                $table->timestamp('deleted_at')->nullable();
            });
        }

        if (! Schema::hasTable('currencies')) {
            Schema::create('currencies', function ($table): void {
                $table->unsignedInteger('id')->primary();
                $table->string('code');
                $table->string('name');
                $table->string('symbol')->nullable();
            });

            DB::table('currencies')->insert([
                ['id' => 1, 'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$'],
                ['id' => 2, 'code' => 'GBP', 'name' => 'Pound sterling', 'symbol' => '£'],
            ]);
        }

        if (! Schema::hasTable('expense_categories')) {
            Schema::create('expense_categories', function ($table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->timestamp('deleted_at')->nullable();
            });
        }
    }
}
