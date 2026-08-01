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
        if (($metadata['sensitive'] ?? false) === true) {
            Log::info('Sensitive email provider send.', [
                'type' => $metadata['type'] ?? null,
                'template' => $template,
                'status' => 'sent',
                'reminder_stage' => $metadata['reminder_stage'] ?? null,
                'abandoned_cart_record_id' => $metadata['abandoned_cart_record_id'] ?? null,
            ]);
        } else {
            Log::info('Email provider log send.', [
                'email' => $email,
                'subject' => $subject,
                'template' => $template,
                'data' => $data,
                'metadata' => $metadata,
            ]);
        }

        return [
            'provider' => $this->name(),
            'status' => 'sent',
            'message_id' => 'log-'.sha1($email.$subject.$template.now()->timestamp),
        ];
    }
}
