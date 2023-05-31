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

namespace App\Jobs\Client;

use App\Models\Client;
use App\Models\Company;
use App\Libraries\MultiDB;
use Illuminate\Bus\Queueable;
use App\DataProviders\USStates;
use App\Utils\Traits\MakesHash;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class UpdateTaxData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use MakesHash;

    public $tries = 1;

    /**
     * Create a new job instance.
     *
     * @param Client $client
     * @param Company $company
     */
    public function __construct(public Client $client, protected Company $company)
    {
    }

    /**
     * Execute the job.
     *
     */
    public function handle()
    {
        MultiDB::setDb($this->company->db);

        if(!config('services.tax.zip_tax.key'))
            return;

        $tax_provider = new \App\Services\Tax\Providers\TaxProvider($this->company, $this->client);
        
        try {
            
            $tax_provider->updateClientTaxData();
        
            if (!$this->client->state && $this->client->postal_code) {

                $this->client->state = USStates::getState($this->client->postal_code);
                
                $this->client->save();

            }

        
        }catch(\Exception $e){
            nlog("problem getting tax data => ".$e->getMessage());
        }

    }

    public function middleware()
    {
        return [new WithoutOverlapping($this->company->id)];
    }

}