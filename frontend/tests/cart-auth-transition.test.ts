import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('Cart authentication transitions', () => {
  it('keeps the guest cookie and delegates login convergence to the backend', () => {
    const auth = source('app/stores/auth.ts')
    const api = source('app/composables/useCartApi.ts')

    expect(auth).toContain('setSession(response.data.token, response.data.user)')
    expect(auth).toContain('cart.markUnresolvedForAuthTransition()')
    expect(auth).toContain('await cart.sync().catch(() => null)')
    expect(auth).not.toContain('mergeCart')
    expect(auth).not.toContain('cartSession.clear')
    expect(api).toContain('...auth.authHeaders()')
    expect(api).toContain("'X-Cart-Session': sentSession")
  })

  it('clears stale rendered content on logout but preserves the Cart capability', () => {
    const auth = source('app/stores/auth.ts')
    const store = source('app/stores/cart.ts')

    expect(auth).toContain('useCartStore().markUnresolvedForAuthTransition()')
    expect(store).toContain('function markUnresolvedForAuthTransition()')
    expect(store).toContain('cart.value = null')
    expect(store).toContain("lastOperation.value = 'auth-transition'")
    expect(store).not.toContain('useCartSession().clear')
  })

  it('ignores stale in-flight responses across user switches', () => {
    const store = source('app/stores/cart.ts')

    expect(store).toContain('const authorityVersion = ref(0)')
    expect(store).toContain('authorityVersion.value += 1')
    expect(store).toContain('const expectedAuthorityVersion = authorityVersion.value')
    expect(store).toContain('if (authorityVersion.value !== expectedAuthorityVersion)')
  })
})
