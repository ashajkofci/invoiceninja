<?php

namespace App\Import\Providers;

use App\Models\Invoice;
use Illuminate\Support\Facades\Cache;
use App\Services\Quickbooks\QuickbooksService;
use App\Services\Quickbooks\Transformers\ClientTransformer;
use App\Services\Quickbooks\Transformers\PaymentTransformer;

class QBBackup extends BaseImport implements ImportInterface
{

    private array $qb_data = [];

    private QuickbooksService $qb;

    public function __construct(array $request, Company $company)
    {
        parent::__construct($request, $company);

        $_base64_encoded_qb_data = Cache::get($this->request['hash'].'-qb_backup');
        $qb_json = base64_decode($_base64_encoded_qb_data);
        $this->qb_data = json_decode($qb_json, true);

        $this->qb = new QuickbooksService($this->company);
        
    }

    public function import(string $entity)
    {
        if (in_array($entity, ['client', 'invoice', 'product', 'payment', 'vendor', 'expense'])) {
            $this->{$entity}();
        }
    }

    public function transform(array $data)
    {
    }

    public function client()
    {
        $this->qb->client->importToNinja($this->qb_data['clients']);
    }

    public function product()
    {
        $this->qb->product->importToNinja($this->qb_data['products']);
    }

    public function invoice()
    {
        $this->qb->invoice->importToNinja($this->qb_data['invoices']);
    }

    public function payment()
    {

        $payments = isset($this->qb_data['payments']) && is_array($this->qb_data['payments']) ? $this->qb_data['payments'] : [];

            foreach ($payments as $payment) {

                $payment_transformer = new PaymentTransformer($this->company);

                $transformed = $payment_transformer->qbToNinja($payment);

                $ninja_payment = $payment_transformer->buildPayment($payment);
                $ninja_payment->service()->applyNumber()->save();

                
                $invoice = Invoice::query()
                        ->withTrashed()
                        ->where('company_id', $this->company->id)
                        ->where('sync->qb_id', $payment['invoice_id'])
                        ->first();

                if ($invoice) {

                    $paymentable = new \App\Models\Paymentable();
                    $paymentable->payment_id = $ninja_payment->id;
                    $paymentable->paymentable_id = $invoice->id;
                    $paymentable->paymentable_type = 'invoices';
                    $paymentable->amount = $transformed['applied'] + $ninja_payment->credits->sum('amount');
                    $paymentable->created_at = $ninja_payment->date; //@phpstan-ignore-line
                    $paymentable->save();

                    $invoice->service()->applyPayment($ninja_payment, $paymentable->amount);

                }

            }

    }

    public function vendor()
    {

    }

    public function expense()
    {

    }
}