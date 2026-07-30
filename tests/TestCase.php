<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Existing checkout suites exercise the enabled contract. Release-gate
        // tests override these values to cover closed and rollback states.
        config()->set('commerce.public.enabled', true);
        config()->set('commerce.public.confirmation_enabled', true);
        config()->set('legal.approved', true);
        config()->set(
            'legal.manifest_path',
            base_path('tests/Fixtures/Legal/approved-legal-content-manifest.json'),
        );
        config()->set(
            'legal.approval_record_path',
            base_path('tests/Fixtures/Legal/approved-legal-content-approval.json'),
        );
        config()->set('legal.source_pages', [
            'terms' => base_path('tests/Fixtures/Legal/approved-terms.bg.ts'),
            'privacy' => base_path('tests/Fixtures/Legal/approved-privacy.bg.ts'),
        ]);
    }

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
