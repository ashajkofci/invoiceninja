<?php
namespace Tests\Feature;

use App\DataMapper\CompanySettings;
use App\Factory\CompanyUserFactory;
use App\Models\{Account,Company,User,CompanyToken,Client,ClientContact,Quote,QuoteInvitation};
use App\Services\Marketing\MarketingConfig;
use App\Services\Email\EmailMailable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{DB,Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

/** Run only against the disposable database documented in docs/marketing.md. */
class MarketingApiTest extends TestCase
{
    private array $first;
    private array $second;
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('MARKETING_MYSQL') !== '1') { $this->markTestSkipped('Requires the disposable marketing_test MySQL database.'); }
        config(['database.default'=>'mysql','ninja.db.default'=>'mysql','ninja.environment'=>'selfhost','ninja.db.multi_db_enabled'=>false,
            'database.connections.mysql.host'=>'127.0.0.1','database.connections.mysql.port'=>(int)(getenv('MARKETING_DB_PORT') ?: 13309),
            'database.connections.mysql.database'=>'marketing_test','database.connections.mysql.username'=>'root','database.connections.mysql.password'=>'marketing_test_only',
            'app.key'=>'base64:'.base64_encode(str_repeat('m',32)),'cache.default'=>'array','mail.default'=>'array']);
        DB::purge('mysql');
        DB::beginTransaction();
        Mail::fake();
        $this->first=$this->companyFixture();$this->second=$this->companyFixture();
        $this->withHeaders(['X-API-TOKEN'=>$this->first['token'],'X-API-SECRET'=>config('ninja.api_secret')]);
    }
    protected function tearDown(): void
    {
        if (isset($this->first)) { DB::rollBack(); }
        parent::tearDown();
    }
    private function companyFixture(): array
    {
        return Model::withoutEvents(function(){
            $account=Account::factory()->create();
            $company=Company::factory()->create(['account_id'=>$account->id,'enabled_modules'=>Company::MODULE_MARKETING,'is_disabled'=>false]);
            $settings=CompanySettings::defaults();$settings->name='Marketing test company';$settings->currency_id='1';$settings->email_sending_method='default';$settings->send_reminders=true;$settings->enable_quote_reminder1=false;
            $company->settings=$settings;$company->save();
            $user=User::factory()->create(['account_id'=>$account->id,'email'=>Str::uuid().'@example.test']);
            $cu=CompanyUserFactory::create($user->id,$company->id,$account->id);$cu->is_owner=true;$cu->is_admin=true;$cu->is_locked=false;$cu->save();
            $token=Str::random(64);
            $ct=new CompanyToken();$ct->forceFill(['user_id'=>$user->id,'company_id'=>$company->id,'account_id'=>$account->id,'name'=>'marketing regression','token'=>$token,'is_system'=>true]);$ct->save();
            $client=Client::factory()->create(['user_id'=>$user->id,'company_id'=>$company->id]);
            $contact=ClientContact::factory()->create(['user_id'=>$user->id,'company_id'=>$company->id,'client_id'=>$client->id,'email'=>'marketing@example.test','send_email'=>true,'is_locked'=>false,'is_primary'=>true]);
            return compact('company','user','cu','token','client','contact');
        });
    }
    private function payload(): array
    {
        return ['title'=>'Persisted sales workflow','client_id'=>$this->first['client']->hashed_id,'contact_id'=>$this->first['contact']->hashed_id,'quote_id'=>'','owner_id'=>$this->first['user']->hashed_id,'stage_id'=>'qualified','amount'=>1250,'currency_id'=>1,'expected_close'=>'2026-12-31','source'=>'website','campaign_id'=>'','locale'=>'fr','notes'=>'Integration regression','lost_reason'=>'','consent'=>true,'consent_source'=>'Contact requested a follow-up','automatic'=>false,'archived'=>false];
    }
    public function test_company_module_workflow_settings_persistence_email_and_stale_edits(): void
    {
        $bootstrap=$this->getJson('/api/v1/marketing/bootstrap?language=fr')->assertOk()->json('data');
        $this->assertSame('Marketing et ventes',$bootstrap['labels']['marketing']);
        $this->assertCount(1,$bootstrap['options']['clients']);
        $settings=$bootstrap['config'];$settings['daily_limit']=12;
        $this->putJson('/api/v1/marketing/settings',['revision'=>0,'config'=>$settings])->assertOk()->assertJsonPath('data.config.daily_limit',12);
        $this->putJson('/api/v1/marketing/settings',['revision'=>0,'config'=>$settings])->assertStatus(409);
        $opportunity=$this->postJson('/api/v1/marketing/opportunities',$this->payload())->assertOk()->json('data');
        $id=$opportunity['id'];
        $forecast=$this->getJson('/api/v1/marketing/bootstrap')->assertOk()->json('data.forecast.0');
        $probability=collect($settings['stages'])->firstWhere('id','qualified')['probability'];
        $this->assertEquals(1250,$forecast['open']);
        $this->assertEquals(1250*$probability/100,$forecast['weighted']);
        $this->getJson('/api/v1/marketing/opportunities')->assertOk()->assertJsonPath('data.0.id',$id);
        $this->putJson('/api/v1/marketing/opportunities/'.$id,[...$opportunity,'title'=>'Edited opportunity'])->assertOk()->assertJsonPath('data.revision',2);
        $this->putJson('/api/v1/marketing/opportunities/'.$id,$opportunity)->assertStatus(409);
        $this->postJson('/api/v1/marketing/bulk_enroll',['ids'=>[$id],'sequence'=>'discovery_fr'])->assertOk();
        $this->postJson('/api/v1/marketing/bulk_enroll',['ids'=>[$id],'sequence'=>'discovery_fr'])->assertOk();
        $activities=$this->getJson('/api/v1/marketing/activities?opportunity_id='.$id)->assertOk()->json('data');
        $this->assertCount(5,$activities); // creation and edit audit notes + three sequence steps
        $activity=collect($activities)->firstWhere('template_id','welcome_fr');
        $preview=$this->postJson('/api/v1/marketing/activities/'.$activity['id'].'/preview')->assertOk()->json('data');
        $this->assertStringContainsString('Bonjour',$preview['body']);
        $this->postJson('/api/v1/marketing/activities/'.$activity['id'].'/send',['revision'=>$activity['revision'],'preview_version'=>str_repeat('0',64)])->assertStatus(409);
        $this->postJson('/api/v1/marketing/activities/'.$activity['id'].'/send',['revision'=>$activity['revision'],'preview_version'=>$preview['version']])->assertOk()->assertJsonPath('data.state','sent');
        Mail::assertSent(EmailMailable::class,function ($mail) { $this->assertStringContainsString('Bonjour',$mail->render()); return true; });
        $this->postJson('/api/v1/marketing/activities/'.$activity['id'].'/send',['revision'=>$activity['revision'],'preview_version'=>$preview['version']])->assertStatus(409);
        Mail::assertSent(EmailMailable::class,1);
        $this->withHeaders(['X-API-TOKEN'=>$this->second['token']]);
        $this->getJson('/api/v1/marketing/opportunities')->assertOk()->assertJsonCount(0,'data');
        $this->postJson('/api/v1/marketing/opportunities/'.$id.'/enroll',['sequence'=>'discovery_en'])->assertStatus(400);
        $this->postJson('/api/v1/marketing/activities/'.$activity['id'].'/preview')->assertStatus(400);
        $this->postJson('/api/v1/marketing/bulk_enroll',['ids'=>[$id],'sequence'=>'discovery_en'])->assertNotFound();
    }
    public function test_foreign_relations_and_disabled_module_are_rejected(): void
    {
        $this->postJson('/api/v1/marketing/opportunities',[...$this->payload(),'client_id'=>$this->second['client']->hashed_id])->assertStatus(400);
        $this->postJson('/api/v1/marketing/opportunities',[...$this->payload(),'contact_id'=>$this->second['contact']->hashed_id])->assertStatus(400);
        $this->first['company']->enabled_modules=0;$this->first['company']->saveQuietly();
        $this->getJson('/api/v1/marketing/bootstrap')->assertForbidden();
    }
    public function test_quote_offer_preview_approval_and_automatic_enrollment(): void
    {
        $fixture=$this->first;
        $quote=Model::withoutEvents(fn()=>Quote::factory()->create(['company_id'=>$fixture['company']->id,'user_id'=>$fixture['user']->id,'client_id'=>$fixture['client']->id,'status_id'=>2,'number'=>'MKT-0001','due_date'=>now()->addDays(30)->toDateString()]));
        Model::withoutEvents(fn()=>QuoteInvitation::forceCreate(['company_id'=>$fixture['company']->id,'user_id'=>$fixture['user']->id,'quote_id'=>$quote->id,'client_contact_id'=>$fixture['contact']->id,'key'=>Str::random(40)]));
        $opportunity=$this->postJson('/api/v1/marketing/opportunities',[...$this->payload(),'quote_id'=>$quote->hashed_id,'automatic'=>true])->assertOk()->json('data');
        $config=MarketingConfig::defaults();$config['automatic']=true;$config['auto_enroll_quotes']=true;
        $this->putJson('/api/v1/marketing/settings',['revision'=>0,'config'=>$config])->assertOk();
        $service=new \App\Services\Marketing\MarketingService($fixture['company']);$service->run();$service->run();
        $activity=$this->getJson('/api/v1/marketing/activities?opportunity_id='.$opportunity['id'])->assertOk()->json('data');
        $this->assertCount(4,$activity);
        $offer=collect($activity)->firstWhere('template_id','offer_followup_fr');
        $preview=$this->postJson('/api/v1/marketing/activities/'.$offer['id'].'/preview')->assertOk()->json('data');
        $this->assertStringContainsString($quote->number,$preview['body']);
        $quote->status_id=3;$quote->saveQuietly();$service->run();
        $this->getJson('/api/v1/marketing/opportunities')->assertOk()->assertJsonPath('data.0.stage_id','won');
        $this->assertSame(0,$service->activities()->where('opportunity_id',$opportunity['id'])->where('state','pending')->count());
        Mail::assertNothingSent();
    }

    public function test_read_only_users_cannot_edit_or_configure_marketing(): void
    {
        DB::table('company_user')->where('company_id',$this->first['company']->id)->where('user_id',$this->first['user']->id)->update(['is_owner'=>false,'is_admin'=>false,'permissions'=>'["view_quote","view_client"]']);
        $this->getJson('/api/v1/marketing/bootstrap')->assertOk()->assertJsonPath('data.permissions.edit',false)->assertJsonPath('data.permissions.configure',false);
        $this->postJson('/api/v1/marketing/opportunities',$this->payload())->assertForbidden();
        $this->putJson('/api/v1/marketing/settings',['revision'=>0,'config'=>MarketingConfig::defaults()])->assertForbidden();
    }
    private function quoteFixture(int $status = 1): Quote
    {
        return Model::withoutEvents(fn () => Quote::factory()->create([
            'company_id'=>$this->first['company']->id, 'user_id'=>$this->first['user']->id,
            'client_id'=>$this->first['client']->id, 'status_id'=>$status, 'number'=>'QUOTE-TRACK',
            'assigned_user_id'=>$this->first['user']->id, 'amount'=>2400, 'due_date'=>now()->addDays(30)->toDateString(),
        ]));
    }

    public function test_quote_action_creates_a_prefilled_opportunity_and_reuses_it(): void
    {
        $quote = $this->quoteFixture();
        $this->first['client']->settings = (object) ['language_id'=>'2','currency_id'=>'3'];
        $this->first['client']->saveQuietly();
        $invited = Model::withoutEvents(fn () => ClientContact::factory()->create(['company_id'=>$quote->company_id,'client_id'=>$quote->client_id,'user_id'=>$quote->user_id,'email'=>'invited@example.test','is_primary'=>false,'is_locked'=>false]));
        Model::withoutEvents(fn () => QuoteInvitation::forceCreate(['company_id'=>$quote->company_id,'user_id'=>$quote->user_id,'quote_id'=>$quote->id,'client_contact_id'=>$invited->id,'key'=>Str::random(40)]));
        $record = $this->postJson('/api/v1/marketing/from_quote/'.$quote->hashed_id)->assertOk()->json('data');
        $this->assertEquals('2400.0000',$record['amount']);
        $this->assertSame($invited->hashed_id,$record['contact_id']);
        $this->assertSame($quote->hashed_id,$record['quote_id']);
        $this->assertSame('proposal',$record['stage_id']);
        $this->assertEquals(3,$record['currency_id']);
        $this->assertFalse($record['consent']);
        $this->assertFalse($record['automatic']);
        $this->postJson('/api/v1/marketing/from_quote/'.$quote->hashed_id)->assertOk()->assertJsonPath('data.id',$record['id']);
        $this->getJson('/api/v1/marketing/activities?opportunity_id='.$record['id'])->assertOk()->assertJsonCount(4,'data');
        $this->getJson('/api/v1/marketing/opportunities?opportunity_id='.$record['id'])->assertOk()->assertJsonPath('data.0.next_activity.state','pending');
        $this->withHeaders(['X-API-TOKEN'=>$this->second['token']]);
        $this->postJson('/api/v1/marketing/from_quote/'.$quote->hashed_id)->assertStatus(404);
        Mail::assertNothingSent();
    }

    public function test_quote_events_are_opt_in_and_do_not_restart_archived_opportunities(): void
    {
        $quote = $this->quoteFixture(2);
        $event = new \App\Events\Quote\QuoteWasMarkedSent($quote,$this->first['company'],[]);
        event($event);
        $this->assertEquals(0,\App\Models\MarketingOpportunity::where('company_id',$quote->company_id)->count());
        $config = MarketingConfig::defaults(); $config['auto_create_quotes']=true; $config['quote_followup_mode']='automatic';
        $this->putJson('/api/v1/marketing/settings',['revision'=>0,'config'=>$config])->assertOk();
        event($event); event($event);
        $opportunity = \App\Models\MarketingOpportunity::where('quote_id',$quote->id)->sole();
        $this->assertTrue($opportunity->automatic);
        $this->assertEquals(3,\App\Models\MarketingActivity::where('opportunity_id',$opportunity->id)->where('state','pending')->count());
        $saved=$this->postJson('/api/v1/marketing/from_quote/'.$quote->hashed_id)->assertOk()->json('data');
        $this->putJson('/api/v1/marketing/opportunities/'.$opportunity->id,[...$saved,'archived'=>true])->assertOk();
        event($event);
        $this->assertEquals(1,\App\Models\MarketingOpportunity::where('quote_id',$quote->id)->count());
        $this->assertTrue($opportunity->fresh()->archived);
        $this->assertEquals(0,\App\Models\MarketingActivity::where('opportunity_id',$opportunity->id)->where('state','pending')->count());
        $invitation=Model::withoutEvents(fn () => QuoteInvitation::forceCreate(['company_id'=>$quote->company_id,'user_id'=>$quote->user_id,'quote_id'=>$quote->id,'client_contact_id'=>$this->first['contact']->id,'key'=>Str::random(40)]));
        event(new \App\Events\Quote\QuoteWasEmailed($invitation,$this->first['company'],\App\Utils\Ninja::eventVars($this->first['user']->id),'quote'));
        $this->assertEquals(1,\App\Models\MarketingOpportunity::where('quote_id',$quote->id)->count());
        Mail::assertNothingSent();
    }

    public function test_removing_a_quote_clears_marketing_history_and_prevents_automatic_recreation(): void
    {
        $quote = $this->quoteFixture(2);
        $config = MarketingConfig::defaults();
        $config['auto_create_quotes'] = true;
        $this->putJson('/api/v1/marketing/settings', ['revision'=>0, 'config'=>$config])->assertOk();
        $record = $this->postJson('/api/v1/marketing/from_quote/'.$quote->hashed_id)->assertOk()->json('data');
        $this->assertGreaterThan(0, \App\Models\MarketingActivity::where('opportunity_id', $record['id'])->count());
        $duplicate = \App\Models\MarketingOpportunity::findOrFail($record['id'])->replicate();
        $duplicate->id = (string) Str::uuid();
        $duplicate->save();
        $url = '/api/v1/marketing/opportunities/'.$record['id'];
        $this->deleteJson($url, ['revision'=>$record['revision'] + 1])->assertStatus(409);
        $this->withHeaders(['X-API-TOKEN'=>$this->second['token']]);
        $this->deleteJson($url, ['revision'=>$record['revision']])->assertNotFound();
        $this->withHeaders(['X-API-TOKEN'=>$this->first['token']]);
        $this->deleteJson($url, ['revision'=>$record['revision']])->assertOk();
        $this->assertNotNull($quote->fresh());
        $this->assertSame(0, \App\Models\MarketingActivity::where('opportunity_id', $record['id'])->count());
        $this->getJson('/api/v1/marketing/opportunities?archived=all')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, \App\Models\MarketingOpportunity::where('quote_id', $quote->id)->count());
        event(new \App\Events\Quote\QuoteWasMarkedSent($quote, $this->first['company'], []));
        $this->assertSame(0, \App\Models\MarketingOpportunity::where('quote_id', $quote->id)->count());
        $restored = $this->postJson('/api/v1/marketing/from_quote/'.$quote->hashed_id)->assertOk()->json('data');
        $this->assertNotSame($record['id'], $restored['id']);
        $this->assertSame(1, \App\Models\MarketingOpportunity::where('quote_id', $quote->id)->count());
    }

    public function test_action_lists_and_custom_quote_stage_and_legacy_settings(): void
    {
        $legacy = MarketingConfig::defaults(); unset($legacy['auto_create_quotes'],$legacy['quote_stage_id'],$legacy['quote_followup_mode']);
        $legacy['stages'][2]['id']='custom_proposal';
        DB::table('marketing_settings')->insert(['company_id'=>$this->first['company']->id,'config'=>json_encode($legacy),'revision'=>1]);
        $config = $this->getJson('/api/v1/marketing/bootstrap')->assertOk()->json('data.config');
        $this->assertFalse($config['auto_create_quotes']);
        $this->assertSame('new',$config['quote_stage_id']);
        $config['quote_stage_id']='custom_proposal'; $config['quote_followup_mode']='none';
        $this->putJson('/api/v1/marketing/settings',['revision'=>1,'config'=>$config])->assertOk();
        $quote = $this->quoteFixture();
        $record=$this->postJson('/api/v1/marketing/from_quote/'.$quote->hashed_id)->assertOk()->assertJsonPath('data.stage_id','custom_proposal')->json('data');
        $this->getJson('/api/v1/marketing/opportunities?worklist=needs_followup')->assertOk()->assertJsonCount(1,'data');
        $this->postJson('/api/v1/marketing/activities',['opportunity_id'=>$record['id'],'title'=>'Call now','kind'=>'call','due_at'=>now()->subMinute()->toIso8601String()])->assertOk();
        $this->getJson('/api/v1/marketing/opportunities?worklist=needs_followup')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/v1/marketing/activities?worklist=due')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.title','Call now');
        $this->getJson('/api/v1/marketing/bootstrap')->assertOk()->assertJsonPath('data.workload.due',1)->assertJsonPath('data.workload.needs_followup',0);
    }

}
