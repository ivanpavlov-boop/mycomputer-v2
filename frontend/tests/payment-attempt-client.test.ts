import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const client = readFileSync(
  resolve(frontendRoot, 'app/composables/usePaymentAttempts.ts'),
  'utf8',
)

describe('payment attempt client', () => {
  it('uses only the two approved endpoints with an empty body and credentials', () => {
    expect(client).toContain('/account/orders/${encodeURIComponent(String(orderId))}/payment-attempts')
    expect(client).toContain("request('/checkout/payment-attempts')")
    expect(client).toContain('body: {}')
    expect(client).toContain("credentials: 'include'")
    expect(client).toContain("'Idempotency-Key': key")
  })

  it('does not persist, log, retry automatically, or render a payment action', () => {
    expect(client).not.toContain('localStorage')
    expect(client).not.toContain('sessionStorage')
    expect(client).not.toContain('useAnalytics')
    expect(client).not.toContain('console.')
    expect(client).not.toContain('setTimeout')
    expect(client).not.toContain('setInterval')
    expect(client).not.toContain('retry(')
  })
})
