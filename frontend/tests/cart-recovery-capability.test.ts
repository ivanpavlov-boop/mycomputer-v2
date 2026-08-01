import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import {
  isRecoveryCapability,
  readAndClearRecoveryCapability,
} from '../app/utils/cartRecoveryCapability'

const frontendRoot = resolve(import.meta.dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('abandoned Cart recovery capability boundary', () => {
  it('accepts only an exact 43-character Base64URL fragment and clears it synchronously', () => {
    const replaceState = vi.fn()
    const capability = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcde'
    const location = { hash: `#${capability}`, pathname: '/cart/recover' }
    const history = { state: { fixture: true }, replaceState }

    expect(capability).toHaveLength(43)
    expect(readAndClearRecoveryCapability(location, history)).toBe(capability)
    expect(replaceState).toHaveBeenCalledOnce()
    expect(replaceState).toHaveBeenCalledWith(history.state, '', '/cart/recover')
    expect(isRecoveryCapability(capability)).toBe(true)

    for (const invalid of [
      '',
      capability.slice(1),
      `${capability}x`,
      `${capability.slice(0, 42)}=`,
      `${capability.slice(0, 42)}.`,
    ]) {
      expect(isRecoveryCapability(invalid)).toBe(false)
    }
  })

  it('uses a clean route, clean API path, body-only capability, and safe metadata', () => {
    const page = source('app/pages/cart/recover/index.vue')
    const api = source('app/composables/useCartApi.ts')
    const middleware = source('app/middleware/cart-recovery.ts')

    expect(existsSync(resolve(frontendRoot, 'app/pages/cart/recover/[token].vue'))).toBe(false)
    expect(page).toContain('readAndClearRecoveryCapability(window.location, window.history)')
    expect(page.indexOf('readAndClearRecoveryCapability')).toBeLessThan(page.indexOf("await router.replace('/cart/recover')"))
    expect(page.indexOf("await router.replace('/cart/recover')")).toBeLessThan(page.indexOf('await cart.recover(capability)'))
    expect(page.indexOf('readAndClearRecoveryCapability')).toBeLessThan(page.indexOf('await cart.recover(capability)'))
    expect(api).toContain("cartRequest('/cart/recover'")
    expect(api).toContain('body: { capability }')
    expect(api).not.toContain('/cart/recover/${')
    expect(page).toContain("new URL('/cart/recover', config.public.siteUrl)")
    expect(page).toContain('noindex, nofollow, noarchive')
    expect(page).toContain("name: 'referrer', content: 'no-referrer'")
    expect(page).toContain("useResponseHeader('Cache-Control')")
    expect(page).not.toContain('route.params')
    expect(page).not.toContain('route.query')
    expect(page).not.toContain('useState')
    expect(page).not.toContain('localStorage')
    expect(page).not.toContain('sessionStorage')
    expect(page).not.toContain('useCookie')
    expect(page).not.toContain('console.')
    expect(middleware).toContain("config.public.abandonedCartRecoveryEnabled === 'true'")
    expect(middleware).toContain('!canStartCheckout.value')
  })

  it('uses one neutral Bulgarian error without exposing backend reasons', () => {
    const page = source('app/pages/cart/recover/index.vue')

    expect(page).toContain('failureMessage')
    expect(page).toContain('\\u041b\\u0438\\u043d\\u043a\\u044a\\u0442')
    expect(page).not.toContain('error.message')
    expect(page).not.toContain('cart_recovery_invalid')
    expect(page).not.toContain('cart_recovery_consumed')
    expect(page).not.toContain('cart_recovery_forbidden')
  })
})
