import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const successPage = readFileSync(
  resolve(frontendRoot, 'app/pages/checkout/success.vue'),
  'utf8',
)
const actionPanel = readFileSync(
  resolve(frontendRoot, 'app/components/payments/PaymentActionPanel.vue'),
  'utf8',
)

describe('Checkout payment acceptance UI', () => {
  it('uses the shared guest payment action presentation', () => {
    expect(successPage).toContain(':presentation="confirmation.payment.presentation"')
    expect(successPage).toContain('mode="guest"')
    expect(successPage).not.toContain('redirect_url')
    expect(successPage).not.toContain('bank_instructions')
    expect(successPage).not.toContain('leasing_status')
  })

  it('keeps purchase analytics separate from payment actions', () => {
    expect(successPage).toContain('analytics.purchase')
    expect(actionPanel).not.toContain('trackPurchase')
    expect(actionPanel).not.toContain('analytics')
  })

  it('never retries or redirects automatically', () => {
    expect(actionPanel).toContain('@click="retryPayment"')
    expect(actionPanel).not.toContain('onMounted')
    expect(actionPanel).not.toContain('setTimeout')
    expect(actionPanel).not.toContain('setInterval')
    expect(actionPanel).not.toContain('window.location')
    expect(actionPanel).not.toContain('navigateTo')
    expect(readFileSync(
      resolve(frontendRoot, 'app/composables/usePaymentAttempts.ts'),
      'utf8',
    )).toContain('retry: 0')
  })
})
