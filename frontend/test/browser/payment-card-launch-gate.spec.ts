import { expect, test } from '@playwright/test'
import {
  fixtureState,
  fixtureUrl,
  resetFixture,
  seededSession,
  setCartCookie,
} from './helpers'

test.describe('payment card launch gate', () => {
  test.beforeEach(async ({ context, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
    })
    await setCartCookie(context)
  })

  test('shows launch methods without card UI or public initiation requests', async ({ page, request }) => {
    await page.goto('/checkout')

    await expect(page.locator('input[type="radio"][value="cash_on_delivery"]')).toBeVisible()
    await expect(page.locator('input[type="radio"][value="bank_transfer"]')).toBeVisible()
    await expect(page.locator('input[type="radio"][value="leasing"]')).toBeVisible()
    await expect(page.locator('input[type="radio"][value="card"]')).toHaveCount(0)
    await expect(page.locator('input[name*="card" i], input[autocomplete*="cc-" i]')).toHaveCount(0)

    const state = await fixtureState(request)
    expect(state.requests.filter(entry => entry.path === '/api/v1/payments/initiate')).toHaveLength(0)
    expect(state.orders_created).toBe(0)
    expect(state.payment_attempts).toBe(0)
  })

  test('rejects a manually injected card checkout without side effects', async ({ page, request }) => {
    await page.goto('/checkout')

    const result = await page.evaluate(async ({ apiUrl, session }) => {
      const response = await fetch(`${apiUrl}/api/v1/checkout`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'Idempotency-Key': 'A'.repeat(43),
          'X-Cart-Session': session,
        },
        body: JSON.stringify({
          first_name: 'Test',
          last_name: 'Customer',
          email: 'customer@example.test',
          phone: '+359888123456',
          billing_address: 'Test address 1',
          shipping_address: 'Test address 2',
          shipping_provider: 'manual',
          shipping_method: 'address',
          delivery_type: 'address',
          city: 'Sofia',
          postcode: '1000',
          payment_method: 'card',
          notes: '',
          terms: true,
        }),
      })

      return {
        status: response.status,
        body: await response.json(),
      }
    }, { apiUrl: fixtureUrl, session: seededSession })

    expect(result.status).toBe(422)
    expect(result.body.error.code).toBe('payment_method_unavailable')
    expect(result.body.error.message).toBe('Избраният начин на плащане не е наличен.')

    const state = await fixtureState(request)
    expect(state.orders_created).toBe(0)
    expect(state.payment_attempts).toBe(0)
    expect(state.confirmation_capabilities).toBe(0)
    expect(state.checkout_identities).toHaveLength(0)
  })
})
