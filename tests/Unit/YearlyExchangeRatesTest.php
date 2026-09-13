<?php

namespace Tests\Unit;

use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Expense;
use App\Repositories\CompanyRepository;
use App\Repositories\ExpenseRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class YearlyExchangeRatesTest extends TestCase
{
    public function testYearlyRatesValidationDefaultsAndExpenseUpdates(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'ninja.environment' => 'selfhost']);
        DB::purge('sqlite');
        Schema::create('currencies', fn (Blueprint $table) => $table->id());
        DB::table('currencies')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->json('settings');
            $table->timestamps();
            $table->softDeletes();
        });
        $migration = require database_path('migrations/2026_09_13_010000_add_yearly_exchange_rates_to_companies.php');
        $migration->up();
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('currency_id');
            $table->integer('invoice_currency_id')->nullable();
            $table->date('date');
            $table->double('exchange_rate')->default(1);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        $rate = ['year' => 2026, 'currency_id' => '2', 'base_currency_id' => '1', 'rate' => 0.95];
        $rules = UpdateCompanyRequest::yearlyExchangeRateRules();
        $this->assertTrue(Validator::make(['yearly_exchange_rates' => [$rate]], $rules)->passes());
        foreach ([['year' => 2025], ['rate' => 0], ['rate' => -1], ['rate' => 0.0000001], ['currency_id' => 999], ['currency_id' => 1]] as $invalid) {
            $this->assertTrue(Validator::make(['yearly_exchange_rates' => [array_replace($rate, $invalid)]], $rules)->fails());
        }
        $this->assertTrue(Validator::make(['yearly_exchange_rates' => [$rate, $rate]], $rules)->fails());
        $this->assertTrue(Validator::make(['yearly_exchange_rates' => null], $rules)->fails());

        // React sends the array along with the older JSON mirror; Flutter sends only JSON.
        $request = new UpdateCompanyRequest(['yearly_exchange_rates' => [$rate], 'yearly_exchange_rates_json' => '[]']);
        $request->prepareForValidation();
        $this->assertSame([$rate], $request->input('yearly_exchange_rates'));
        $request = new UpdateCompanyRequest(['yearly_exchange_rates_json' => json_encode([$rate])]);
        $request->prepareForValidation();
        $this->assertSame([$rate], $request->input('yearly_exchange_rates'));

        Model::withoutEvents(function () use ($rate) {
            $company = new Company();
            $company->settings = (object) ['currency_id' => '1'];
            $company->save();
            $otherCompany = new Company();
            $otherCompany->settings = (object) ['currency_id' => '1'];
            $otherCompany->save();
            $row = ['company_id' => $company->id, 'currency_id' => 2, 'date' => '2026-01-01', 'exchange_rate' => 1.23];
            DB::table('expenses')->insert([
                $row,
                array_replace($row, ['date' => '2026-12-31']),
                array_replace($row, ['date' => '2025-12-31']),
                array_replace($row, ['date' => '2027-01-01']),
                array_replace($row, ['currency_id' => 3]),
                array_replace($row, ['company_id' => $otherCompany->id]),
            ]);
            $archived = DB::table('expenses')->insertGetId($row + ['deleted_at' => now()]);
            $deleted = DB::table('expenses')->insertGetId($row + ['is_deleted' => true]);
            $repository = new CompanyRepository();
            $repository->save(['yearly_exchange_rates' => [$rate]], $company);
            $this->assertEquals(0.95, DB::table('expenses')->find(1)->exchange_rate);
            $this->assertEquals(0.95, DB::table('expenses')->find(2)->exchange_rate);
            $this->assertEquals(0.95, DB::table('expenses')->find($archived)->exchange_rate);
            foreach ([3, 4, 5, 6, $deleted] as $id) {
                $this->assertEquals(1.23, DB::table('expenses')->find($id)->exchange_rate);
            }
            DB::table('expenses')->where('id', 1)->update(['exchange_rate' => 1.1]);
            $repository->save(['yearly_exchange_rates' => [$rate]], $company);
            $this->assertEquals(1.1, DB::table('expenses')->find(1)->exchange_rate);
            $rate['rate'] = 0.97;
            $repository->save(['yearly_exchange_rates' => [$rate]], $company);
            $this->assertEquals(0.97, DB::table('expenses')->find(1)->exchange_rate);

            $expense = new Expense();
            $expense->forceFill($row + ['invoice_currency_id' => 1]);
            $expense->setRelation('company', $company);
            $expense->exchange_rate = 1;
            (new ExpenseRepository())->processExchangeRates(['exchange_rate' => 1], $expense);
            $this->assertEquals(0.97, $expense->exchange_rate);
            $expense->exchange_rate = 1;
            $expense->saveQuietly();
            $this->assertEquals(0.97, $expense->fresh()->exchange_rate);
            $expense->exchange_rate = 1.4;
            $expense->saveQuietly();
            $this->assertEquals(1.4, $expense->fresh()->exchange_rate);
            $expense->date = '2026-06-15';
            $expense->saveQuietly();
            $this->assertEquals(0.97, $expense->fresh()->exchange_rate);
            $expense->date = '2027-01-01';
            $this->assertNull($expense->yearlyExchangeRate());
            $expense->date = '2026-01-01';
            $company->settings = (object) ['currency_id' => '3'];
            $this->assertNull($expense->yearlyExchangeRate());

            $company->refresh();
            DB::unprepared("CREATE TRIGGER reject_rate_update BEFORE UPDATE ON expenses BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
            try {
                $repository->save(['yearly_exchange_rates' => [array_replace($rate, ['rate' => 0.99])]], $company);
                $this->fail('The expense update must fail.');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertStringContainsString('test rollback', $exception->getMessage());
            }
            $this->assertEquals(0.97, $company->fresh()->yearly_exchange_rates[0]['rate']);
            $this->assertEquals(0.97, DB::table('expenses')->find(1)->exchange_rate);
        });
        $migration->down();
        $this->assertFalse(Schema::hasColumn('companies', 'yearly_exchange_rates'));
    }
}
