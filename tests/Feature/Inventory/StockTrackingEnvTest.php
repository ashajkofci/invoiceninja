<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Feature\Inventory;

use App\DataMapper\InvoiceItem;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\MockAccountData;
use Tests\TestCase;

class StockTrackingEnvTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    private Product $testProduct;

    private array $invoice_array;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();

        $this->withoutMiddleware(
            ThrottleRequests::class
        );

        if (config('ninja.testvars.travis') !== false) {
            $this->markTestSkipped('Skip test for GH Actions');
        }

        $this->testProduct = Product::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'in_stock_quantity' => 100,
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'line_items' => [],
        ]);

        $invoice->company->track_inventory = true;
        $invoice->push();

        $invoice_item = new InvoiceItem();
        $invoice_item->type_id = 1;
        $invoice_item->product_key = $this->testProduct->product_key;
        $invoice_item->notes = $this->testProduct->notes;
        $invoice_item->quantity = 10;
        $invoice_item->cost = 100;

        $line_items[] = $invoice_item;
        $invoice->line_items = $line_items;
        $invoice->number = Str::random(16);

        $this->invoice_array = $invoice->toArray();
        $this->invoice_array['client_id'] = $this->client->hashed_id;

        putenv('ENABLE_STOCK_TRACKING');
    }

    protected function tearDown(): void
    {
        putenv('ENABLE_STOCK_TRACKING');

        parent::tearDown();
    }

    public function testStockDoesNotChangeWhenEnvNotSet()
    {
        $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/invoices/', $this->invoice_array)
        ->assertStatus(200);

        $this->assertEquals(100, $this->testProduct->fresh()->in_stock_quantity);
    }

    public function testStockDoesNotChangeWhenEnvIsFalse()
    {
        putenv('ENABLE_STOCK_TRACKING=false');

        $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/invoices/', $this->invoice_array)
        ->assertStatus(200);

        $this->assertEquals(100, $this->testProduct->fresh()->in_stock_quantity);
    }

    public function testStockDoesNotChangeWhenEnvIsZero()
    {
        putenv('ENABLE_STOCK_TRACKING=0');

        $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/invoices/', $this->invoice_array)
        ->assertStatus(200);

        $this->assertEquals(100, $this->testProduct->fresh()->in_stock_quantity);
    }

    public function testStockChangesWhenEnvIsTrue()
    {
        putenv('ENABLE_STOCK_TRACKING=true');

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/invoices/', $this->invoice_array)
        ->assertStatus(200);

        $this->assertEquals(90, $this->testProduct->fresh()->in_stock_quantity);

        $data = $response->json();
        $invoice = Invoice::find($this->decodePrimaryKey($data['data']['id']));

        $invoice->service()->markDeleted()->save();
        $invoice->is_deleted = true;
        $invoice->save();

        $this->assertEquals(100, $this->testProduct->fresh()->in_stock_quantity);

        $invoice = Invoice::withTrashed()->find($this->decodePrimaryKey($data['data']['id']));
        $invoice->service()->handleRestore()->save();

        $this->assertEquals(90, $this->testProduct->fresh()->in_stock_quantity);
    }

    public function testStockChangesWhenEnvIsOne()
    {
        putenv('ENABLE_STOCK_TRACKING=1');

        $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/invoices/', $this->invoice_array)
        ->assertStatus(200);

        $this->assertEquals(90, $this->testProduct->fresh()->in_stock_quantity);
    }

    public function testStockDoesNotChangeWhenEnvTrueButCompanyDisabled()
    {
        putenv('ENABLE_STOCK_TRACKING=true');

        $this->company->track_inventory = false;
        $this->company->save();

        $invoice = Invoice::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'line_items' => [],
        ]);

        $invoice->push();

        $invoice_item = new InvoiceItem();
        $invoice_item->type_id = 1;
        $invoice_item->product_key = $this->testProduct->product_key;
        $invoice_item->notes = $this->testProduct->notes;
        $invoice_item->quantity = 10;
        $invoice_item->cost = 100;

        $line_items[] = $invoice_item;
        $invoice->line_items = $line_items;
        $invoice->number = Str::random(16);

        $invoice_array = $invoice->toArray();
        $invoice_array['client_id'] = $this->client->hashed_id;

        $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/invoices/', $invoice_array)
        ->assertStatus(200);

        $this->assertEquals(100, $this->testProduct->fresh()->in_stock_quantity);
    }

    public function testStockChangeWithDeleteAndRestoreWhenEnabled()
    {
        putenv('ENABLE_STOCK_TRACKING=true');

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/invoices/', $this->invoice_array)
        ->assertStatus(200);

        $this->assertEquals(90, $this->testProduct->fresh()->in_stock_quantity);

        $data = $response->json();
        $invoice = Invoice::find($this->decodePrimaryKey($data['data']['id']));

        $invoice->service()->markDeleted()->save();
        $invoice->is_deleted = true;
        $invoice->save();

        $this->assertEquals(100, $this->testProduct->fresh()->in_stock_quantity);

        $invoice = Invoice::withTrashed()->find($this->decodePrimaryKey($data['data']['id']));
        $invoice->service()->handleRestore()->save();

        $this->assertEquals(90, $this->testProduct->fresh()->in_stock_quantity);
    }

    public function testStockDoesNotChangeOnDeleteAndRestoreWhenDisabled()
    {
        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->post('/api/v1/invoices/', $this->invoice_array)
        ->assertStatus(200);

        $this->assertEquals(100, $this->testProduct->fresh()->in_stock_quantity);

        $data = $response->json();
        $invoice = Invoice::find($this->decodePrimaryKey($data['data']['id']));

        $invoice->service()->markDeleted()->save();
        $invoice->is_deleted = true;
        $invoice->save();

        $this->assertEquals(100, $this->testProduct->fresh()->in_stock_quantity);

        $invoice = Invoice::withTrashed()->find($this->decodePrimaryKey($data['data']['id']));
        $invoice->service()->handleRestore()->save();

        $this->assertEquals(100, $this->testProduct->fresh()->in_stock_quantity);
    }
}
