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

namespace Tests\Pdf;

use App\Factory\InvoiceItemFactory;
use App\Services\Pdf\PdfConfiguration;
use App\Services\Pdf\PdfService;
use App\Services\PdfMaker\Design;
use App\Services\Template\TemplateService;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * 
 *   App\Services\Pdf\PdfService
 */
class PdfServiceTest extends TestCase
{
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testPdfGeneration()
    {

        if(config('ninja.testvars.travis')) {
            $this->markTestSkipped();
        }

        $invitation = $this->invoice->invitations->first();

        $service = (new PdfService($invitation))->boot();

        $this->assertNotNull($service->getPdf());

    }

    public function testHtmlGeneration()
    {

        $invitation = $this->invoice->invitations->first();

        $service = (new PdfService($invitation))->boot();

        $this->assertIsString($service->getHtml());

    }

    public function testInitOfClass()
    {

        $invitation = $this->invoice->invitations->first();

        $service = (new PdfService($invitation))->boot();

        $this->assertInstanceOf(PdfService::class, $service);

    }

    public function testEntityResolution()
    {

        $invitation = $this->invoice->invitations->first();

        $service = (new PdfService($invitation))->boot();

        $this->assertInstanceOf(PdfConfiguration::class, $service->config);


    }

    public function testDefaultDesign()
    {
        $invitation = $this->invoice->invitations->first();

        $service = (new PdfService($invitation))->boot();

        $this->assertEquals(2, $service->config->design->id);

    }

    public function testHtmlIsArray()
    {
        $invitation = $this->invoice->invitations->first();

        $service = (new PdfService($invitation))->boot();

        $this->assertIsArray($service->html_variables);

    }

    public function testLineTotalExcludesLineAndInvoiceTaxes()
    {
        $item = InvoiceItemFactory::create();
        $item->cost = 100;
        $item->line_total = 100;
        $item->gross_line_total = 108.1;
        $item->tax_name1 = 'VAT';
        $item->tax_rate1 = 8.1;

        $this->invoice->uses_inclusive_taxes = false;
        $this->invoice->tax_name1 = 'VAT';
        $this->invoice->tax_rate1 = 8.1;
        $this->invoice->line_items = [$item];
        $this->invoice->save();

        $service = (new PdfService($this->invoice->invitations->first()))->boot();
        $design = new Design();
        $design->entity = $this->invoice;
        $design->client = $this->client;
        $design->company = $this->company;

        $this->assertSame('$100.00', $service->builder->transformLineItems([$item])[0]['$product.line_total']);
        $this->assertSame('$100.00', $this->invoice->transformLineItems([$item])[0]['$product.line_total']);
        $this->assertSame('$100.00', $design->transformLineItems([$item])[0]['$product.line_total']);
        $this->assertSame('$116.20', $service->html_variables['values']['$subtotal']);

        $service->config->entity->uses_inclusive_taxes = true;
        $this->invoice->uses_inclusive_taxes = true;

        $this->assertSame('$100.00', $service->builder->transformLineItems([$item])[0]['$product.line_total']);
        $this->assertSame('$100.00', $this->invoice->transformLineItems([$item])[0]['$product.line_total']);
        $this->assertSame('$100.00', $design->transformLineItems([$item])[0]['$product.line_total']);
    }

    public function testTimeCoefficientIsAvailableAsPdfProductColumn()
    {
        $item = InvoiceItemFactory::create();
        $item->quantity = 2;
        $item->cost = 25;
        $item->time_coefficient = 3.5;
        $item->time_coefficient_name = 'Three and a half days';
        $item->line_total = 175;
        $item->gross_line_total = 189;

        $service = (new PdfService($this->invoice->invitations->first()))->boot();
        $values = $service->builder->transformLineItems([$item])[0];

        $this->assertSame('3.5', $values['$product.time_coefficient']);
        $this->assertSame('Three and a half days', $values['$product.time_coefficient_name']);
        $this->assertSame('$175.00', $values['$product.line_total']);
    }

    public function testPdfLineTotalRoundsOnlyForDisplay()
    {
        $item = InvoiceItemFactory::create();
        $item->cost = .05;
        $item->line_total = .05;
        $item->gross_line_total = .05;
        $item->tax_name1 = 'VAT';
        $item->tax_rate1 = 8.1;

        $this->invoice->uses_inclusive_taxes = false;
        $this->invoice->tax_name1 = 'VAT';
        $this->invoice->tax_rate1 = 8.1;
        $this->invoice->line_items = [$item, $item, $item];
        $this->invoice->save();

        $service = (new PdfService($this->invoice->invitations->first()))->boot();

        $this->assertSame('$0.05', $service->builder->transformLineItems([$item])[0]['$product.line_total']);
        $this->assertSame('$0.16', $service->html_variables['values']['$subtotal']);
    }

    public function testProductPoidsTotalVariablesAreAvailable()
    {
        $invitation = $this->poidsInvoiceInvitation('PoIds|single_line_text');

        $service = (new PdfService($invitation))->boot();

        $this->assertEquals('110', $service->html_variables['values']['$product.poids_total']);
        $this->assertEquals('110', $service->html_variables['values']['$poids_total']);
        $this->assertEquals('PoIds', $service->html_variables['labels']['$product.poids_total_label']);
        $this->assertEquals('PoIds', $service->html_variables['labels']['$poids_total_label']);
    }

    public function testProductPoidsTotalIsNotAutomaticallyRenderedOnInvoicePdfHtml()
    {
        $invitation = $this->poidsInvoiceInvitation('POIDS|single_line_text');

        $html = (new PdfService($invitation))->boot()->getHtml();

        $this->assertStringNotContainsString('totals_table-product.poids_total', $html);
    }

    public function testProductPoidsTotalIsNotAutomaticallyRenderedOnDeliveryNotePdfHtml()
    {
        $invitation = $this->poidsInvoiceInvitation('Poids|single_line_text');

        $html = (new PdfService($invitation, PdfService::DELIVERY_NOTE))->boot()->getHtml();

        $this->assertStringNotContainsString('totals_table-product.poids_total', $html);
    }

    public function testProductPoidsTotalIsAvailableInTemplateInvoiceData()
    {
        $invitation = $this->poidsInvoiceInvitation('pOiDs|single_line_text');

        $data = (new TemplateService())
            ->setCompany($this->company)
            ->processData(['invoices' => collect([$invitation->invoice])])
            ->getData();

        $this->assertSame('110', $data['invoices'][0]['poids_total']);
        $this->assertSame(110.0, $data['invoices'][0]['poids_total_raw']);
        $this->assertSame('pOiDs', $data['invoices'][0]['poids_total_label']);
        $this->assertSame('110', $data['poids_total']);
        $this->assertSame(110.0, $data['poids_total_raw']);
        $this->assertSame('pOiDs', $data['poids_total_label']);
    }

    public function testProductPoidsTotalIsEmptyWithoutMatchingField()
    {
        $invitation = $this->poidsInvoiceInvitation('Weight|single_line_text');

        $service = (new PdfService($invitation))->boot();

        $this->assertSame('', $service->html_variables['values']['$product.poids_total']);
        $this->assertStringNotContainsString('totals_table-product.poids_total', $service->getHtml());
    }

    public function testProductPoidsTotalUsesMixedInputShapes()
    {
        $custom_fields = (array) ($this->company->custom_fields ?: []);
        $custom_fields['product2'] = 'Poids|single_line_text';
        $this->company->custom_fields = $custom_fields;
        $this->company->save();

        $first_item = InvoiceItemFactory::create();
        $first_item->quantity = 3;
        $first_item->custom_value2 = '2.5';

        $this->invoice->line_items = [
            (array) $first_item,
            ['quantity' => 2, 'custom_value2' => '1,25'],
        ];
        $this->invoice->save();

        $invitation = $this->invoice->invitations()->first()->fresh(['company', 'invoice.client']);
        $service = (new PdfService($invitation))->boot();

        $this->assertEquals('10', $service->html_variables['values']['$poids_total']);
    }

    public function testTemplateResolution()
    {
        $invitation = $this->invoice->invitations->first();

        $service = (new PdfService($invitation))->boot();

        $this->assertIsString($service->designer->template);

    }

    private function poidsInvoiceInvitation(string $product_custom_field)
    {
        $custom_fields = $this->company->custom_fields ?: new \stdClass();
        $custom_fields->product2 = $product_custom_field;

        $this->company->custom_fields = $custom_fields;
        $this->company->save();

        $first_item = InvoiceItemFactory::create();
        $first_item->quantity = 3;
        $first_item->cost = 10;
        $first_item->line_total = 30;
        $first_item->custom_value2 = '2.5';

        $second_item = InvoiceItemFactory::create();
        $second_item->quantity = 2;
        $second_item->cost = 10;
        $second_item->line_total = 20;
        $second_item->custom_value2 = '1,25';

        $invalid_item = InvoiceItemFactory::create();
        $invalid_item->quantity = 5;
        $invalid_item->cost = 10;
        $invalid_item->line_total = 50;
        $invalid_item->custom_value2 = 'not numeric';

        $task_item = InvoiceItemFactory::create();
        $task_item->type_id = 2;
        $task_item->quantity = 10;
        $task_item->cost = 10;
        $task_item->line_total = 100;
        $task_item->custom_value2 = '10';

        $this->invoice->line_items = [$first_item, $second_item, $invalid_item, $task_item];
        $this->invoice->save();

        return $this->invoice->invitations()->first()->fresh(['company', 'invoice.client']);
    }

}
