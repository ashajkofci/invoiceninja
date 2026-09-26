<?php
namespace App\Services\Marketing;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MarketingConfig
{
    public static function defaults(): array
    {
        return json_decode(file_get_contents(resource_path('marketing/defaults.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function get(Company $company): array
    {
        $row = DB::table('marketing_settings')->where('company_id', $company->id)->first();
        return ['revision' => $row?->revision ?? 0, 'config' => $row ? json_decode($row->config, true) : self::defaults()];
    }

    public static function validate(array $data): array
    {
        $rules = [
            'automatic' => 'required|boolean', 'auto_enroll_quotes'=>'required|boolean',
            'offer_sequence_en'=>'required|string', 'offer_sequence_fr'=>'required|string', 'offer_sequence_de'=>'required|string', 'timezone' => 'required|timezone',
            'send_hour_start' => 'required|integer|min:0|max:23', 'send_hour_end' => 'required|integer|min:1|max:24|gt:send_hour_start',
            'weekdays_only' => 'required|boolean', 'daily_limit' => 'required|integer|min:1|max:10000',
            'min_interval_hours' => 'required|integer|min:0|max:8760', 'require_consent' => 'required|boolean',
            'default_locale' => 'required|in:en,fr,de', 'footer' => 'nullable|string|max:4000',
            'stages' => 'required|array|min:3|max:50', 'templates' => 'required|array|min:1|max:500',
            'sequences' => 'present|array|max:100', 'campaigns' => 'present|array|max:500', 'sources' => 'present|array|max:100',
            'stages.*.probability' => 'required|integer|min:0|max:100', 'stages.*.outcome' => 'required|in:open,won,lost',
            'templates.*.locale' => 'required|in:en,fr,de', 'templates.*.purpose' => 'required|in:offer,marketing',
            'templates.*.subject' => ['required','string','max:255', 'not_regex:/[\r\n]/'], 'templates.*.body' => 'required|string|max:20000',
            'campaigns.*.budget' => 'required|numeric|min:0|max:999999999', 'campaigns.*.currency_id' => 'required|integer|exists:currencies,id',
            'campaigns.*.description' => 'nullable|string|max:4000',
            'sequences.*.steps' => 'required|array|min:1|max:30',
            'sequences.*.steps.*.days' => 'required|integer|min:0|max:3650',
            'sequences.*.steps.*.kind' => 'required|in:email,call,meeting,task',
            'sequences.*.steps.*.title' => 'required|string|max:255',
            'sequences.*.steps.*.template_id' => 'nullable|string|max:64',
        ];
        foreach (['stages', 'templates', 'sequences', 'campaigns', 'sources'] as $key) {
            $rules["$key.*.id"] = ['required','string','max:64','regex:/^[a-zA-Z0-9_-]+$/','distinct'];
            $rules["$key.*.name"] = 'required|string|max:255';
        }
        $valid = Validator::make($data, $rules)->validate();
        foreach (['open','won','lost'] as $outcome) {
            self::ensure(collect($valid['stages'])->contains('outcome', $outcome), 'stages', "A $outcome stage is required.");
        }
        foreach (['en','fr','de'] as $locale) {
            self::ensure(collect($valid['sequences'])->contains('id', $valid['offer_sequence_'.$locale]), 'sequences', 'Choose an existing offer sequence for each language.');
        }
        foreach ($valid['sequences'] as $sequence) {
            foreach ($sequence['steps'] as $step) {
                if ($step['kind'] === 'email') {
                    self::ensure(collect($valid['templates'])->contains('id', $step['template_id']), 'sequences', 'Every email step needs an existing template.');
                }
            }
        }
        foreach ($valid['templates'] as $template) {
            preg_match_all('/\{\{(.*?)\}\}/', $template['subject'].' '.$template['body'], $matches);
            self::ensure(!array_diff($matches[1], ['contact','client','company','opportunity','amount','quote_number','quote_url','expected_close']), 'templates', 'Unknown template variable.');
        }
        return $valid;
    }

    public static function ensure(bool $condition, string $key, string $message): void
    {
        if (!$condition) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }

    // Both native clients use these field definitions, so config and editing stay in sync.
    public static function fields(): array
    {
        $field = fn ($name, $type = 'text', $options = null) => array_filter(compact('name', 'type', 'options'), fn ($v) => $v !== null);
        $common = [$field('id'), $field('name')];
        $collections = [
            'stages' => [...$common, $field('probability','number'),$field('outcome','select',['open','won','lost'])],
            'templates' => [...$common,$field('locale','select',['en','fr','de']),$field('purpose','select',['offer','marketing']),$field('subject'),$field('body','textarea')],
            'campaigns' => [...$common,$field('budget','number'),$field('currency_id','select','currencies'),$field('description','textarea')],
            'sources' => $common,
            'sequences' => [...$common,['name'=>'steps','type'=>'list','fields'=>[$field('title'),$field('days','number'),$field('kind','select',['email','call','meeting','task']),$field('template_id','select','templates')]]],
        ];
        return [
            'opportunities' => [$field('title'),$field('client_id','select','clients'),$field('contact_id','select','contacts'),$field('quote_id','select','quotes'),$field('owner_id','select','owners'),$field('stage_id','select','stages'),$field('amount','number'),$field('currency_id','select','currencies'),$field('expected_close','date'),$field('source','select','sources'),$field('campaign_id','select','campaigns'),$field('locale','select',['en','fr','de']),$field('notes','textarea'),$field('lost_reason','textarea'),$field('consent','boolean'),$field('consent_source'),$field('automatic','boolean'),$field('archived','boolean')],
            'activities' => [$field('opportunity_id','select','opportunities'),$field('title'),$field('kind','select',['email','call','meeting','task','note']),$field('due_at','datetime-local'),$field('template_id','select','templates'),$field('notes','textarea')],
            'settings' => [$field('automatic','boolean'),$field('auto_enroll_quotes','boolean'),$field('offer_sequence_en','select','sequences'),$field('offer_sequence_fr','select','sequences'),$field('offer_sequence_de','select','sequences'),$field('default_locale','select',['en','fr','de']),$field('timezone'),$field('send_hour_start','number'),$field('send_hour_end','number'),$field('weekdays_only','boolean'),$field('daily_limit','number'),$field('min_interval_hours','number'),$field('require_consent','boolean'),$field('footer','textarea')],
            'collections' => $collections,
        ];
    }
}
