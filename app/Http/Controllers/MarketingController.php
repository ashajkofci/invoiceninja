<?php
namespace App\Http\Controllers;

use App\Models\{Company, Client, ClientContact, Quote, Currency, MarketingOpportunity, MarketingActivity};
use App\Services\Marketing\{MarketingConfig, MarketingService};
use App\Utils\Traits\MakesHash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MarketingController extends Controller
{
    use MakesHash;

    private function service(string $action = 'view'): MarketingService
    {
        $user = auth()->user();
        $company = $user->company();
        abort_unless($company->enabled_modules & Company::MODULE_MARKETING, 403, 'Marketing is not enabled for this company.');
        abort_unless($user->isAdmin() || $user->hasPermission($action.'_quote'), 403);
        return new MarketingService($company);
    }

    private function visible($query, string $entity)
    {
        if (!auth()->user()->isAdmin() && !auth()->user()->hasPermission('view_'.$entity)) {
            $query->where(fn ($q) => $q->where('user_id',auth()->id())->orWhere('assigned_user_id',auth()->id()));
        }
        return $query;
    }

    private function serializeOpportunity(MarketingOpportunity $o): array
    {
        $data = $o->toArray();
        foreach (['client_id','contact_id','quote_id','owner_id'] as $field) {
            $data[$field] = $o->$field ? $this->encodePrimaryKey($o->$field) : '';
        }
        return $data;
    }

    public function fromQuote(Quote $quote)
    {
        $service = $this->service();
        abort_unless($quote->company_id === $service->company->id && !$quote->is_deleted && auth()->user()->can('view', $quote), 404);
        $existing = $service->opportunities()->where('quote_id', $quote->id)->orderBy('archived')->oldest()->first();
        if (!$existing) { $this->service('create'); }
        $opportunity = $existing ?: $service->fromQuote($quote, auth()->id(), null, true);
        return ['data'=>$this->serializeOpportunity($opportunity)];
    }

    public function bootstrap(Request $request)
    {
        $service = $this->service();
        $company = $service->company;
        $settings = MarketingConfig::get($company);
        $labels = json_decode(file_get_contents(resource_path('marketing/labels.json')),true);
        $language = substr($request->string('language', 'en'),0,2);
        $clients = $this->visible(Client::without(['gateway_tokens','documents','contacts.company'])->where('company_id',$company->id)->where('is_deleted',false),'client')->get();
        $quotes = $this->visible(Quote::where('company_id',$company->id)->where('is_deleted',false),'quote')->get();
        $options = [
            'clients'=>$clients->map(fn ($c) => ['id'=>$c->hashed_id,'name'=>$c->present()->name()])->values(),
            'contacts'=>ClientContact::where('company_id',$company->id)->whereIn('client_id',$clients->pluck('id'))->get()->map(fn ($c) => ['id'=>$c->hashed_id,'name'=>trim($c->first_name.' '.$c->last_name).' <'.$c->email.'>','client_id'=>$this->encodePrimaryKey($c->client_id)])->values(),
            'quotes'=>$quotes->map(fn ($q) => ['id'=>$q->hashed_id,'name'=>$q->number,'client_id'=>$this->encodePrimaryKey($q->client_id)])->values(),
            'owners'=>$company->users()->whereNull('company_user.deleted_at')->where('company_user.is_locked',false)->whereNull('users.deleted_at')->get()->map(fn ($u)=>['id'=>$u->hashed_id,'name'=>trim($u->first_name.' '.$u->last_name) ?: $u->email])->values(),
            'currencies'=>Currency::all()->map(fn ($c)=>['id'=>(string)$c->id,'name'=>$c->code])->values(),
            'opportunities'=>$service->opportunities()->get(['id','title'])->map(fn ($o)=>['id'=>$o->id,'name'=>$o->title])->values(),
        ];
        $stages = collect($settings['config']['stages'])->keyBy('id');
        $stage = fn ($o) => $stages[$o->stage_id] ?? ['outcome'=>'lost','probability'=>0];
        $forecast = $service->opportunities()->where('archived',false)->get()->groupBy('currency_id')->map(function ($items, $currency) use ($stage) {
            $open = $items->filter(fn ($o)=>$stage($o)['outcome']==='open');
            return ['currency_id'=>$currency,'open'=>$open->sum('amount'),'weighted'=>$open->sum(fn ($o)=>round((float)$o->amount * $stage($o)['probability']/100,4)),
                'won'=>$items->filter(fn ($o)=>$stage($o)['outcome']==='won')->sum('amount'),'lost'=>$items->filter(fn ($o)=>$stage($o)['outcome']==='lost')->sum('amount')];
        })->values();
        return ['data'=>[
            ...$settings, 'labels'=>$labels[$language] ?? $labels['en'],'fields'=>MarketingConfig::fields(),'options'=>$options,'forecast'=>$forecast,
            'permissions'=>['edit'=>auth()->user()->isAdmin() || auth()->user()->hasPermission('edit_quote'),'create'=>auth()->user()->isAdmin() || auth()->user()->hasPermission('create_quote'),'configure'=>auth()->user()->isAdmin()],
            'workload'=>['due'=>$service->activities()->where('state','pending')->where('due_at','<=',now())->count(), 'needs_followup'=>$this->withoutNextAction($service->opportunities()->where('archived',false), $settings['config'])->count()],
            'defaults'=>['owner_id'=>auth()->user()->hashed_id,'currency_id'=>(string)$company->settings->currency_id,'locale'=>$settings['config']['default_locale'],'stage_id'=>$settings['config']['stages'][0]['id']],
        ]];
    }

    public function settings(Request $request)
    {
        $service = $this->service('edit');
        abort_unless(auth()->user()->isAdmin(),403);
        $request->validate(['revision'=>'required|integer|min:0','config'=>'required|array']);
        $config = MarketingConfig::validate($request->input('config'));
        DB::transaction(function () use ($service,$request,$config) {
            Company::whereKey($service->company->id)->lockForUpdate()->firstOrFail();
            $current = MarketingConfig::get($service->company);
            abort_unless($current['revision'] === (int)$request->input('revision'),409,'Configuration changed. Reload before saving.');
            foreach (['stage_id'=>'stages','campaign_id'=>'campaigns','source'=>'sources'] as $field=>$collection) {
                $used = $service->opportunities()->whereNotNull($field)->where($field,'!=','')->pluck($field)->unique()->all();
                MarketingConfig::ensure(!array_diff($used,array_column($config[$collection],'id')),$collection,'Cannot remove identifiers used by opportunities.');
            }
            $used = $service->activities()->where('state','pending')->whereNotNull('template_id')->pluck('template_id')->unique()->all();
            MarketingConfig::ensure(!array_diff($used,array_column($config['templates'],'id')),'templates','Cannot remove templates used by pending activities.');
            DB::table('marketing_settings')->updateOrInsert(['company_id'=>$service->company->id],['config'=>json_encode($config),'revision'=>$current['revision']+1,'created_at'=>now(),'updated_at'=>now()]);
        });
        return ['data'=>MarketingConfig::get($service->company)];
    }

    public function bulkEnroll(Request $request)
    {
        $service = $this->service('edit');
        $data = $request->validate(['ids'=>'required|array|min:1|max:100','ids.*'=>'required|uuid|distinct','sequence'=>'required|string|max:64']);
        DB::transaction(function () use ($service,$data) {
            Company::whereKey($service->company->id)->lockForUpdate()->firstOrFail();
            $records = $service->opportunities()->whereIn('id',$data['ids'])->lockForUpdate()->get();
            abort_unless($records->count() === count($data['ids']),404);
            foreach ($records as $record) { $service->enroll($record,$data['sequence'],auth()->id()); }
        });
        return ['data'=>true];
    }

    private function withoutNextAction($query, array $config)
    {
        return $query->whereIn('stage_id', collect($config['stages'])->where('outcome','open')->pluck('id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('marketing_activities')
                ->whereColumn('marketing_activities.opportunity_id','marketing_opportunities.id')->where('state','pending'));
    }

    public function index(Request $request, string $resource)
    {
        $service = $this->service();
        abort_unless(in_array($resource,['opportunities','activities']),404);
        $request->validate(['worklist'=>'nullable|in:due,needs_followup','q'=>'nullable|string|max:255','stage_id'=>'nullable|string','state'=>'nullable|string','opportunity_id'=>'nullable|uuid','page'=>'nullable|integer|min:1']);
        $query = $resource === 'opportunities' ? $service->opportunities() : $service->activities();
        if ($request->filled('q')) { $query->where('title','like','%'.$request->input('q').'%'); }
        if ($resource === 'opportunities') {
            if ($request->input('archived') !== 'all') { $query->where('archived',$request->boolean('archived')); }
            if ($request->filled('opportunity_id')) { $query->whereKey($request->input('opportunity_id')); }
            if ($request->input('worklist') === 'needs_followup') { $this->withoutNextAction($query, $service->config()); }
            if ($request->filled('stage_id')) { $query->where('stage_id',$request->input('stage_id')); }
        } else {
            if ($request->filled('state')) { $query->where('state',$request->input('state')); }
            if ($request->filled('opportunity_id')) { $query->where('opportunity_id',$request->input('opportunity_id')); }
        }
        if ($resource === 'activities' && $request->input('worklist') === 'due') { $query->where('state','pending')->where('due_at','<=',now()); }
        $allPipeline = $resource === 'opportunities' && $request->input('archived') === 'all';
        $page = $query->orderBy($resource === 'opportunities' ? ($allPipeline ? 'created_at' : 'updated_at') : 'due_at', $allPipeline || ($resource === 'activities' && $request->input('worklist') === 'due') ? 'asc' : 'desc')->orderBy('id')->paginate(50);
        if ($resource === 'opportunities') {
            $next = $service->activities()->whereIn('opportunity_id', $page->getCollection()->pluck('id'))->where('state','pending')->orderBy('due_at')->orderBy('id')->get()->groupBy('opportunity_id');
            $page->through(fn ($o)=>[...$this->serializeOpportunity($o), 'next_activity'=>($next[$o->id] ?? collect())->first()]);
        }
        return $page;
    }

    public function save(Request $request, string $resource, ?string $id = null)
    {
        $service = $this->service($id ? 'edit' : 'create');
        abort_unless(in_array($resource,['opportunities','activities']),404);
        $config = $service->config();
        $rules = $resource === 'opportunities' ? [
            'title'=>'required|string|max:255', 'client_id'=>'required|string', 'contact_id'=>'required|string', 'quote_id'=>'nullable|string', 'owner_id'=>'required|string',
            'stage_id'=>['required',Rule::in(array_column($config['stages'],'id'))],
            'amount'=>'required|numeric|min:0|max:999999999999', 'currency_id'=>'required|integer|exists:currencies,id',
            'expected_close'=>'nullable|date_format:Y-m-d', 'source'=>['nullable',Rule::in(array_column($config['sources'],'id'))],
            'campaign_id'=>['nullable',Rule::in(array_column($config['campaigns'],'id'))], 'locale'=>'required|in:en,fr,de',
            'notes'=>'nullable|string|max:20000','lost_reason'=>'nullable|string|max:2000','consent'=>'required|boolean',
            'consent_source'=>'nullable|required_if:consent,true|string|max:255','automatic'=>'required|boolean','archived'=>'required|boolean',
        ] : [
            'opportunity_id'=>'required|uuid','title'=>'required|string|max:255','kind'=>'required|in:email,call,meeting,task,note',
            'due_at'=>'required|date','template_id'=>['nullable','required_if:kind,email',Rule::in(array_column($config['templates'],'id'))], 'notes'=>'nullable|string|max:20000',
        ];
        if ($id) { $rules['revision'] = 'required|integer|min:1'; }
        $valid = $request->validate($rules);
        $saved = DB::transaction(function () use ($resource,$id,$valid,$service,$request) {
            Company::whereKey($service->company->id)->lockForUpdate()->firstOrFail();
            $query = $resource === 'opportunities' ? $service->opportunities() : $service->activities();
            $record = $id ? $query->whereKey($id)->lockForUpdate()->firstOrFail() : ($resource === 'opportunities' ? new MarketingOpportunity() : new MarketingActivity());
            if ($id) { abort_unless($record->revision === (int)$valid['revision'],409,'Record changed. Reload before saving.'); }
            if ($resource === 'opportunities') {
                foreach (['client_id','contact_id','quote_id','owner_id'] as $key) {
                    try { $valid[$key] = empty($valid[$key]) ? null : $this->decodePrimaryKey($valid[$key]); }
                    catch (\Throwable) { MarketingConfig::ensure(false,$key,'Invalid identifier.'); }
                }
                $client = Client::without(['gateway_tokens','documents','contacts.company'])->where('company_id',$service->company->id)->where('is_deleted',false)->findOrFail($valid['client_id']);
                abort_unless(auth()->user()->can('view',$client),403);
                ClientContact::where('company_id',$service->company->id)->where('client_id',$client->id)->findOrFail($valid['contact_id']);
                MarketingConfig::ensure($service->company->users()->whereNull('company_user.deleted_at')->where('company_user.is_locked',false)->whereNull('users.deleted_at')->where('users.id',$valid['owner_id'])->exists(),'owner_id','Owner must be a member of this company.');
                if ($valid['quote_id']) {
                    $quote = Quote::where('company_id',$service->company->id)->where('client_id',$client->id)->where('is_deleted',false)->findOrFail($valid['quote_id']);
                    abort_unless(auth()->user()->can('view',$quote),403);
                    DB::table('marketing_excluded_quotes')->where('company_id',$service->company->id)->where('quote_id',$quote->id)->delete();
                }
                $valid['consent_at'] = $valid['consent'] ? ($record->consent_at ?: now()) : null;
                if ($id && ($record->contact_id != $valid['contact_id'] || $record->quote_id != $valid['quote_id'])) {
                    $service->cancelPending($record,'Contact or offer changed. Review and create new follow-ups.');
                    $valid['consent_at'] = $valid['consent'] ? now() : null;
                }
            } else {
                $service->opportunities()->findOrFail($valid['opportunity_id']);
                abort_unless(!$id || $record->state === 'pending',409,'Only pending activities can be edited.');
                $valid['due_at'] = \Illuminate\Support\Carbon::parse($valid['due_at'],'UTC')->utc()->format('Y-m-d H:i:s');
                $valid['user_id'] = auth()->id();
                if (!$id) { $valid['state'] = $valid['kind'] === 'note' ? 'done' : 'pending'; }
            }
            $valid['revision'] = $id ? $record->revision+1 : 1;
            $changes = [];
            if ($resource === 'opportunities') {
                foreach ($valid as $key=>$value) {
                    if (!in_array($key,['revision','consent_at']) && $record->$key != $value) { $changes[$key] = ['from'=>$record->$key,'to'=>$value]; }
                }
            }
            $record->fill($valid);
            $record->company_id = $service->company->id;
            $record->save();
            if ($resource === 'opportunities') {
                if ($record->archived || $service->stage($record)['outcome'] !== 'open') { $service->cancelPending($record,'Opportunity closed or archived.'); }
                $service->syncQuote($record);
                if ($changes || !$id) {
                    $labels = json_decode(file_get_contents(resource_path('marketing/labels.json')),true)[$record->locale];
                    $service->activities()->create(['company_id'=>$service->company->id,'opportunity_id'=>$record->id,'user_id'=>auth()->id(),'title'=>$labels[$id ? 'audit' : 'created'],'kind'=>'note','state'=>'done','due_at'=>now(),'notes'=>json_encode($changes,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)]);
                }
            }
            return $record;
        });
        return ['data'=>$resource === 'opportunities' ? $this->serializeOpportunity($saved) : $saved];
    }

    public function destroy(Request $request, string $resource, string $id)
    {
        $service = $this->service('edit');
        abort_unless($resource === 'opportunities', 404);
        $data = $request->validate(['revision'=>'required|integer|min:1']);
        DB::transaction(function () use ($service, $id, $data) {
            Company::whereKey($service->company->id)->lockForUpdate()->firstOrFail();
            $record = $service->opportunities()->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($record->revision === (int)$data['revision'], 409, 'Record changed. Reload before removing.');
            $records = $record->quote_id
                ? $service->opportunities()->where('quote_id', $record->quote_id)->lockForUpdate()->get()
                : collect([$record]);
            $ids = $records->pluck('id')->all();
            abort_if($service->activities()->whereIn('opportunity_id', $ids)->where('state', 'sending')->exists(),
                409, 'An email is being sent. Retry after its status is resolved.');
            if ($record->quote_id) {
                DB::table('marketing_excluded_quotes')->insertOrIgnore([
                    'company_id'=>$service->company->id, 'quote_id'=>$record->quote_id, 'created_at'=>now(),
                ]);
            }
            $service->opportunities()->whereIn('id', $ids)->delete();
        });
        return ['data'=>true];
    }

    public function action(Request $request, string $resource, string $id, string $action)
    {
        $service = $this->service($action === 'preview' ? 'view' : 'edit');
        abort_unless(in_array($resource,['opportunities','activities']),404);
        if ($resource === 'opportunities') {
            $opportunity = $service->opportunities()->findOrFail($id);
            abort_unless($action === 'enroll',404);
            $request->validate(['sequence'=>'required|string|max:64']);
            $service->enroll($opportunity,$request->input('sequence'),auth()->id());
            return ['data'=>true];
        }
        $activity = $service->activities()->findOrFail($id);
        if ($action === 'preview') { return ['data'=>$activity->snapshot ?: $service->preview($activity)]; }
        $request->validate(['revision'=>'required|integer|min:1']);
        abort_unless($activity->revision === (int)$request->input('revision'),409,'Record changed. Reload before continuing.');
        if ($action === 'send') {
            $request->validate(['preview_version'=>'required|string|size:64']);
            $service->deliver($activity,false,(int)$request->input('revision'),$request->input('preview_version'));
        }
        elseif (in_array($action,['complete','cancel'])) {
            MarketingConfig::ensure($action !== 'complete' || $activity->kind !== 'email','action','Email activities must be sent, not marked complete.');
            $changed = $service->activities()->whereKey($id)->where('revision',$activity->revision)->where('state','pending')->update([
                'state'=>$action === 'complete' ? 'done' : 'cancelled','revision'=>$activity->revision+1,'updated_at'=>now(),
            ]);
            abort_unless($changed,409,'Only pending activities can be changed.');
        } else { abort(404); }
        return ['data'=>$activity->fresh()];
    }
}
