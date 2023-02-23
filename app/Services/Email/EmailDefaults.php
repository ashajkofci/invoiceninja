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

namespace App\Services\Email;

use App\DataMapper\EmailTemplateDefaults;
use App\Models\Account;
use App\Utils\Ninja;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\App;
use League\CommonMark\CommonMarkConverter;

class EmailDefaults
{
    /**
     * The settings object for this email
     * @var CompanySettings $settings
     */
    protected $settings;

    /**
     * The HTML / Template to use for this email
     * @var string $template
     */
    private string $template;

    /**
     * The locale to use for
     * translations for this email
     */
    private string $locale;

    /**
     * @param EmailService $email_service The email service class
     * @param EmailObject  $email_object  the email object class
     */
    public function __construct(protected EmailService $email_service, public EmailObject $email_object)
    {
    }
 
    /**
     * Entry point for generating
     * the defaults for the email object
     *
     * @return EmailObject $email_object The email object
     */
    public function run()
    {
        $this->settings = $this->email_object->settings;

        $this->setLocale()
             ->setFrom()
             ->setTemplate()
             ->setBody()
             ->setSubject()
             ->setReplyTo()
             ->setBcc()
             ->setAttachments()
             ->setMetaData()
             ->setVariables();
        
        return $this->email_object;
    }

    /**
     * Sets the meta data for the Email object
     */
    private function setMetaData(): self
    {
        $this->email_object->company_key = $this->email_service->company->company_key;

        $this->email_object->logo = $this->email_service->company->present()->logo();

        $this->email_object->signature = $this->email_object->signature ?: $this->settings->email_signature;

        $this->email_object->whitelabel = $this->email_object->company->account->isPaid() ? true : false;
 
        return $this;
    }

    /**
     * Sets the locale
     */
    private function setLocale(): self
    {
        if ($this->email_object->client) {
            $this->locale = $this->email_object->client->locale();
        } elseif ($this->email_object->vendor) {
            $this->locale = $this->email_object->vendor->locale();
        } else {
            $this->locale = $this->email_service->company->locale();
        }

        App::setLocale($this->locale);
        App::forgetInstance('translator');
        $t = app('translator');
        $t->replace(Ninja::transformTranslations($this->settings));

        return $this;
    }

    /**
     * Sets the template
     */
    private function setTemplate(): self
    {
        $this->template = $this->email_object->settings->email_style;

        match ($this->email_object->settings->email_style) {
            'light' => $this->template = 'email.template.client',
            'dark' => $this->template = 'email.template.client',
            'custom' => $this->template = 'email.template.custom',
            default => $this->template = 'email.template.client',
        };

        $this->email_object->html_template = $this->template;

        return $this;
    }

    /**
     * Sets the FROM address
     */
    private function setFrom(): self
    {
        if (Ninja::isHosted() && $this->email_object->settings->email_sending_method == 'default') {
            $this->email_object->from = new Address(config('mail.from.address'), $this->email_service->company->owner()->name());
            return $this;
        }

        if ($this->email_object->from) {
            return $this;
        }

        $this->email_object->from = new Address($this->email_service->company->owner()->email, $this->email_service->company->owner()->name());

        return $this;
    }

    /**
     * Sets the body of the email
     */
    private function setBody(): self
    {
        if ($this->email_object->body) {
            $this->email_object->body = $this->email_object->body;
        } elseif (strlen($this->email_object->settings->{$this->email_object->email_template_body}) > 3) {
            $this->email_object->body = $this->email_object->settings->{$this->email_object->email_template_body};
        } else {
            $this->email_object->body = EmailTemplateDefaults::getDefaultTemplate($this->email_object->email_template_body, $this->locale);
        }
        
        if ($this->template == 'email.template.custom') {
            $this->email_object->body = (str_replace('$body', $this->email_object->body, $this->email_object->settings->email_style_custom));
        }

        return $this;
    }

    /**
     * Sets the subject of the email
     */
    private function setSubject(): self
    {
        if ($this->email_object->subject) { //where the user updates the subject from the UI
            return $this;
        } elseif (strlen($this->email_object->settings->{$this->email_object->email_template_subject}) > 3) {
            $this->email_object->subject = $this->email_object->settings->{$this->email_object->email_template_subject};
        } else {
            $this->email_object->subject = EmailTemplateDefaults::getDefaultTemplate($this->email_object->email_template_subject, $this->locale);
        }

        return $this;
    }

    /**
     * Sets the reply to of the email
     */
    private function setReplyTo(): self
    {
        $reply_to_email = str_contains($this->email_object->settings->reply_to_email, "@") ? $this->email_object->settings->reply_to_email : $this->email_service->company->owner()->email;

        $reply_to_name = strlen($this->email_object->settings->reply_to_name) > 3 ? $this->email_object->settings->reply_to_name : $this->email_service->company->owner()->present()->name();

        $this->email_object->reply_to = array_merge($this->email_object->reply_to, [new Address($reply_to_email, $reply_to_name)]);

        return $this;
    }

    /**
     * Replaces the template placeholders
     * with variable values.
     */
    public function setVariables(): self
    {
        $this->email_object->body = strtr($this->email_object->body, $this->email_object->variables);
        
        $this->email_object->subject = strtr($this->email_object->subject, $this->email_object->variables);

        if ($this->template != 'custom') {
            $this->email_object->body = $this->parseMarkdownToHtml($this->email_object->body);
        }

        return $this;
    }

    /**
     * Sets the BCC of the email
     */
    private function setBcc(): self
    {
        $bccs = [];
        $bcc_array = [];

        if (strlen($this->email_object->settings->bcc_email) > 1) {
            if (Ninja::isHosted() && $this->email_service->company->account->isPaid()) {
                $bccs = array_slice(explode(',', str_replace(' ', '', $this->email_object->settings->bcc_email)), 0, 2);
            } elseif (Ninja::isSelfHost()) {
                $bccs = (explode(',', str_replace(' ', '', $this->email_object->settings->bcc_email)));
            }
        }

        foreach ($bccs as $bcc) {
            $bcc_array[] = new Address($bcc);
        }
        
        $this->email_object->bcc = array_merge($this->email_object->bcc, $bcc_array);

        return $this;
    }

    /**
     * Sets the CC of the email
     * @todo at some point....
     */
    private function buildCc()
    {
        return [
        
        ];
    }

    /**
     * Sets the attachments for the email
     *
     * Note that we base64 encode these, as they
     * sometimes may not survive serialization.
     *
     * We decode these in the Mailable later
     */
    private function setAttachments(): self
    {
        $attachments = [];

        if ($this->email_object->settings->document_email_attachment && $this->email_service->company->account->hasFeature(Account::FEATURE_DOCUMENTS)) {
            foreach ($this->email_service->company->documents as $document) {
                $attachments[] = ['file' => base64_encode($document->getFile()), 'name' => $document->name];
            }
        }

        $this->email_object->attachments = array_merge($this->email_object->attachments, $attachments);

        return $this;
    }

    /**
     * Sets the headers for the email
     */
    private function setHeaders(): self
    {
        if ($this->email_object->invitation_key) {
            $this->email_object->headers = array_merge($this->email_object->headers, ['x-invitation-key' => $this->email_object->invitation_key]);
        }

        return $this;
    }

    /**
     * Converts any markdown to HTML in the email
     *
     * @param  string $markdown The body to convert
     * @return string           The parsed markdown response
     */
    private function parseMarkdownToHtml(string $markdown): ?string
    {
        $converter = new CommonMarkConverter([
            'allow_unsafe_links' => false,
        ]);

        return $converter->convert($markdown);
    }
}
