# Marketing and sales module

## Enable and operate

1. Deploy the backend and both web clients together. Back up the database, then run `php artisan migrate --force` on the intended server. The migration adds four company-scoped tables; it does not alter existing quotes or send mail.
2. For each company, enable **Marketing & Sales** under Settings → Account Management → Enabled Modules. Keep the existing Quotes module enabled for offer creation and editing. The new module bit is `65536`; existing module flags are preserved.
3. Open Marketing & Sales. Company administrators configure stages, probabilities, lead sources, campaigns, email templates, sequences, sending hours and limits under Settings. Other users need existing quote permissions: `view_quote` for access, `create_quote` for creation, and `edit_quote` for editing, enrollment and delivery. Client and quote selectors respect their existing access policies. Only administrators can change company configuration.
4. Create prospects using Invoice Ninja clients and contacts. Create an opportunity, select its contact, owner, currency, stage, expected close date, source and campaign. Record consent and evidence for marketing messages. Ordinary offer follow-ups use the existing client's reminder preference instead. An unsubscribe blocks every email sent by this module for that company and email address.
5. Create an offer with the existing quote editor. After saving, link that quote in the opportunity. Send the original quote through Invoice Ninja so the selected contact has a quote invitation. Follow-up messages use that contact's existing private portal link.
6. Enroll one opportunity, or select several and enroll them together, in a follow-up sequence. Enrollment creates dated email/call/meeting/task activities. Email steps remain pending until an operator previews and sends them, or automatic sending is enabled for both the company and the opportunity.
7. Use the activities list to view due dates, notes, immutable opportunity-change entries, sent message snapshots, pending work, cancellations and delivery problems. Filter by opportunity or status. Complete calls/tasks/meetings; email activities must actually be sent. Edit or cancel pending activities. Archive opportunities to stop their pending follow-ups.
8. Quote approval or conversion moves an open opportunity to its configured won stage and cancels pending follow-ups. Closing or archiving an opportunity cancels its pending work. The scheduler also synchronizes outcomes without requiring the UI to be open.

React provides a pipeline board, list, currency-separated weighted forecasts, bulk enrollment and current-page CSV export (including spreadsheet-formula escaping). Flutter provides the native opportunity/activity editors, forecasts, bulk enrollment, company configuration, offer navigation, email preview and delivery on desktop and web. Both use the same server field definitions, options and translations. Values and campaign budgets retain their own currencies; there is no implicit exchange-rate conversion.

## Templates and defaults

There are **36 editable email templates**: English, French and German versions of welcome, discovery, meeting follow-up, offer follow-up, offer questions, final offer follow-up, revised proposal, reconnect, renewal, feedback, referral and news. Templates are plain text, not executable code. Company email styling, signature, reply-to and transport settings are reused.

Supported variables:

| Variable | Value |
| --- | --- |
| `{{contact}}` | Selected contact's name |
| `{{client}}` | Client display name |
| `{{company}}` | Company name |
| `{{opportunity}}` | Opportunity title |
| `{{amount}}` | Opportunity amount and currency code |
| `{{quote_number}}` | Linked quote number |
| `{{quote_url}}` | Selected contact's existing quote invitation link |
| `{{expected_close}}` | Expected close date |

Unknown variables and header line breaks are rejected. HTML from data is escaped before email rendering. Every message appends the configurable company footer and a company-scoped unsubscribe link. Visiting that link shows a confirmation form; only submitting the form changes preferences, so link scanners cannot unsubscribe contacts accidentally. Preferences also cover new opportunities created for the same email address.

Six starter sequences are provided: an offer sequence at days 3, 7 and 14, and a discovery sequence with an immediate welcome email, a call at day 2 and a discovery email at day 5, in each of the three languages. Sequence delays are calendar days from enrollment. Activity due dates are edited in UTC; the sending window uses the configured IANA time zone, including daylight-saving changes.

Defaults are deliberately inactive: company automatic sending is off, opportunity automatic sending is off, and automatic enrollment is off. Defaults include Europe/Zurich, weekdays 09:00–17:00, at most 50 emails per company/local calendar day, at least 24 hours between messages to the same contact, and consent required for marketing-purpose templates. All these values can be changed per company. Module-wide validation ceilings bound payloads and accidental bulk sends; they are not delivery promises.

The optional **Automatically enroll sent offers** setting chooses a sequence for each opportunity language. It enrolls linked, sent quotes only when both company and opportunity automation are enabled. It does not create leads/opportunities from arbitrary historical quotes. Changing templates affects future sends; sent snapshots retain the actual rendered content. Enrollment snapshots dates and step references. Re-enrolling the same sequence against the same opportunity/quote is idempotent. To intentionally start a new round, create a sequence with a new identifier or schedule a new activity. Existing activities do not silently reschedule when a sequence is edited.

If Invoice Ninja's built-in quote reminder is enabled for the client, automatic offer delivery from this module is blocked with an explanation. Turn that reminder off before assigning automatic offer follow-ups to Marketing. Manual sends still respect contact preferences, consent where applicable, suppression, daily limits and contact spacing. They may be sent ahead of their due date and outside automatic sending hours.

## Scheduler, mail and recovery

The existing Laravel scheduler invokes `marketing:run` every five minutes. Keep the normal `schedule:run` cron entry running. The command also handles Invoice Ninja's multiple-database configuration. Deployment does not require another service or a new mail provider.

`php artisan marketing:run` synchronizes quote outcomes and sends eligible due emails. **This is a live sending command**, not a dry run. Use it only after reviewing company configuration and pending activities. Do not enable real automatic sends merely to validate a deployment.

The worker checks module/company status, opportunity status, contact validity/locks/email preference, suppression, consent, quote state and client reminder preference immediately before claiming a message. It serializes claims and limits using a company database row lock. Manual and automatic delivery share that path. It processes up to 1,000 due email candidates per company per pass; later passes handle the remainder.

- `pending`: unsent; may be edited or cancelled. An explanatory error can indicate a blocked send. It is re-evaluated on subsequent scheduler runs.
- `sending`: claimed; transport confirmation has not yet been recorded. A crash can leave this status for review.
- `sent`: the configured mail transport accepted the message. This is **not** evidence of inbox delivery, opening or reading.
- `failed`: transport acceptance was not confirmed. Review existing Invoice Ninja/server mail logs before creating a replacement activity.
- `done`: completed non-email work or an audit note.
- `cancelled`: explicitly cancelled or stopped by opportunity/quote changes.

Uncertain sends are never automatically retried, because SMTP acceptance and database commit cannot be made atomic. Repeated send requests cannot re-send a claimed or sent activity. Existing SMTP/provider quotas still apply. No external email provider account is created by this module. Tests fake mail delivery and use `.test` addresses.

## Data and API

New tables: `marketing_settings`, `marketing_opportunities`, `marketing_activities`, `marketing_suppressions`. Company deletion cascades to module data. Company settings, opportunities and pending activity edits use optimistic revisions: stale updates return HTTP 409 and must be reloaded. Linking another contact/quote cancels the old pending follow-ups; review and create the replacement follow-ups. Existing opportunity references prevent removal of used stage/source/campaign identifiers; pending activities prevent deletion of referenced templates.

All authenticated endpoints use the current API token's company:

- `GET /api/v1/marketing/bootstrap?language=en|fr|de`: config, revision, labels, form definitions, allowed client/contact/quote/owner options, permissions and forecasts.
- `PUT /api/v1/marketing/settings`: `{revision, config}`; administrator only.
- `GET /api/v1/marketing/opportunities`: paginated records; `q`, `stage_id`, `archived`, `page`.
- `GET /api/v1/marketing/activities`: paginated records; `q`, `state`, `opportunity_id`, `page`.
- `POST /api/v1/marketing/{opportunities|activities}`: create using the bootstrap field definitions.
- `PUT /api/v1/marketing/{resource}/{uuid}`: update with the current revision.
- `POST /api/v1/marketing/opportunities/{uuid}/enroll`: `{sequence}`.
- `POST /api/v1/marketing/bulk_enroll`: `{ids: [opportunity UUIDs], sequence}`; up to 100 records, all checked within the company and enrolled transactionally.
- `POST /api/v1/marketing/activities/{uuid}/preview`: rendered recipient, subject and body, or the sent snapshot.
- `POST /api/v1/marketing/activities/{uuid}/{send|complete|cancel}`: current `{revision}`. Sending also requires the `preview_version` returned as `version` by the preview endpoint; a changed recipient/template rejects a stale preview.

Module entities use UUIDs. Existing entity references use Invoice Ninja's hashed IDs. Existing missing-record API behavior is retained. There is no unauthenticated lead submission or bulk campaign send endpoint. Client/contact records remain Invoice Ninja's source of truth. Use normal database backups to preserve this module; it is not included in older entity-specific JSON export formats.

## Verification

```sh
php vendor/bin/phpunit tests/Unit/MarketingWorkflowTest.php
PHP_BIN=/opt/homebrew/opt/php@8.3/bin/php bash tests/marketing/run-mysql.sh
# React checkout:
npm test
npm run build
# Flutter checkout, before running web/FOSS builds:
flutter test test_marketing.dart
```

The MySQL runner checks Docker, starts a uniquely named disposable MySQL 8.4 project on loopback port 13309 (override `MARKETING_DB_PORT`), loads the actual schema, runs migrations and seeds, then exercises authenticated workflows with two companies and fake email. It always removes its container/volumes on exit. It never resets an existing Invoice Ninja database. `MarketingApiTest` skips unless explicitly enabled by that runner. The vendor MySQL image remains available as a Docker cache.

Follow `AGENTS.md` for React/Flutter build ordering, web copies and macOS signing checks. Runtime browser tests, builds, fake-email tests and real-provider delivery are distinct checks. Automatic bounce processing, inbox/reply synchronization, open/click tracking, A/B testing, external CRM synchronization, public lead forms and lead scoring are not implemented by this module.

The React browser regression is `tests/e2e/marketing-workflow.spec.ts`, using the dedicated `playwright.marketing.config.ts` (not the normal global database-reset setup). Start a disposable backend on port 18090 with `MAIL_MAILER=array`, a fresh English test company with Marketing enabled, and at least one client/contact. Start Vite on port 18091 with `VITE_API_URL=http://127.0.0.1:18090`. Put its disposable company API token in a private JSON file as `{"token":"..."}`, then run:

```sh
MARKETING_BROWSER=1 MARKETING_FIXTURE=/absolute/path/to/test-token.json npx playwright test --config playwright.marketing.config.ts
```

Use a fresh company for each run: the test intentionally persists an opportunity, activities and settings. It verifies the authenticated workflow through the real API, settings/reload persistence, preview/send with the array mail transport, and a 390-pixel viewport. It does not exercise password login: this checkout's React login currently requests `/api/v1/login/precheck`, which the paired backend does not expose. Flutter widget/build verification does not establish interactive native workflow or real SMTP delivery.
