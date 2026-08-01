import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('Cart recovery session rotation', () => {
  it('routes recovery through the authoritative store and Cart-bearing API client', () => {
    const page = source('app/pages/cart/recover/index.vue')
    const store = source('app/stores/cart.ts')
    const api = source('app/composables/useCartApi.ts')

    expect(page).toContain('await cart.recover(capability)')
    expect(page).not.toContain('cart.backendCart')
    expect(store).toContain("const accepted = await runMutation('recover', () => useCartApi().recover(capability))")
    expect(store).toContain('return accepted?.cart ?? null')
    expect(api).toContain("recover: (capability: string) => cartRequest('/cart/recover'")
    expect(api).toContain('body: { capability }')
    expect(api).toContain('cartSession.persist(normalized)')
  })

  it('preserves the previous confirmed Cart on a recovery conflict', () => {
    const store = source('app/stores/cart.ts')

    expect(store).toContain("status.value = cart.value === null ? 'error' : 'ready'")
    expect(store).not.toMatch(/catch \(failure\)[\s\S]{0,150}cart\.value = null/)
  })

  it('does not send recovery tokens to analytics or logs', () => {
    const page = source('app/pages/cart/recover/index.vue')
    const store = source('app/stores/cart.ts')

    expect(page).not.toContain('useAnalytics')
    expect(page).not.toContain('console.')
    expect(page).not.toContain('localStorage')
    expect(page).not.toContain('sessionStorage')
    expect(page).not.toContain('useCookie')
    expect(store).not.toMatch(/useAnalytics\(\)[^(]+\([^)]*capability/)
    expect(store).not.toContain('console.')
  })
})
