<?php

namespace App\Services\Payments\Contracts;

use Illuminate\Http\Request;

interface WebhookSignatureValidatorInterface
{
    public function validate(string $provider, Request $request): bool;
}
