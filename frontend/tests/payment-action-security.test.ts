import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const actionPanel = readFileSync(
  resolve(frontendRoot, 'app/components/payments/PaymentActionPanel.vue'),
  'utf8',
)

describe('Payment action UI security', () => {
  it('does not read capabilities, idempotency keys, or browser storage', () => {
    for (const forbidden of [
      'mc_checkout_confirmation',
      'mc_payment_retry',
      'Idempotency-Key',
      'localStorage',
      'sessionStorage',
      'document.cookie',
      'console.',
    ]) {
      expect(actionPanel).not.toContain(forbidden)
    }
  })

  it('keeps ambiguous results fail-closed', () => {
    expect(actionPanel).toContain('payment_attempt_in_progress')
    expect(actionPanel).toContain('payment_result_indeterminate')
    expect(actionPanel).toContain('payment_provider_failed')
    expect(actionPanel).not.toContain('onMounted')
    expect(actionPanel).not.toContain('watchEffect')
  })

  it('uses a generic Bulgarian message when retry capability is unavailable', () => {
    expect(actionPanel).toContain('payment_retry_unavailable')
    expect(actionPanel).toContain('Свържете се с нас')
    expect(actionPanel).not.toContain('token')
    expect(actionPanel).not.toContain('capability')
  })
})
