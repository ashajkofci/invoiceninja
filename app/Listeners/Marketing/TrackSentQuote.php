<?php
namespace App\Listeners\Marketing;

use App\Events\Quote\{QuoteWasEmailed, QuoteWasMarkedSent};
use App\Models\Company;
use App\Services\Marketing\MarketingService;
use Illuminate\Support\Facades\Schema;

class TrackSentQuote
{
    public function handle(QuoteWasEmailed|QuoteWasMarkedSent $event): void
    {
        // Marketing must never prevent the original quote from being delivered.
        try {
            $company = $event->company;
            if ($company->is_disabled || !($company->enabled_modules & Company::MODULE_MARKETING) || !Schema::hasTable('marketing_settings')) { return; }
            $service = new MarketingService($company);
            if (!$service->config()['auto_create_quotes']) { return; }
            $quote = $event instanceof QuoteWasEmailed ? $event->invitation->quote : $event->quote;
            if (!$quote || $quote->is_deleted) { return; }
            $service->fromQuote($quote, null, $event instanceof QuoteWasEmailed ? $event->invitation->client_contact_id : null);
        } catch (\Throwable $error) { report($error); }
    }
}
