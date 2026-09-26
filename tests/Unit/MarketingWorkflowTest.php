<?php
namespace Tests\Unit;

use App\Http\Controllers\{MarketingController, MarketingUnsubscribeController};
use App\Models\{Company, Client, ClientContact, MarketingOpportunity, MarketingActivity, User};
use App\Services\Marketing\{MarketingConfig, MarketingService};
use App\Services\Email\Email;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema, Crypt};
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class MarketingWorkflowTest extends TestCase
{
    private Company $company;
    private MarketingService $service;
    private MarketingOpportunity $opportunity;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','ninja.environment'=>'selfhost','ninja.db.multi_db_enabled'=>false,'app.key'=>'base64:'.base64_encode(str_repeat('m',32))]);
        DB::purge('sqlite');
        Carbon::setTestNow('2026-09-28 09:00:00');
        Schema::create('companies',function(Blueprint $t){$t->id();$t->json('settings');$t->integer('enabled_modules');$t->boolean('is_disabled')->default(false);$t->string('db')->nullable();$t->string('company_key')->default('test');$t->string('portal_mode')->default('subdomain');$t->string('portal_domain')->nullable();$t->timestamps();$t->softDeletes();});
        Schema::create('clients',function(Blueprint $t){$t->id();$t->integer('company_id');$t->string('name');$t->json('settings')->nullable();$t->integer('group_settings_id')->nullable();$t->boolean('is_deleted')->default(false);$t->timestamps();$t->softDeletes();});
        Schema::create('client_contacts',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('client_id');$t->string('first_name');$t->string('last_name');$t->string('email');$t->boolean('send_email')->default(true);$t->boolean('is_locked')->default(false);$t->timestamps();$t->softDeletes();});
        Schema::create('currencies',function(Blueprint $t){$t->id();$t->string('code');});
        DB::table('currencies')->insert(['id'=>1,'code'=>'CHF']);
        Schema::create('quotes',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('client_id');$t->integer('status_id');$t->boolean('is_deleted')->default(false);$t->string('number');$t->date('due_date')->nullable();$t->timestamps();$t->softDeletes();});
        Schema::create('quote_invitations',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('quote_id');$t->integer('client_contact_id');$t->string('key');$t->timestamps();$t->softDeletes();});
        (require database_path('migrations/2026_09_26_000000_create_marketing_module.php'))->up();
        Model::withoutEvents(function(){
            $this->company=Company::forceCreate(['settings'=>(object)['name'=>'Example company','currency_id'=>1,'send_reminders'=>true,'enable_quote_reminder1'=>false],'enabled_modules'=>Company::MODULE_MARKETING]);
            Client::forceCreate(['company_id'=>$this->company->id,'name'=>'Example client','settings'=>(object)[]]);
            DB::table('client_contacts')->insert(['company_id'=>$this->company->id,'client_id'=>1,'first_name'=>'Ada','last_name'=>'Example','email'=>'ada@example.test','send_email'=>true]);
        });
        $this->service=new MarketingService($this->company);
        $this->opportunity=$this->service->opportunities()->create(['company_id'=>$this->company->id,'client_id'=>1,'contact_id'=>1,'owner_id'=>1,'title'=>'New opportunity','stage_id'=>'new','currency_id'=>1,'amount'=>1500,'consent'=>true,'consent_source'=>'Requested newsletter on website','automatic'=>true]);
    }
    protected function tearDown(): void { Carbon::setTestNow();parent::tearDown(); }
    private function config(array $changes=[]): array
    {
        $config=array_replace(MarketingConfig::defaults(),$changes);
        DB::table('marketing_settings')->updateOrInsert(['company_id'=>$this->company->id],['config'=>json_encode($config),'revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        return $config;
    }
    private function activity(string $template='welcome_en'): MarketingActivity
    {
        return $this->service->activities()->create(['company_id'=>$this->company->id,'opportunity_id'=>$this->opportunity->id,'user_id'=>1,'title'=>'Follow-up','kind'=>'email','template_id'=>$template,'state'=>'pending','due_at'=>now()]);
    }
    private function fakeTransport(bool $accepted=true): void
    {
        $mail=Mockery::mock(Email::class)->makePartial();
        $mail->delivered=$accepted;
        $mail->shouldReceive('handle')->once();
        $this->app->bind(Email::class,fn()=>$mail);
    }
    public function test_defaults_are_valid_translated_and_every_sequence_can_resolve_its_templates(): void
    {
        $config=MarketingConfig::validate(MarketingConfig::defaults());
        $this->assertCount(36,$config['templates']);
        foreach(['en','fr','de'] as $locale){$this->assertCount(12,array_filter($config['templates'],fn($t)=>$t['locale']===$locale));}
        $this->assertFalse($config['automatic']);
        $this->assertTrue($config['require_consent']);
        $config['sequences'][0]['steps'][0]['template_id']='missing';
        $this->expectException(ValidationException::class);MarketingConfig::validate($config);
    }
    public function test_company_isolation_and_sequence_enrollment_are_persistent_and_idempotent(): void
    {
        $this->service->enroll($this->opportunity,'discovery_en',1);
        $this->service->enroll($this->opportunity->fresh(),'discovery_en',1);
        $this->assertSame(3,$this->service->activities()->count());
        $other=new Company();$other->id=999;
        $this->assertSame(0,(new MarketingService($other))->activities()->count());
        $this->assertSame(0,(new MarketingService($other))->opportunities()->count());
        $this->service->cancelPending($this->opportunity,'Closed');
        $this->assertSame(3,$this->service->activities()->where('state','cancelled')->count());
    }
    public function test_manual_send_records_snapshot_and_does_not_send_twice(): void
    {
        $this->fakeTransport();$activity=$this->activity();
        $preview=$this->service->preview($activity);
        $this->assertStringContainsString('Ada Example',$preview['body']);
        $this->assertStringContainsString('Unsubscribe',$preview['body']);
        $this->service->deliver($activity);
        $this->service->deliver($activity->fresh());
        $this->assertSame('sent',$activity->fresh()->state);
        $this->assertSame('ada@example.test',$activity->fresh()->snapshot['to']);
    }
    public function test_a_claimed_send_is_never_reentered(): void
    {
        $activity=$this->activity();$activity->update(['state'=>'sending']);
        $this->app->bind(Email::class,fn()=>throw new \RuntimeException('Must not send'));
        $this->service->deliver($activity);
        $this->assertSame('sending',$activity->fresh()->state);
    }
    public function test_automatic_send_respects_company_switch_opportunity_switch_and_sending_window(): void
    {
        $activity=$this->activity();$this->service->deliver($activity,true);
        $this->assertSame('pending',$activity->fresh()->state);
        $this->config(['automatic'=>true]);$this->opportunity->update(['automatic'=>false]);
        $this->service->deliver($activity,true);$this->assertSame('pending',$activity->fresh()->state);
        $this->opportunity->update(['automatic'=>true]);Carbon::setTestNow('2026-09-27 09:00:00');
        $this->service->deliver($activity,true);$this->assertSame('pending',$activity->fresh()->state);
        Carbon::setTestNow('2026-09-28 09:00:00');$this->fakeTransport();
        $this->service->deliver($activity,true);$this->assertSame('sent',$activity->fresh()->state);
    }
    public function test_marketing_consent_is_required(): void
    {
        $this->opportunity->update(['consent'=>false]);
        $this->expectException(ValidationException::class);$this->service->deliver($this->activity());
    }
    public function test_unsubscribe_blocks_existing_and_future_opportunities_for_same_email(): void
    {
        DB::table('marketing_suppressions')->insert(['company_id'=>$this->company->id,'email'=>'ada@example.test','created_at'=>now()]);
        $this->expectException(ValidationException::class);$this->service->deliver($this->activity());
    }
    public function test_transport_failure_is_not_reported_as_sent_or_automatically_retried(): void
    {
        $this->fakeTransport(false);$activity=$this->activity();
        $this->service->deliver($activity);$this->service->deliver($activity->fresh(),true);
        $this->assertSame('failed',$activity->fresh()->state);$this->assertNull($activity->fresh()->sent_at);
    }
    public function test_interval_applies_across_opportunities_for_same_contact(): void
    {
        $sent=$this->activity();$sent->update(['state'=>'sent','sent_at'=>now()]);
        $this->expectException(ValidationException::class);$this->service->deliver($this->activity());
    }
    public function test_quote_approval_closes_pipeline_and_cancels_pending_followups(): void
    {
        DB::table('quotes')->insert(['id'=>1,'company_id'=>$this->company->id,'client_id'=>1,'status_id'=>3,'number'=>'Q-001']);
        $this->opportunity->update(['quote_id'=>1]);$activity=$this->activity();
        $this->service->deliver($activity);
        $this->assertSame('won',$this->opportunity->fresh()->stage_id);
        $this->assertSame('cancelled',$activity->fresh()->state);
    }
    public function test_unsubscribe_get_is_non_mutating_and_post_persists(): void
    {
        $token=Crypt::encryptString(json_encode(['company_id'=>$this->company->id,'email'=>'ada@example.test','locale'=>'fr']));
        $controller=new MarketingUnsubscribeController();
        $response=$controller(Request::create('/marketing/unsubscribe','GET',['token'=>$token]));
        $this->assertStringContainsString('Se désinscrire',$response->getContent());
        $this->assertSame(0,DB::table('marketing_suppressions')->count());
        $controller(Request::create('/marketing/unsubscribe','POST',['token'=>$token]));
        $this->assertSame(1,DB::table('marketing_suppressions')->count());
    }
}
