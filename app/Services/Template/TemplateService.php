<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Services\Template;

use App\Models\Client;
use App\Models\Company;
use App\Models\Design;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Transformers\ProjectTransformer;
use App\Transformers\PurchaseOrderTransformer;
use App\Transformers\QuoteTransformer;
use App\Transformers\TaskTransformer;
use App\Utils\HostedPDF\NinjaPdf;
use App\Utils\HtmlEngine;
use App\Utils\Number;
use App\Utils\PaymentHtmlEngine;
use App\Utils\Traits\MakesDates;
use App\Utils\Traits\Pdf\PdfMaker;
use App\Utils\VendorHtmlEngine;
use League\Fractal\Manager;
use League\Fractal\Serializer\ArraySerializer;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Extension\DebugExtension;
use Twig\Extension\StringLoaderExtension;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\SecurityError;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TemplateService
{
    use MakesDates, PdfMaker;
    
    private \DomDocument $document;

    public Environment $twig;

    private string $compiled_html = '';

    private array $data = [];

    private array $variables = [];

    public ?Company $company;

    public function __construct(public ?Design $template = null)
    {
        $this->template = $template;
        $this->init();
    }
    
    /**
     * Boot Dom Document
     *
     * @return self
     */
    private function init(): self
    {
        $this->document = new \DOMDocument();
        $this->document->validateOnParse = true;
        $loader = new FilesystemLoader(storage_path());
        $this->twig = new Environment($loader, [
                'debug' => true,
        ]);

        $string_extension = new StringLoaderExtension();
        $this->twig->addExtension($string_extension);
        $this->twig->addExtension(new IntlExtension());
        $this->twig->addExtension(new DebugExtension());
        
        $function = new TwigFunction('img', function ($string, $style = '') {
            return '<img src="'.$string.'" style="'.$style.'"></img>';
        });
        $this->twig->addFunction($function);

        $filter = new TwigFilter('sum', function (array $array, string $column) {
            return array_sum(array_column($array, $column));
        });
        
        $this->twig->addFilter($filter);

        return $this;
    }
        
    /**
     * Iterate through all of the
     * ninja nodes
     *
     * @param array $data - the payload to be passed into the template
     * @return self
     */
    public function build(array $data): self
    {
        $this->compose()
             ->processData($data)
             ->parseNinjaBlocks()
             ->processVariables($data)
             ->parseVariables();

        return $this;
    }
    
    private function processVariables($data): self
    {
        $this->variables = $this->resolveHtmlEngine($data);

        return $this;
    }

    public function mock(): self
    {
        $tm = new TemplateMock($this->company);
        $tm->init();

        $this->data = $tm->engines;
        $this->variables = $tm->variables[0];


        $this->parseNinjaBlocks()
             ->parseVariables();

        return $this;
    }
    
    /**
     * Returns the HTML as string
     *
     * @return string
     */
    public function getHtml(): string
    {
        return $this->compiled_html;
    }

    public function getPdf(): mixed
    {

        if (config('ninja.invoiceninja_hosted_pdf_generation') || config('ninja.pdf_generator') == 'hosted_ninja') {
            $pdf = (new NinjaPdf())->build($this->compiled_html);
        } else {
            $pdf = $this->makePdf(null, null, $this->compiled_html);
        }

        return $pdf;

    }

    public function getData(): array
    {
        return $this->data;
    }
    
    public function processData($data): self
    {

        $this->data = $this->preProcessDataBlocks($data);

        return $this;
    }

    /**
     * Parses all Ninja tags in the document
     *
     * @return self
     */
    private function parseNinjaBlocks(): self
    {
        $replacements = [];

        $contents = $this->document->getElementsByTagName('ninja');

        foreach ($contents as $content) {
                                        
            $template = $content->ownerDocument->saveHTML($content);

            try {
                $template = $this->twig->createTemplate(html_entity_decode($template));
            } catch(SyntaxError $e) {
                nlog($e->getMessage());
                continue;
            } catch(Error $e) {
                nlog("error = " .$e->getMessage());
                continue;
            } catch(RuntimeError $e) {
                nlog("runtime = " .$e->getMessage());
                continue;
            } catch(LoaderError $e) {
                nlog("loader = " . $e->getMessage());
                continue;
            } catch(SecurityError $e) {
                nlog("security = " . $e->getMessage());
                continue;
            }

            $template = $template->render($this->data);

            $f = $this->document->createDocumentFragment();
            $f->appendXML(html_entity_decode($template));
            
            $replacements[] = $f;

        }

        foreach($contents as $key => $content) {
            $content->parentNode->replaceChild($replacements[$key], $content);
        }

        $this->save();

        return $this;

    }
    
    /**
     * Parses all variables in the document
     *
     * @return self
     */
    private function parseVariables(): self
    {

        $html = $this->getHtml();

        foreach($this->variables as $key => $variable) {
            
            if(isset($variable['labels']) && isset($variable['values'])) {
                $html = strtr($html, $variable['labels']);
                $html = strtr($html, $variable['values']);
            }
        }

        @$this->document->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $this->save();

        return $this;
    }
    
    /**
     * Saves the document and updates the compiled string.
     *
     * @return self
     */
    private function save(): self
    {
        $this->compiled_html = str_replace('%24', '$', $this->document->saveHTML());

        return $this;
    }

    /**
     * compose
     *
     * @return self
     */
    private function compose(): self
    {
        if(!$this->template) {
            return $this;
        }

        $html = '';
        $html .= $this->template->design->includes;
        $html .= $this->template->design->header;
        $html .= $this->template->design->body;
        $html .= $this->template->design->footer;

        @$this->document->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        return $this;

    }
    
    /**
     * Inject the template components
     * manually
     *
     * @return self
     */
    public function setTemplate(array $partials): self
    {

        $html = '';
        $html .= $partials['design']['includes'];
        $html .= $partials['design']['header'];
        $html .= $partials['design']['body'];
        $html .= $partials['design']['footer'];

        @$this->document->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        return $this;

    }

    /**
     * Resolves the labels and values needed to replace the string
     * holders in the template.
     *
     * @return array
     */
    private function resolveHtmlEngine(array $data): array
    {
        return collect($data)->map(function ($value, $key) {
            
            $processed = [];

            if(in_array($key, ['tasks','projects','aging']) || !$value->first()) {
                return $processed;
            }

            match ($key) {
                'variables' => $processed = $value->first() ?? [],
                'invoices' => $processed = (new HtmlEngine($value->first()->invitations()->first()))->generateLabelsAndValues() ?? [],
                'quotes' => $processed = (new HtmlEngine($value->first()->invitations()->first()))->generateLabelsAndValues() ?? [],
                'credits' => $processed = (new HtmlEngine($value->first()->invitations()->first()))->generateLabelsAndValues() ?? [],
                'payments' => $processed = (new PaymentHtmlEngine($value->first(), $value->first()->client->contacts()->first()))->generateLabelsAndValues() ?? [],
                'tasks' => $processed = [],
                'projects' => $processed = [],
                'purchase_orders' => (new VendorHtmlEngine($value->first()->invitations()->first()))->generateLabelsAndValues() ?? [],
                'aging' => $processed = [],
                default => $processed = [],
            };
            
            return $processed;

        })->toArray();

    }

    private function preProcessDataBlocks($data): array
    {
        return collect($data)->map(function ($value, $key) {

            $processed = [];

            match ($key) {
                'invoices' => $processed = $this->processInvoices($value),
                'quotes' => $processed = $this->processQuotes($value),
                'credits' => $processed = $this->processCredits($value),
                'payments' => $processed = $this->processPayments($value),
                'tasks' => $processed = $this->processTasks($value),
                'projects' => $processed = $this->processProjects($value),
                'purchase_orders' => $processed = $this->processPurchaseOrders($value),
                'aging' => $processed = $value,
                default => $processed = [],
            };

            return $processed;

        })->toArray();
    }

    public function processInvoices($invoices): array
    {
        $invoices = collect($invoices)
                ->map(function ($invoice) {

                    $payments = [];
            
                    if($invoice->payments ?? false) {
                        $payments = $invoice->payments->map(function ($payment) {
                            return $this->transformPayment($payment);
                        })->toArray();
                    }

                    return [
                        'amount' => Number::formatMoney($invoice->amount, $invoice->client),
                        'balance' => Number::formatMoney($invoice->balance, $invoice->client),
                        'balance_raw' => $invoice->balance,
                        'number' => $invoice->number ?: '',
                        'discount' => $invoice->discount,
                        'po_number' => $invoice->po_number ?: '',
                        'date' => $this->translateDate($invoice->date, $invoice->client->date_format(), $invoice->client->locale()),
                        'last_sent_date' => $this->translateDate($invoice->last_sent_date, $invoice->client->date_format(), $invoice->client->locale()),
                        'next_send_date' => $this->translateDate($invoice->next_send_date, $invoice->client->date_format(), $invoice->client->locale()),
                        'due_date' => $this->translateDate($invoice->due_date, $invoice->client->date_format(), $invoice->client->locale()),
                        'terms' => $invoice->terms ?: '',
                        'public_notes' => $invoice->public_notes ?: '',
                        'private_notes' => $invoice->private_notes ?: '',
                        'uses_inclusive_taxes' => (bool) $invoice->uses_inclusive_taxes,
                        'tax_name1' => $invoice->tax_name1 ?? '',
                        'tax_rate1' => (float) $invoice->tax_rate1,
                        'tax_name2' => $invoice->tax_name2 ?? '',
                        'tax_rate2' => (float) $invoice->tax_rate2,
                        'tax_name3' => $invoice->tax_name3 ?? '',
                        'tax_rate3' => (float) $invoice->tax_rate3,
                        'total_taxes' => Number::formatMoney($invoice->total_taxes, $invoice->client),
                        'total_taxes_raw' => $invoice->total_taxes,
                        'is_amount_discount' => (bool) $invoice->is_amount_discount ?? false,
                        'footer' => $invoice->footer ?? '',
                        'partial' => $invoice->partial ?? 0,
                        'partial_due_date' => $this->translateDate($invoice->partial_due_date, $invoice->client->date_format(), $invoice->client->locale()),
                        'custom_value1' => (string) $invoice->custom_value1 ?: '',
                        'custom_value2' => (string) $invoice->custom_value2 ?: '',
                        'custom_value3' => (string) $invoice->custom_value3 ?: '',
                        'custom_value4' => (string) $invoice->custom_value4 ?: '',
                        'custom_surcharge1' => (float) $invoice->custom_surcharge1,
                        'custom_surcharge2' => (float) $invoice->custom_surcharge2,
                        'custom_surcharge3' => (float) $invoice->custom_surcharge3,
                        'custom_surcharge4' => (float) $invoice->custom_surcharge4,
                        'exchange_rate' => (float) $invoice->exchange_rate,
                        'custom_surcharge_tax1' => (bool) $invoice->custom_surcharge_tax1,
                        'custom_surcharge_tax2' => (bool) $invoice->custom_surcharge_tax2,
                        'custom_surcharge_tax3' => (bool) $invoice->custom_surcharge_tax3,
                        'custom_surcharge_tax4' => (bool) $invoice->custom_surcharge_tax4,
                        'line_items' => $invoice->line_items ? $this->padLineItems($invoice->line_items, $invoice->client): (array) [],
                        'reminder1_sent' => $this->translateDate($invoice->reminder1_sent, $invoice->client->date_format(), $invoice->client->locale()),
                        'reminder2_sent' => $this->translateDate($invoice->reminder2_sent, $invoice->client->date_format(), $invoice->client->locale()),
                        'reminder3_sent' => $this->translateDate($invoice->reminder3_sent, $invoice->client->date_format(), $invoice->client->locale()),
                        'reminder_last_sent' => $this->translateDate($invoice->reminder_last_sent, $invoice->client->date_format(), $invoice->client->locale()),
                        'paid_to_date' => Number::formatMoney($invoice->paid_to_date, $invoice->client),
                        'auto_bill_enabled' => (bool) $invoice->auto_bill_enabled,
                        'client' => [
                            'name' => $invoice->client->present()->name(),
                            'balance' => $invoice->client->balance,
                            'payment_balance' => $invoice->client->payment_balance,
                            'credit_balance' => $invoice->client->credit_balance,
                        ],
                        'payments' => $payments,
                        'total_tax_map' => $invoice->calc()->getTotalTaxMap(),
                        'line_tax_map' => $invoice->calc()->getTaxMap(),
                    ];

                });

        return $invoices->toArray();

    }

    public function padLineItems(array $items, Client $client): array
    {
        return collect($items)->map(function ($item) use ($client) {

            $item->cost_raw = $item->cost ?? 0;
            $item->discount_raw = $item->discount ?? 0;
            $item->line_total_raw = $item->line_total ?? 0;
            $item->gross_line_total_raw = $item->gross_line_total ?? 0;
            $item->tax_amount_raw = $item->tax_amount ?? 0;
            $item->product_cost_raw = $item->product_cost ?? 0;

            $item->cost = Number::formatMoney($item->cost_raw, $client);
            
            if($item->is_amount_discount) {
                $item->discount = Number::formatMoney($item->discount_raw, $client);
            }
            
            $item->line_total = Number::formatMoney($item->line_total_raw, $client);
            $item->gross_line_total = Number::formatMoney($item->gross_line_total_raw, $client);
            $item->tax_amount = Number::formatMoney($item->tax_amount_raw, $client);
            $item->product_cost = Number::formatMoney($item->product_cost_raw, $client);

            return $item;

        })->toArray();
    }

    private function transformPayment(Payment $payment): array
    {

        $data = [];
                
        $credits = $payment->credits->map(function ($credit) use ($payment) {
            return [
                'credit' => $credit->number,
                'amount_raw' => $credit->pivot->amount,
                'refunded_raw' => $credit->pivot->refunded,
                'net_raw' => $credit->pivot->amount - $credit->pivot->refunded,
                'amount' => Number::formatMoney($credit->pivot->amount, $payment->client),
                'refunded' => Number::formatMoney($credit->pivot->refunded, $payment->client),
                'net' => Number::formatMoney($credit->pivot->amount - $credit->pivot->refunded, $payment->client),
                'is_credit' => true,
                'date' => $this->translateDate($credit->date, $payment->client->date_format(), $payment->client->locale()),
                'created_at' => $this->translateDate($credit->pivot->created_at, $payment->client->date_format(), $payment->client->locale()),
                'updated_at' => $this->translateDate($credit->pivot->updated_at, $payment->client->date_format(), $payment->client->locale()),
                'timestamp' => $credit->pivot->created_at->timestamp,
            ];
        });

        $pivot = $payment->invoices->map(function ($invoice) use ($payment) {
            return [
                'invoice' => $invoice->number,
                'amount_raw' => $invoice->pivot->amount,
                'refunded_raw' => $invoice->pivot->refunded,
                'net_raw' => $invoice->pivot->amount - $invoice->pivot->refunded,
                'amount' => Number::formatMoney($invoice->pivot->amount, $payment->client),
                'refunded' => Number::formatMoney($invoice->pivot->refunded, $payment->client),
                'net' => Number::formatMoney($invoice->pivot->amount - $invoice->pivot->refunded, $payment->client),
                'is_credit' => false,
                'date' => $this->translateDate($invoice->date, $payment->client->date_format(), $payment->client->locale()),
                'created_at' => $this->translateDate($invoice->pivot->created_at, $payment->client->date_format(), $payment->client->locale()),
                'updated_at' => $this->translateDate($invoice->pivot->updated_at, $payment->client->date_format(), $payment->client->locale()),
                'timestamp' => $invoice->pivot->created_at->timestamp,
            ];
        })->merge($credits)->sortBy('timestamp')->toArray();

        return [
            'status' => $payment->stringStatus($payment->status_id),
            'badge' => $payment->badgeForStatus($payment->status_id),
            'amount' => Number::formatMoney($payment->amount, $payment->client),
            'applied' => Number::formatMoney($payment->applied, $payment->client),
            'balance' => Number::formatMoney(($payment->amount - $payment->refunded - $payment->applied), $payment->client),
            'refunded' => Number::formatMoney($payment->refunded, $payment->client),
            'amount_raw' => $payment->amount,
            'applied_raw' => $payment->applied,
            'refunded_raw' => $payment->refunded,
            'balance_raw' => ($payment->amount - $payment->refunded - $payment->applied),
            'date' => $this->translateDate($payment->date, $payment->client->date_format(), $payment->client->locale()),
            'method' => $payment->translatedType(),
            'currency' => $payment->currency->code ?? $payment->company->currency()->code,
            'exchange_rate' => $payment->exchange_rate,
            'transaction_reference' => $payment->transaction_reference,
            'is_manual' => $payment->is_manual,
            'number' => $payment->number,
            'custom_value1' => $payment->custom_value1 ?? '',
            'custom_value2' => $payment->custom_value2 ?? '',
            'custom_value3' => $payment->custom_value3 ?? '',
            'custom_value4' => $payment->custom_value4 ?? '',
            'created_at' => $this->translateDate($payment->created_at, $payment->client->date_format(), $payment->client->locale()),
            'updated_at' => $this->translateDate($payment->updated_at, $payment->client->date_format(), $payment->client->locale()),
            'client' => [
                'name' => $payment->client->present()->name(),
                'balance' => $payment->client->balance,
                'payment_balance' => $payment->client->payment_balance,
                'credit_balance' => $payment->client->credit_balance,
            ],
            'paymentables' => $pivot,
            'refund_activity' => $this->getPaymentRefundActivity($payment),
        ];

        nlog($data);

        return $data;

    }

    /**
     *  [
      "id" => 12,
      "date" => "2023-10-08",
      "invoices" => [
        [
          "amount" => 1,
          "invoice_id" => 23,
          "id" => null,
        ],
      ],
      "q" => "/api/v1/payments/refund",
      "email_receipt" => "true",
      "gateway_refund" => false,
      "send_email" => false,
    ],
     *
     * @param Payment $payment
     * @return array
     */
    private function getPaymentRefundActivity(Payment $payment): array
    {

        return collect($payment->refund_meta ?? [])
        ->map(function ($refund) use ($payment) {

            $date = \Carbon\Carbon::parse($refund['date'])->addSeconds($payment->client->timezone_offset());
            $date = $this->translateDate($date, $payment->client->date_format(), $payment->client->locale());
            $entity = ctrans('texts.invoice');

            $map = [];

            foreach($refund['invoices'] as $refunded_invoice) {
                $invoice = Invoice::withTrashed()->find($refunded_invoice['invoice_id']);
                $amount = Number::formatMoney($refunded_invoice['amount'], $payment->client);
                $notes = ctrans('texts.status_partially_refunded_amount', ['amount' => $amount]);

                array_push($map, "{$date} {$entity} #{$invoice->number} {$notes}\n");

            }

            return $map;

        })->flatten()->toArray();

    }

    public function processQuotes($quotes): array
    {
        $it = new QuoteTransformer();
        $it->setDefaultIncludes(['client']);
        $manager = new Manager();
        $manager->parseIncludes(['client']);
        $resource = new \League\Fractal\Resource\Collection($quotes, $it, null);
        $resources = $manager->createData($resource)->toArray();

        foreach($resources['data'] as $key => $resource) {

            $resources['data'][$key]['client'] = $resource['client']['data'] ?? [];
            $resources['data'][$key]['client']['contacts'] = $resource['client']['data']['contacts']['data'] ?? [];
            
        }

        return $resources['data'];

    }
    
    /**
     * Pushes credits through the appropriate transformer
     * and builds any required relationships
     *
     * @param  mixed $credits
     * @return array
     */
    public function processCredits($credits): array
    {
        $credits = collect($credits)
                ->map(function ($credit) {

                    return [
                        'amount' => Number::formatMoney($credit->amount, $credit->client),
                        'balance' => Number::formatMoney($credit->balance, $credit->client),
                        'balance_raw' => $credit->balance,
                        'number' => $credit->number ?: '',
                        'discount' => $credit->discount,
                        'po_number' => $credit->po_number ?: '',
                        'date' => $this->translateDate($credit->date, $credit->client->date_format(), $credit->client->locale()),
                        'last_sent_date' => $this->translateDate($credit->last_sent_date, $credit->client->date_format(), $credit->client->locale()),
                        'next_send_date' => $this->translateDate($credit->next_send_date, $credit->client->date_format(), $credit->client->locale()),
                        'due_date' => $this->translateDate($credit->due_date, $credit->client->date_format(), $credit->client->locale()),
                        'terms' => $credit->terms ?: '',
                        'public_notes' => $credit->public_notes ?: '',
                        'private_notes' => $credit->private_notes ?: '',
                        'uses_inclusive_taxes' => (bool) $credit->uses_inclusive_taxes,
                        'tax_name1' => $credit->tax_name1 ?? '',
                        'tax_rate1' => (float) $credit->tax_rate1,
                        'tax_name2' => $credit->tax_name2 ?? '',
                        'tax_rate2' => (float) $credit->tax_rate2,
                        'tax_name3' => $credit->tax_name3 ?? '',
                        'tax_rate3' => (float) $credit->tax_rate3,
                        'total_taxes' => Number::formatMoney($credit->total_taxes, $credit->client),
                        'total_taxes_raw' => $credit->total_taxes,
                        'is_amount_discount' => (bool) $credit->is_amount_discount ?? false,
                        'footer' => $credit->footer ?? '',
                        'partial' => $credit->partial ?? 0,
                        'partial_due_date' => $this->translateDate($credit->partial_due_date, $credit->client->date_format(), $credit->client->locale()),
                        'custom_value1' => (string) $credit->custom_value1 ?: '',
                        'custom_value2' => (string) $credit->custom_value2 ?: '',
                        'custom_value3' => (string) $credit->custom_value3 ?: '',
                        'custom_value4' => (string) $credit->custom_value4 ?: '',
                        'custom_surcharge1' => (float) $credit->custom_surcharge1,
                        'custom_surcharge2' => (float) $credit->custom_surcharge2,
                        'custom_surcharge3' => (float) $credit->custom_surcharge3,
                        'custom_surcharge4' => (float) $credit->custom_surcharge4,
                        'exchange_rate' => (float) $credit->exchange_rate,
                        'custom_surcharge_tax1' => (bool) $credit->custom_surcharge_tax1,
                        'custom_surcharge_tax2' => (bool) $credit->custom_surcharge_tax2,
                        'custom_surcharge_tax3' => (bool) $credit->custom_surcharge_tax3,
                        'custom_surcharge_tax4' => (bool) $credit->custom_surcharge_tax4,
                        'line_items' => $credit->line_items ? $this->padLineItems($credit->line_items, $credit->client): (array) [],
                        'reminder1_sent' => $this->translateDate($credit->reminder1_sent, $credit->client->date_format(), $credit->client->locale()),
                        'reminder2_sent' => $this->translateDate($credit->reminder2_sent, $credit->client->date_format(), $credit->client->locale()),
                        'reminder3_sent' => $this->translateDate($credit->reminder3_sent, $credit->client->date_format(), $credit->client->locale()),
                        'reminder_last_sent' => $this->translateDate($credit->reminder_last_sent, $credit->client->date_format(), $credit->client->locale()),
                        'paid_to_date' => Number::formatMoney($credit->paid_to_date, $credit->client),
                        'auto_bill_enabled' => (bool) $credit->auto_bill_enabled,
                        'client' => [
                            'name' => $credit->client->present()->name(),
                            'balance' => $credit->client->balance,
                            'payment_balance' => $credit->client->payment_balance,
                            'credit_balance' => $credit->client->credit_balance,
                        ],
                        'payments' => [],
                        'total_tax_map' => $credit->calc()->getTotalTaxMap(),
                        'line_tax_map' => $credit->calc()->getTaxMap(),
                    ];

                });

        return $credits->toArray();

    }



    /**
     * Pushes payments through the appropriate transformer
     *
     * @param  mixed $payments
     * @return array
     */
    public function processPayments($payments): array
    {

        $payments = collect($payments)->map(function ($payment) {
            return $this->transformPayment($payment);
        })->toArray();
        
        return $payments;


    }

    public function processTasks($tasks): array
    {
        $it = new TaskTransformer();
        $it->setDefaultIncludes(['client','project','invoice']);
        $manager = new Manager();
        $resource = new \League\Fractal\Resource\Collection($tasks, $it, null);
        $resources = $manager->createData($resource)->toArray();

        foreach($resources['data'] as $key => $resource) {

            $resources['data'][$key]['client'] = $resource['client']['data'] ?? [];
            $resources['data'][$key]['client']['contacts'] = $resource['client']['data']['contacts']['data'] ?? [];
            $resources['data'][$key]['project'] = $resource['project']['data'] ?? [];
            $resources['data'][$key]['invoice'] = $resource['invoice'] ?? [];
                    
        }

        return $resources['data'];


    }

    public function processProjects($projects): array
    {

        $it = new ProjectTransformer();
        $it->setDefaultIncludes(['client','tasks']);
        $manager = new Manager();
        $manager->setSerializer(new ArraySerializer());
        $resource = new \League\Fractal\Resource\Collection($projects, $it, Project::class);
        $i = $manager->createData($resource)->toArray();
        return $i[Project::class];

    }

    public function processPurchaseOrders($purchase_orders): array
    {
        
        $it = new PurchaseOrderTransformer();
        $it->setDefaultIncludes(['vendor','expense']);
        $manager = new Manager();
        $manager->setSerializer(new ArraySerializer());
        $resource = new \League\Fractal\Resource\Collection($purchase_orders, $it, PurchaseOrder::class);
        $i = $manager->createData($resource)->toArray();
        return $i[PurchaseOrder::class];

    }

    public function setCompany(Company $company): self
    {
        $this->company = $company;
        
        return $this;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function overrideVariables($variables): self
    {
        $this->variables = $variables;
        
        return $this;
    }

}
