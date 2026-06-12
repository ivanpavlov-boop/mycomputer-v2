<?php

namespace App\Jobs;

use App\Services\Email\EmailMarketingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [60, 300, 900];

    public function __construct(
        public string $email,
        public string $type,
        public array $data = [],
        public ?string $subject = null,
    ) {
        $this->onQueue('emails');
    }

    public function handle(EmailMarketingService $emailMarketing): void
    {
        $emailMarketing->send($this->email, $this->type, $this->data, $this->subject);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
