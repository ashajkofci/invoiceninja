<?php
namespace App\Services\Marketing;

use App\Models\{Company, Client, ClientContact, Quote, MarketingOpportunity, MarketingActivity};
use App\Services\Email\{Email, EmailObject};
use App\Utils\Traits\MakesHash;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Validation\ValidationException;

class MarketingService
{
    use MakesHash;

    public function __construct(public Company $company) {}

    public function config(): array { return MarketingConfig::get($this->company)['config']; }

    public function opportunities()
    {
        return MarketingOpportunity::where('company_id', $this->company->id);
    }

    public function activities()
    {
        return MarketingActivity::where('company_id', $this->company->id);
    }

    public function stage(MarketingOpportunity $opportunity): array
    {
        return collect($this->config()['stages'])->firstWhere('id', $opportunity->stage_id) ?? ['outcome'=>'lost','probability'=>0];
    }

    public function fromQuote(Quote $quote, ?int $userId = null, ?int $contactId = null, bool $explicit = false): ?MarketingOpportunity
    {
        return DB::transaction(function () use ($quote, $userId, $contactId, $explicit) {
            Company::whereKey($this->company->id)->lockForUpdate()->firstOrFail();
            $quote = Quote::where('company_id', $this->company->id)->where('is_deleted', false)->findOrFail($quote->id);
            $excluded = DB::table('marketing_excluded_quotes')
                ->where('company_id', $this->company->id)->where('quote_id', $quote->id);
            if ($explicit) { $excluded->delete(); }
            elseif ($excluded->exists()) { return null; }
            // Reuse even an archived opportunity; resending must not restart a closed sales cycle.
            $existing = $this->opportunities()->where('quote_id', $quote->id)->orderBy('archived')->oldest()->first();
            if ($existing) { return $existing; }
            $config = $this->config();
            $client = Client::without(['gateway_tokens','documents','contacts.company'])->where('company_id', $this->company->id)->where('is_deleted', false)->findOrFail($quote->client_id);
            $contacts = $client->contacts()->where('company_id', $this->company->id)->where('is_locked', false);
            $invited = $quote->invitations()->pluck('client_contact_id')->all();
            $contact = $contactId ? (clone $contacts)->find($contactId) : null;
            $contact ??= (clone $contacts)->whereIn('id', $invited)->orderByDesc('is_primary')->orderBy('id')->first();
            $contact ??= $contacts->orderByDesc('is_primary')->orderBy('id')->first();
            MarketingConfig::ensure((bool) $contact, 'contact', 'Add an active contact to the quote customer first.');
            $members = $this->company->users()->whereNull('company_user.deleted_at')->where('company_user.is_locked', false)->whereNull('users.deleted_at');
            $owner = null;
            foreach (array_filter([$quote->assigned_user_id, $userId, $quote->user_id]) as $candidate) {
                if ((clone $members)->where('users.id', $candidate)->exists()) { $owner = $candidate; break; }
            }
            $owner ??= $members->orderBy('users.id')->value('users.id');
            MarketingConfig::ensure((bool) $owner, 'owner_id', 'An active company member is required.');
            $locale = substr($client->locale(), 0, 2);
            if (!in_array($locale, ['en','fr','de'])) { $locale = $config['default_locale']; }
            $labels = json_decode(file_get_contents(resource_path('marketing/labels.json')), true)[$locale];
            $opportunity = $this->opportunities()->create([
                'company_id'=>$this->company->id, 'client_id'=>$client->id, 'contact_id'=>$contact->id, 'quote_id'=>$quote->id,
                'owner_id'=>$owner, 'title'=>Str::limit($quote->number.' · '.$client->present()->name(), 255, ''),
                'stage_id'=>$config['quote_stage_id'], 'amount'=>max(0, $quote->amount), 'currency_id'=>$client->getSetting('currency_id'),
                'expected_close'=>$quote->due_date ?: null, 'locale'=>$locale, 'consent'=>false,
                'automatic'=>$config['quote_followup_mode'] === 'automatic', 'archived'=>false,
            ]);
            $this->activities()->create(['company_id'=>$this->company->id,'opportunity_id'=>$opportunity->id,'user_id'=>$owner,
                'title'=>$labels['created_from_quote'],'kind'=>'note','state'=>'done','due_at'=>now(),'notes'=>$quote->number]);
            $this->syncQuote($opportunity);
            if ($config['quote_followup_mode'] !== 'none' && $this->stage($opportunity)['outcome'] === 'open') {
                $this->enroll($opportunity, $config['offer_sequence_'.$locale], $owner);
            }
            return $opportunity->refresh();
        });
    }

    public function enroll(MarketingOpportunity $opportunity, string $sequenceId, int $userId): void
    {
        $sequence = collect($this->config()['sequences'])->firstWhere('id', $sequenceId);
        MarketingConfig::ensure((bool) $sequence, 'sequence', 'Unknown sequence.');
        MarketingConfig::ensure(!$opportunity->archived && $this->stage($opportunity)['outcome'] === 'open', 'sequence', 'Only open opportunities can be enrolled.');
        DB::transaction(function () use ($sequence, $opportunity, $userId) {
            Company::whereKey($this->company->id)->lockForUpdate()->firstOrFail();
            $opportunity = $this->opportunities()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            MarketingConfig::ensure(!$opportunity->archived && $this->stage($opportunity)['outcome'] === 'open','sequence','Only open opportunities can be enrolled.');
            foreach ($sequence['steps'] as $index => $step) {
                $this->activities()->firstOrCreate([
                    'opportunity_id' => $opportunity->id,
                    'sequence_key' => $sequence['id'].':'.($opportunity->quote_id ?? 'none').':'.$index,
                ], [
                    'company_id' => $this->company->id, 'user_id' => $userId,
                    'title' => $step['title'], 'kind' => $step['kind'],
                    'template_id' => $step['template_id'] ?: null,
                    'due_at' => now()->addDays($step['days']), 'state' => 'pending',
                ]);
            }
        });
    }

    public function cancelPending(MarketingOpportunity $opportunity, string $reason): void
    {
        $this->activities()->where('opportunity_id', $opportunity->id)->where('state', 'pending')
            ->update(['state'=>'cancelled','error'=>$reason,'revision'=>DB::raw('revision + 1'),'updated_at'=>now()]);
    }

    public function syncQuote(MarketingOpportunity $opportunity): void
    {
        if (!$opportunity->quote_id || $opportunity->archived) { return; }
        $quote = Quote::where('company_id', $this->company->id)->find($opportunity->quote_id);
        if ($quote && !$quote->is_deleted && in_array($quote->status_id, [Quote::STATUS_APPROVED, Quote::STATUS_CONVERTED])) {
            if ($this->stage($opportunity)['outcome'] === 'open') {
                $won = collect($this->config()['stages'])->firstWhere('outcome', 'won');
                $opportunity->update(['stage_id'=>$won['id'],'revision'=>$opportunity->revision+1]);
            }
            $this->cancelPending($opportunity, 'Offer accepted or converted.');
        }
    }

    public function preview(MarketingActivity $activity): array
    {
        $opportunity = $this->opportunities()->findOrFail($activity->opportunity_id);
        $template = collect($this->config()['templates'])->firstWhere('id', $activity->template_id);
        MarketingConfig::ensure((bool) $template, 'template_id', 'Select an existing email template.');
        $client = Client::without(['gateway_tokens','documents','contacts.company'])->where('company_id', $this->company->id)->findOrFail($opportunity->client_id);
        $contact = ClientContact::where('company_id', $this->company->id)->where('client_id', $client->id)->findOrFail($opportunity->contact_id);
        $quote = $opportunity->quote_id ? Quote::where('company_id', $this->company->id)->where('client_id', $client->id)->find($opportunity->quote_id) : null;
        $invitation = $quote?->invitations()->where('client_contact_id', $contact->id)->first();
        MarketingConfig::ensure($template['purpose'] !== 'offer' || ($quote && $invitation), 'quote_id', 'Offer emails require a quote invitation for this contact. Send the original quote through Invoice Ninja first.');
        $values = [
            '{{contact}}'=>trim($contact->first_name.' '.$contact->last_name) ?: $client->present()->name(),
            '{{client}}'=>$client->present()->name(), '{{company}}'=>$this->company->settings->name,
            '{{opportunity}}'=>$opportunity->title, '{{amount}}'=>(string)$opportunity->amount.' '.(\App\Models\Currency::find($opportunity->currency_id)?->code ?? ''),
            '{{quote_number}}'=>$quote?->number ?? '',
            '{{quote_url}}'=>$invitation ? $invitation->getLink() : '',
            '{{expected_close}}'=>$opportunity->expected_close ?? '',
        ];
        $token = Crypt::encryptString(json_encode(['company_id'=>$this->company->id,'email'=>strtolower(trim($contact->email)), 'db'=>$this->company->db, 'locale'=>$template['locale']]));
        $unsubscribe = url('/marketing/unsubscribe').'?token='.urlencode($token);
        $labels = json_decode(file_get_contents(resource_path('marketing/labels.json')), true)[$template['locale']];
        return [
            'version'=>hash('sha256',json_encode([$activity->id,$activity->revision,$contact->email,$template,$values,$this->config()['footer']])),
            'to'=>$contact->email, 'subject'=>str_replace(["\r","\n"], ' ', strtr($template['subject'], $values)),
            'body'=>strtr($template['body'], $values)."\n\n".($this->config()['footer'] ?? '')."\n\n".$labels['unsubscribe'].': '.$unsubscribe,
            'locale'=>$template['locale'], 'purpose'=>$template['purpose'],
        ];
    }

    public function deliver(MarketingActivity $activity, bool $automatic = false, ?int $expectedRevision = null, ?string $expectedPreview = null): void
    {
        // A company row lock serializes claims and quota checks across workers.
        // ponytail: company-wide serialization; use a transactional outbox if throughput requires concurrent sends.
        $claimed = DB::transaction(function () use ($activity, $automatic, $expectedRevision, $expectedPreview) {
            $this->company = Company::whereKey($this->company->id)->lockForUpdate()->firstOrFail();
            $activity = $this->activities()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
            abort_unless($expectedRevision === null || $activity->revision === $expectedRevision,409,'Activity changed. Preview it again.');
            if ($activity->state !== 'pending' || $activity->kind !== 'email') { return; }
            $opportunity = $this->opportunities()->whereKey($activity->opportunity_id)->lockForUpdate()->firstOrFail();
            $this->syncQuote($opportunity);
            $activity->refresh();
            if ($activity->state !== 'pending') { return; }
            $config = $this->config();
            if ($automatic && (!$config['automatic'] || !$opportunity->automatic || !$this->inSendingWindow($config))) { return; }
            MarketingConfig::ensure(($this->company->enabled_modules & Company::MODULE_MARKETING) !== 0 && !$this->company->is_disabled, 'company', 'Marketing is disabled.');
            MarketingConfig::ensure(!$opportunity->archived && $this->stage($opportunity)['outcome'] === 'open', 'opportunity', 'Opportunity is closed or archived.');
            $client = Client::without(['gateway_tokens','documents','contacts.company'])->where('company_id',$this->company->id)->where('is_deleted',false)->findOrFail($opportunity->client_id);
            $contact = ClientContact::where('company_id',$this->company->id)->where('client_id',$client->id)->findOrFail($opportunity->contact_id);
            MarketingConfig::ensure(!$contact->is_locked && $contact->send_email && filter_var($contact->email, FILTER_VALIDATE_EMAIL), 'contact', 'Contact is locked, has disabled email, or has no valid email.');
            MarketingConfig::ensure(!DB::table('marketing_suppressions')->where('company_id',$this->company->id)->where('email',strtolower(trim($contact->email)))->exists(), 'contact', 'Contact has unsubscribed.');
            $preview = $this->preview($activity);
            abort_unless($expectedPreview === null || hash_equals($preview['version'],$expectedPreview),409,'Recipient or template changed. Preview the email again.');
            MarketingConfig::ensure($preview['purpose'] !== 'marketing' || !$config['require_consent'] || ($opportunity->consent && $opportunity->consent_source), 'consent', 'Record marketing consent and its source first.');
            if ($preview['purpose'] === 'offer') {
                $quote = Quote::where('company_id',$this->company->id)->where('client_id',$client->id)->findOrFail($opportunity->quote_id);
                MarketingConfig::ensure(!$automatic || !$client->getSetting('enable_quote_reminder1'), 'quote', 'The built-in quote reminder is enabled. Disable it before using automatic marketing offer follow-ups.');
                MarketingConfig::ensure(!$quote->is_deleted && $quote->status_id === Quote::STATUS_SENT && $client->getSetting('send_reminders'), 'quote', 'Only sent, unexpired offers with reminders enabled may be followed up.');
            }
            $start = now()->setTimezone($config['timezone'])->startOfDay()->utc();
            $sentToday = $this->activities()->whereIn('state',['sent','sending','failed'])->where('updated_at','>=',$start)->count();
            $recent = $this->activities()->whereIn('opportunity_id', $this->opportunities()->where('contact_id',$contact->id)->select('id'))
                ->whereIn('state',['sent','sending','failed'])->where('updated_at','>',now()->subHours($config['min_interval_hours']))->exists();
            if ($automatic && ($sentToday >= $config['daily_limit'] || $recent)) { return; }
            MarketingConfig::ensure($sentToday < $config['daily_limit'] && !$recent, 'limit', 'Company daily limit or contact sending interval reached.');
            $activity->update(['state'=>'sending','snapshot'=>$preview,'revision'=>$activity->revision+1]);
            return true;
        });
        if (!$claimed) { return; }
        $activity->refresh();
        if ($activity->state !== 'sending') { return; }
        // Never retry a transport-uncertain send automatically. A crash leaves it in "sending" for review.
        try {
            $opportunity = $this->opportunities()->findOrFail($activity->opportunity_id);
            $object = new EmailObject();
            $object->client_id = $opportunity->client_id;
            $object->client_contact_id = $opportunity->contact_id;
            $object->to = [new Address($activity->snapshot['to'])];
            $object->subject = $activity->snapshot['subject'];
            $object->body = nl2br(e($activity->snapshot['body']));
            $object->text_body = $activity->snapshot['body'];
            $email = app()->makeWith(Email::class, ['email_object'=>$object, 'company'=>$this->company]);
            $email->handle();
            $activity->update(['state'=>$email->delivered ? 'sent' : 'failed','sent_at'=>$email->delivered ? now() : null,
                'error'=>$email->delivered ? null : 'Transport did not confirm acceptance. Review email logs before creating another message.', 'revision'=>$activity->revision+1]);
        } catch (\Throwable $e) {
            report($e);
            $activity->update(['state'=>'failed','error'=>'Delivery uncertain. Review server email logs before creating another message.','revision'=>$activity->revision+1]);
        }
    }

    public function inSendingWindow(array $config): bool
    {
        $now = now()->setTimezone($config['timezone']);
        return (!$config['weekdays_only'] || !$now->isWeekend()) && $now->hour >= $config['send_hour_start'] && $now->hour < $config['send_hour_end'];
    }

    public function run(): void
    {
        $this->opportunities()->whereNotNull('quote_id')->where('archived',false)->each(function ($o) {
            DB::transaction(function () use ($o) {
                Company::whereKey($this->company->id)->lockForUpdate()->firstOrFail();
                $o = $this->opportunities()->whereKey($o->id)->lockForUpdate()->firstOrFail();
                $this->syncQuote($o);
                $config = $this->config();
                if ($config['auto_enroll_quotes'] && $config['automatic'] && $o->automatic && $this->stage($o)['outcome'] === 'open') {
                    $quote = Quote::where('company_id',$this->company->id)->find($o->quote_id);
                    if ($quote && !$quote->is_deleted && $quote->status_id === Quote::STATUS_SENT) {
                        $this->enroll($o, $config['offer_sequence_'.$o->locale], $o->owner_id);
                    }
                }
            });
        });
        $this->activities()->where('kind','email')->where('state','pending')->where('due_at','<=',now())->orderBy('due_at')->limit(1000)->get()->each(function ($activity) {
            try { $this->deliver($activity, true); }
            catch (ValidationException $e) {
                $activity->update(['error'=>collect($e->errors())->flatten()->implode(' ')]);
            }
            catch (\Throwable $e) { report($e); }
        });
    }
}
