<?php

namespace App\Services\Payments\Webhooks;

use App\Services\Payments\Contracts\WebhookSignatureValidatorInterface;

class WebhookSignatureValidatorFactory
{
    public function make(string $provider): WebhookSignatureValidatorInterface
    {
        return new MockSignatureValidator;
    }
}
