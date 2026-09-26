<?php
namespace App\Console\Commands;
use App\Libraries\MultiDB;
use App\Models\Company;
use App\Services\Marketing\MarketingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
class MarketingRun extends Command
{
    protected $signature = 'marketing:run';
    protected $description = 'Synchronize opportunity outcomes and send eligible marketing follow-ups';
    public function handle(): int
    {
        $databases = config('ninja.db.multi_db_enabled') ? MultiDB::$dbs : [null];
        foreach ($databases as $db) {
            if ($db) { MultiDB::setDb($db); }
            if (!Schema::hasTable('marketing_settings')) { continue; }
            Company::where('is_disabled',false)->whereRaw('(enabled_modules & ?) != 0',[Company::MODULE_MARKETING])->each(function ($company) {
                (new MarketingService($company))->run();
            });
        }
        return self::SUCCESS;
    }
}
