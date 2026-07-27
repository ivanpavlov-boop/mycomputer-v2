<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function cartSession(string $name): string
    {
        $hex = md5($name);
        $hex[12] = '4';
        $hex[16] = '8';

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }

    protected function checkoutIdempotencyKey(string $name): string
    {
        return rtrim(strtr(
            base64_encode(hash('sha256', 'checkout-idempotency:'.$name, true)),
            '+/',
            '-_',
        ), '=');
    }
}
