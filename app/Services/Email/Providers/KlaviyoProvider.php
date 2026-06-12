<?php

namespace App\Services\Email\Providers;

use App\Services\Email\Contracts\EmailProviderInterface;

class KlaviyoProvider implements EmailProviderInterface
{
    public function name(): string
    {
        return 'klaviyo';
    }

    public function send(string $email, string $subject, string $template, array $data = [], array $metadata = []): array
    {
        return [
            'provider' => $this->name(),
            'status' => 'skipped',
            'reason' => 'Klaviyo API integration is not configured yet.',
        ];
    }
}
