<?php

namespace App\Services\Email\Providers;

use App\Services\Email\Contracts\EmailProviderInterface;

class BrevoProvider implements EmailProviderInterface
{
    public function name(): string
    {
        return 'brevo';
    }

    public function send(string $email, string $subject, string $template, array $data = [], array $metadata = []): array
    {
        return [
            'provider' => $this->name(),
            'status' => 'skipped',
            'reason' => 'Brevo API integration is not configured yet.',
        ];
    }
}
