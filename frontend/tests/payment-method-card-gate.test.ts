import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('payment method card launch gate', () => {
  it('renders only API-provided methods without a hardcoded card fallback', () => {
    const checkout = source('app/pages/checkout/index.vue')
    const selector = source('app/components/checkout/PaymentMethodSelect.vue')

    expect(checkout).toContain(':methods="paymentMethods"')
    expect(checkout).not.toContain("selectedPaymentMethod?.code === 'card'")
    expect(checkout).not.toContain('mock-card')
    expect(selector).toContain('v-for="method in methods"')
    expect(selector).not.toContain("value=\"card\"")
  })

  it('contains no cardholder-data controls or card storage', () => {
    const checkout = source('app/pages/checkout/index.vue')
    const combined = [
      checkout,
      source('app/composables/usePayments.ts'),
      source('app/utils/cartAnalytics.ts'),
    ].join('\n')

    expect(combined).not.toMatch(/card_number|cardNumber|cvv|cvc|expiry|cardholder|mock-card/i)
    expect(combined).not.toMatch(/localStorage.*card|sessionStorage.*card/i)
  })
})
