import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const page = readFileSync(resolve(frontendRoot, 'app/pages/checkout/index.vue'), 'utf8')
const successPage = readFileSync(resolve(frontendRoot, 'app/pages/checkout/success.vue'), 'utf8')

describe('checkout idempotency analytics safety', () => {
  it('emits checkout analytics only after one accepted response', () => {
    expect(page.indexOf('await api.checkout(form, idempotencyKey)'))
      .toBeLessThan(page.indexOf('await cartAnalytics.beginCheckout(operationId, confirmedCart)'))
    expect(page).toContain('await analytics.addPaymentInfo({ payment_method: form.payment_method, value: response.data.grand_total })')
  })

  it('keeps idempotency identity out of analytics and the success URL', () => {
    expect(page).not.toContain('analytics.addPaymentInfo({ idempotency')
    expect(page).not.toContain('cartAnalytics.beginCheckout(idempotency')
    expect(page).toContain("await router.push('/checkout/success')")
    expect(page).not.toContain("router.push({ path: '/checkout/success'")
    expect(successPage).not.toContain('idempotency')
  })
})
