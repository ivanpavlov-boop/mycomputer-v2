<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentRedirectPolicy;
use Tests\TestCase;

class PaymentRedirectSafetyTest extends TestCase
{
    public function test_redirect_policy_requires_exact_allowlisted_https_host(): void
    {
        config()->set(
            'payments.methods.card.approved_redirect_hosts',
            ['payments.example.test'],
        );
        $policy = app(PaymentRedirectPolicy::class);

        $this->assertSame(
            'https://payments.example.test/continue',
            $policy->approved('https://payments.example.test/continue'),
        );

        foreach ([
            'http://payments.example.test/continue',
            'https://user:pass@payments.example.test/continue',
            'https://payments.example.test/continue#token',
            'https://sub.payments.example.test/continue',
            'https://localhost/continue',
            'https://127.0.0.1/continue',
            '/continue',
            'javascript:alert(1)',
        ] as $url) {
            $this->assertNull($policy->approved($url), $url);
        }
    }

    public function test_production_redirect_allowlist_is_empty_by_default(): void
    {
        config()->set('payments.methods.card.approved_redirect_hosts', []);

        $this->assertNull(
            app(PaymentRedirectPolicy::class)
                ->approved('https://payments.example.test/continue'),
        );
    }
}
