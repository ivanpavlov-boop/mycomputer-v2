import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('Checkout confirmation API client', () => {
  it('uses the dedicated cookie-authorized endpoint', () => {
    const composable = source('app/composables/useCheckoutConfirmation.ts')

    expect(composable).toContain("$fetch<ApiDataResponse<CheckoutConfirmation>>('/checkout/confirmation'")
    expect(composable).toContain("credentials: 'include'")
    expect(composable).toContain("useRequestHeaders(['cookie'])")
    expect(composable).toContain('config.apiServerBaseUrl')
    expect(composable).not.toMatch(/authorization|bearer|token/i)
    expect(composable).not.toContain('route.query')
  })

  it('keeps checkout credentials scoped to the checkout request', () => {
    const api = source('app/composables/useCartApi.ts')
    const checkout = api.slice(api.indexOf('checkout:'))

    expect(checkout).toContain("credentials: 'include'")
    expect(api.slice(0, api.indexOf('checkout:'))).not.toContain("credentials: 'include'")
  })
})
