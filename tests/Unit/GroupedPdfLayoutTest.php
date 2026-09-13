<?php

namespace Tests\Unit;

use App\DataMapper\InvoiceItem;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceInvitation;
use App\Services\Pdf\PdfBuilder;
use App\Services\Pdf\PdfConfiguration;
use App\Services\Pdf\PdfService;
use App\Services\PdfMaker\Design;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Presentation tests only: totals are synthetic, already-calculated inputs. */
class GroupedPdfLayoutTest extends TestCase
{
    private $previousApplication;
    private $previousResolver;
    private $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Application::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        // No kernel/providers, environment file, database, cache or business data.
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository(['app' => ['key' => '', 'cipher' => 'AES-256-CBC']]));
        Facade::setFacadeApplication($app);
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $resolver->expects(self::never())->method('connection');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Application::setInstance($this->previousApplication);
        parent::tearDown();
    }

    public static function layouts(): iterable
    {
        foreach (['builder', 'legacy'] as $pipeline) {
            yield "$pipeline standard" => [$pipeline, ['product_key', 'notes', 'quantity', 'unit_cost', 'time_coefficient', 'line_total']];
            yield "$pipeline reordered" => [$pipeline, ['line_total', 'notes', 'time_coefficient', 'item', 'unit_cost', 'quantity']];
            yield "$pipeline no price columns" => [$pipeline, ['quantity', 'item', 'notes', 'time_coefficient']];
        }
    }

    #[DataProvider('layouts')]
    public function testRealRendererPreservesGroupedLayout(string $pipeline, array $fields): void
    {
        [$renderer, $builder, $invoice] = $this->renderers($pipeline, $fields);
        $rows = $renderer->buildTableBody('$product');
        $headers = $renderer->buildTableHeader('product');
        self::assertCount(5, $rows);
        self::assertCount(count($fields), $headers);
        self::assertSame(['group-header', 'group-item', 'group-header', 'group-item', ''], array_column(array_column($rows, 'properties'), 'class'));
        foreach ($fields as $column => $field) {
            $headerField = $field === 'product_key' ? 'item' : $field;
            self::assertSame("product_table-product.$headerField-th", $headers[$column]['properties']['data-ref']);
        }

        foreach ($rows as $index => $row) {
            self::assertCount(count($fields), $row['elements']);
            foreach ($row['elements'] as $column => $cell) {
                $field = $fields[$column];
                self::assertSame("product_table-product.$field-td", $cell['properties']['data-ref']);
                self::assertArrayNotHasKey('colspan', $cell['properties']);
                self::assertArrayNotHasKey('rowspan', $cell['properties']);
                $style = $cell['properties']['style'] ?? '';
                if (in_array($index, [0, 2], true)) {
                    self::assertStringContainsString('font-weight: 700 !important', $style);
                    self::assertStringContainsString('background-color: #e8edf3 !important', $style);
                } elseif (in_array($index, [1, 3], true)) {
                    self::assertStringContainsString('font-weight: 400', $style);
                    if (in_array($field, ['product_key', 'item'], true)) {
                        self::assertSame('div', $cell['elements'][0]['element']);
                        self::assertStringContainsString('font-style: italic', $cell['elements'][0]['properties']['style']);
                        self::assertStringContainsString('margin-left: 0.9rem', $cell['elements'][0]['properties']['style']);
                    }
                } else {
                    self::assertStringNotContainsString('background-color', $style);
                    self::assertStringNotContainsString('<div', $cell['content']);
                }
                $numeric = in_array($field, ['quantity', 'unit_cost', 'time_coefficient', 'line_total'], true);
                if ($numeric) {
                    self::assertStringContainsString('text-align: right !important', $style);
                    self::assertStringContainsString('text-align: right !important', $headers[$column]['properties']['style']);
                } else {
                    self::assertStringNotContainsString('text-align: right', $style);
                }
                if ($index === 1 && in_array($field, ['unit_cost', 'line_total'], true)) {
                    self::assertSame('', $cell['content'], 'Hidden child prices must remain empty cells.');
                }
            }
        }

        foreach ([0, 2] as $index) {
            self::assertStringContainsString('break-after: avoid', $rows[$index]['properties']['style']);
        }
        self::assertFalse($invoice->exists);

        // Exercise the actual DOM writer too (HTML content is intentionally encoded).
        $document = new \DOMDocument('1.0', 'UTF-8');
        $table = $document->appendChild($document->createElement('table'));
        $builder->setDocument($document)->createElementContent($table, [
            ['element' => 'thead', 'elements' => [['element' => 'tr', 'elements' => $headers]]],
            ['element' => 'tbody', 'elements' => $rows],
        ]);
        $xpath = new \DOMXPath($document);
        self::assertSame(5, $xpath->query('//tbody/tr')->length);
        self::assertSame(count($fields) * 5, $xpath->query('//tbody/tr/td')->length);
        self::assertSame(2, $xpath->query('//tr[@class="group-item"]/td/div')->length);
        self::assertSame(0, $xpath->query('//tr[@class="group-item"]/td[@data-state="encoded-html"]')->length);
        self::assertStringContainsString('font-style: italic', html_entity_decode($document->saveHTML()));
    }

    public static function pipelines(): iterable
    {
        yield 'builder' => ['builder'];
        yield 'legacy' => ['legacy'];
    }

    #[DataProvider('pipelines')]
    public function testRealTransformsHideOnlyGroupedChildPrices(string $pipeline): void
    {
        [$renderer, , $invoice] = $this->renderers($pipeline, ['item']);
        $data = $renderer->transformLineItems($invoice->line_items, '$product');
        foreach (['unit_cost', 'cost', 'line_total', 'gross_line_total', 'tax_amount', 'discount', 'tax_rate1', 'tax_rate2', 'tax_rate3', 'tax1', 'tax2', 'tax3'] as $field) {
            self::assertSame('', $data[1]['$product.'.$field], $field);
        }
        foreach ([0 => ['Fixed package', '$500.00'], 2 => ['Automatic package', '$150.00']] as $index => [$title, $total]) {
            self::assertTrue($data[$index]['__is_group_header']);
            self::assertSame($title, $data[$index]['$product.item']);
            self::assertSame($total, $data[$index]['$product.line_total']);
            foreach (['quantity', 'time_coefficient', 'unit_cost', 'cost', 'discount'] as $field) {
                self::assertSame('', $data[$index]['$product.'.$field]);
            }
        }
        self::assertTrue($data[1]['__is_group_child']);
        self::assertSame('2', $data[1]['$product.quantity']);
        self::assertSame('1.5', $data[1]['$product.time_coefficient']);
        self::assertSame('$50.00', $data[3]['$product.unit_cost']);
        self::assertSame('$150.00', $data[3]['$product.line_total']);
        self::assertFalse($data[4]['__is_group_child']);
        self::assertSame('$25.00', $data[4]['$product.line_total']);
    }

    /** PDF_LAYOUT_OUTPUT is an optional HTML file path, not a PDF output path. */
    public function testSyntheticHtmlFixture(): void
    {
        $fields = ['item', 'notes', 'quantity', 'unit_cost', 'time_coefficient', 'line_total'];
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<!doctype html><html lang="en"><head><meta charset="UTF-8"><title>Grouped PDF layout — synthetic fixture</title></head><body></body></html>');
        $style = $document->createElement('style');
        // Use the shipped Clean design, not a simplified table that could hide
        // differences in header/body padding or theme overrides.
        $template = file_get_contents(dirname(__DIR__, 2).'/resources/views/pdf-designs/clean.html');
        preg_match('/<style id="style">(.*?)<\/style>/s', $template, $matches);
        $style->nodeValue = strtr($matches[1], [
            '@import url($font_url);' => '', '$font_name' => 'Helvetica',
            '$font_size' => '14px', '$primary_color' => '#298AAB',
            '$secondary_color' => '#7081e0', '$global_margin' => '12mm',
            '$page_size' => 'A4', '$page_layout' => 'portrait',
            '$company_logo_size' => '120px', '$show_shipping_address' => 'none',
        ]).' section { break-before: page; } section:first-of-type { break-before: auto; }';
        $document->getElementsByTagName('head')->item(0)->appendChild($style);
        $body = $document->getElementsByTagName('body')->item(0);
        $body->appendChild($document->createElement('h1', 'Synthetic grouped invoice'));
        $body->appendChild($document->createElement('p', 'Fixed package: $500.00; automatic package: $150.00; standalone: $25.00. Illustrative total: $675.00. Totals are supplied inputs, not a calculation test.'));
        foreach (['builder', 'legacy'] as $pipeline) {
            [$renderer, $builder] = $this->renderers($pipeline, $fields);
            $section = $body->appendChild($document->createElement('section'));
            $section->appendChild($document->createElement('h2', $pipeline === 'builder' ? 'PdfBuilder' : 'Legacy Design'));
            $table = $section->appendChild($document->createElement('table'));
            $table->setAttribute('data-ref', 'table');
            $headers = $renderer->buildTableHeader('product');
            foreach (['Item', 'Description', 'Quantity', 'Unit cost', 'Coefficient', 'Total'] as $column => $label) {
                $headers[$column]['content'] = $label;
            }
            $builder->setDocument($document)->createElementContent($table, [
                ['element' => 'thead', 'elements' => [['element' => 'tr', 'elements' => $headers]]],
                ['element' => 'tbody', 'elements' => $renderer->buildTableBody('$product')],
            ]);
        }

        // Group indentation is real DOM, not encoded HTML or a JavaScript effect.
        $xpath = new \DOMXPath($document);
        self::assertSame(0, $xpath->query('//*[@data-state="encoded-html"]')->length);
        self::assertSame(4, $xpath->query('//tr[@class="group-item"]/td/div[contains(@style,"font-style: italic")]')->length);
        self::assertSame(24, $xpath->query('//tr[@class="group-header"]/td[contains(@style,"font-weight: 700")]')->length);
        if ($path = getenv('PDF_LAYOUT_OUTPUT')) {
            $html = $document->saveHTML();
            self::assertSame(strlen($html), file_put_contents($path, $html), 'Unable to write PDF_LAYOUT_OUTPUT; its parent directory must exist.');
        }
    }

    private function renderers(string $pipeline, array $fields): array
    {
        $company = new Company();
        $company->custom_fields = (object) [];
        $company->enable_product_discount = true;
        $company->markdown_enabled = false;
        $currency = new Currency();
        $currency->forceFill(['code' => 'USD', 'symbol' => '$', 'precision' => 2, 'thousand_separator' => ',', 'decimal_separator' => '.', 'swap_currency_symbol' => false]);
        $country = new Country();
        $client = $this->getMockBuilder(Client::class)->onlyMethods(['currency', 'getSetting'])->getMock();
        $client->method('currency')->willReturn($currency);
        $client->method('getSetting')->willReturn(false);
        $client->setRelation('company', $company)->setRelation('country', $country);
        $invoice = new Invoice();
        $invoice->setRelation('company', $company)->setRelation('client', $client);
        $invoice->line_items = [
            $this->item(['type_id' => '7', 'group_id' => 'fixed', 'group_title' => 'Fixed package', 'group_has_price' => true, 'group_price' => 500, 'group_hide_item_prices' => true, 'cost' => 500, 'quantity' => 1, 'line_total' => 500]),
            $this->item(['group_id' => 'fixed', 'product_key' => 'Included equipment with a long wrapping label', 'cost' => 100, 'quantity' => 2, 'time_coefficient' => 1.5, 'line_total' => 300, 'gross_line_total' => 324, 'tax_amount' => 24, 'discount' => 5, 'tax_rate1' => 8, 'tax_rate2' => 2, 'tax_rate3' => 1]),
            $this->item(['type_id' => '7', 'group_id' => 'auto', 'group_title' => 'Automatic package', 'cost' => 150, 'quantity' => 1, 'line_total' => 150]),
            $this->item(['group_id' => 'auto', 'product_key' => 'Metered equipment', 'cost' => 50, 'quantity' => 2, 'time_coefficient' => 1.5, 'line_total' => 150]),
            $this->item(['product_key' => 'Standalone delivery', 'cost' => 25, 'quantity' => 1, 'line_total' => 25]),
        ];
        $invitation = new InvoiceInvitation();
        $invitation->setRelation('company', $company)->setRelation('invoice', $invoice);
        $service = new PdfService($invitation);
        $config = new PdfConfiguration($service);
        $config->entity = $invoice;
        $config->currency_entity = $client;
        $config->currency = $currency;
        $config->country = $country;
        $config->settings = (object) ['show_currency_code' => false, 'hide_empty_columns_on_pdf' => false];
        $config->pdf_variables = ['product_columns' => array_map(fn ($field) => '$product.'.$field, $fields)];
        $service->config = $config;
        $builder = new PdfBuilder($service);
        $legacy = new Design('clean');
        $legacy->entity = $invoice;
        $legacy->company = $company;
        $legacy->client = $client;
        $legacy->settings_object = $client;
        $legacy->context = ['pdf_variables' => $config->pdf_variables];

        return [$pipeline === 'builder' ? $builder : $legacy, $builder, $invoice];
    }

    private function item(array $values): InvoiceItem
    {
        $item = new InvoiceItem();
        $item->notes = 'Synthetic description long enough to wrap across several lines in a narrow invoice column. No customer or business data is used.';
        foreach ($values as $key => $value) {
            $item->{$key} = $value;
        }

        return $item;
    }
}