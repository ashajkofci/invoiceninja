<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Jobs\Util;

use App\Libraries\MultiDB;
use App\Models\BankIntegration;
use App\Models\BankTransaction;
use App\Models\BankTransactionRule;
use App\Models\Client;
use App\Models\ClientGatewayToken;
use App\Models\CompanyGateway;
use App\Models\CompanyToken;
use App\Models\Credit;
use App\Models\Design;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GroupSetting;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\RecurringExpense;
use App\Models\RecurringInvoice;
use App\Models\RecurringQuote;
use App\Models\Scheduler;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Webhook;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PurgeDeletedEntities implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CHUNK_SIZE = 1000;

    /**
     * Child records are listed before their parents so foreign-key cascades do as
     * little work as possible.
     *
     * @var array<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private const MODELS = [
        BankTransactionRule::class,
        ClientGatewayToken::class,
        CompanyGateway::class,
        CompanyToken::class,
        Credit::class,
        Expense::class,
        Payment::class,
        PurchaseOrder::class,
        Quote::class,
        Task::class,
        Invoice::class,
        RecurringExpense::class,
        RecurringInvoice::class,
        RecurringQuote::class,
        BankTransaction::class,
        Product::class,
        Project::class,
        Scheduler::class,
        Subscription::class,
        Webhook::class,
        BankIntegration::class,
        Design::class,
        ExpenseCategory::class,
        GroupSetting::class,
        Location::class,
        PaymentTerm::class,
        TaskStatus::class,
        TaxRate::class,
        Vendor::class,
        Client::class,
        User::class,
    ];

    /**
     * @param array<class-string<\Illuminate\Database\Eloquent\Model>>|null $models
     */
    public function __construct(private ?array $models = null)
    {
    }

    public function handle(): void
    {
        $cutoff = now()->subYearsNoOverflow(3);
        $current_database = config('database.default');
        $databases = config('ninja.db.multi_db_enabled') ? MultiDB::$dbs : [$current_database];
        $deleted = 0;

        try {
            foreach ($databases as $database) {
                if (config('ninja.db.multi_db_enabled')) {
                    MultiDB::setDB($database);
                }

                $deleted += $this->purgeDatabase($cutoff);
            }
        } finally {
            if (config('ninja.db.multi_db_enabled')) {
                MultiDB::setDB($current_database);
            }
        }

        nlog("Permanently deleted {$deleted} entities erased on or before {$cutoff->toDateTimeString()}");
    }

    private function purgeDatabase(CarbonInterface $cutoff): int
    {
        $deleted = 0;

        foreach ($this->models ?? self::MODELS as $model) {
            $instance = new $model();
            $key = $instance->getKeyName();

            $query = $model::query()
                ->onlyTrashed()
                ->where('is_deleted', true)
                ->where('deleted_at', '<=', $cutoff)
                ->select($key);

            $query->setEagerLoads([]);

            $query->chunkById(self::CHUNK_SIZE, function ($entities) use ($model, $key, $cutoff, &$deleted) {
                $deleted += $model::query()
                    ->withTrashed()
                    ->whereIn($key, $entities->modelKeys())
                    ->where('is_deleted', true)
                    ->where('deleted_at', '<=', $cutoff)
                    ->forceDelete();
            }, $key);
        }

        return $deleted;
    }
}
