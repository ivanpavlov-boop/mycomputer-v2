import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const page = readFileSync(resolve(frontendRoot, 'app/pages/checkout/success.vue'), 'utf8')
const fixture = readFileSync(
  resolve(frontendRoot, 'test/browser/fixtures/cart-api-server.mjs'),
  'utf8',
)

describe('Checkout success privacy', () => {
  it('prevents indexing, archiving, and referrer leakage', () => {
    expect(page).toContain("content: 'noindex, nofollow, noarchive'")
    expect(page).toContain("{ name: 'referrer', content: 'no-referrer' }")
  })

  it('keeps the browser fixture capability HttpOnly and out of snapshots', () => {
    expect(fixture).toContain('mc_checkout_confirmation=${confirmationToken}; Max-Age=7200; Path=/; HttpOnly; SameSite=Lax')
    expect(fixture).toContain('mc_payment_retry=${retryToken}; Max-Age=3600; Path=/api/v1/checkout/payment-attempts; HttpOnly; SameSite=Lax')
    expect(fixture).toContain("'Cache-Control': 'private, no-store, max-age=0'")
    expect(fixture).not.toContain('confirmation_token:')
    expect(fixture).not.toContain('token_hash:')
  })
})
