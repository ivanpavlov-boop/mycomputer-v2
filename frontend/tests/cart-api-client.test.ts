import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('Cart API client', () => {
  it('merges auth caller and Cart session headers without a second session source', () => {
    const api = source('app/composables/useCartApi.ts')

    expect(api).toContain('const cartSession = useCartSession()')
    expect(api).toContain('...auth.authHeaders()')
    expect(api).toContain('...callerHeaders')
    expect(api).toContain("'X-Cart-Session': sentSession")
    expect(api).not.toContain("useState<string | null>('cart-session-id'")
  })

  it('retries only an invalid-session idempotent GET once', () => {
    const api = source('app/composables/useCartApi.ts')

    expect(api).toContain("normalized.code === 'invalid_cart_session'")
    expect(api).toContain('&& !hasRetried')
    expect(api).toContain('cartSession.clear()')
    expect(api).toContain("get: () => cartRequest('/cart', {}, true)")
    expect(api).toContain("add: (productId: number, quantity: number) => cartRequest('/cart/items'")
    expect(api).not.toContain("cartRequest('/cart/items', {}, true)")
  })

  it('requires valid sessions for Cart responses and only accepts checkout rotation when present', () => {
    const api = source('app/composables/useCartApi.ts')

    expect(api).toContain("sessionResponse: 'required'")
    expect(api).toContain('throw invalidCartSessionResponseError()')
    expect(api).toContain("sessionResponse: 'if-present'")
    expect(api).toContain("checkout: (body: Record<string, unknown>)")
  })
})
