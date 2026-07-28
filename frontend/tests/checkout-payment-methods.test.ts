import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { normalizeApiError } from '../app/utils/apiError'

const frontendRoot = resolve(import.meta.dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('checkout payment methods', () => {
  it('keeps COD and bank transfer selectable while leasing is disabled by default', () => {
    const fixture = source('test/browser/fixtures/cart-api-server.mjs')

    expect(fixture).toContain("code: 'cash_on_delivery'")
    expect(fixture).toContain("code: 'bank_transfer'")
    const paymentMethods = fixture.slice(
      fixture.indexOf("url.pathname === '/api/v1/payments/methods'"),
      fixture.indexOf("url.pathname === '/api/v1/shipping/offices'"),
    )

    expect(paymentMethods).toContain('if (state.scenario.leasing_enabled)')
    expect(paymentMethods).toContain("code: 'leasing'")
    expect(paymentMethods).not.toContain("code: 'card'")
  })

  it('submits the selected method through checkout only', () => {
    const checkout = source('app/pages/checkout/index.vue')

    expect(checkout).toContain('payment_method: \'cash_on_delivery\'')
    expect(checkout).toContain('await api.checkout(checkoutPayload, idempotencyKey)')
    expect(checkout).not.toContain('/payments/initiate')
  })

  it('uses a safe Bulgarian unavailable-method message', () => {
    const normalized = normalizeApiError({
      statusCode: 422,
      data: {
        error: {
          code: 'payment_method_unavailable',
          message: 'internal message must not be trusted',
        },
      },
    })

    expect(normalized.code).toBe('payment_method_unavailable')
    expect(normalized.message).toBe('Избраният начин на плащане не е наличен.')
  })
})
