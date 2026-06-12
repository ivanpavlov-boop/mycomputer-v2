<?php

namespace App\Services\Email\Providers;

use App\Services\Email\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Log;

class LogEmailProvider implements EmailProviderInterface
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $email, string $subject, string $template, array $data = [], array $metadata = []): array
    {
        Log::info('Email provider log send.', [
            'email' => $email,
            'subject' => $subject,
            'template' => $template,
            'data' => $data,
            'metadata' => $metadata,
        ]);

        return [
            'provider' => $this->name(),
            'status' => 'sent',
            'message_id' => 'log-'.sha1($email.$subject.$template.now()->timestamp),
        ];
    }
}
