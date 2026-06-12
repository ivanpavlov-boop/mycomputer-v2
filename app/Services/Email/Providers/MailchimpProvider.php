<?php

namespace App\Services\Email\Providers;

use App\Services\Email\Contracts\EmailProviderInterface;

class MailchimpProvider implements EmailProviderInterface
{
    public function name(): string
    {
        return 'mailchimp';
    }

    public function send(string $email, string $subject, string $template, array $data = [], array $metadata = []): array
    {
        return [
            'provider' => $this->name(),
            'status' => 'skipped',
            'reason' => 'Mailchimp API integration is not configured yet.',
        ];
    }
}
