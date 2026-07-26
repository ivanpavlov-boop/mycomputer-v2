import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { isValidCartSessionId, normalizeCartSessionId } from '../app/utils/cartSession'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('persistent Cart session identity', () => {
  it('normalizes canonical UUIDs and rejects malformed persisted values', () => {
    expect(normalizeCartSessionId('550E8400-E29B-41D4-A716-446655440000'))
      .toBe('550e8400-e29b-41d4-a716-446655440000')
    expect(isValidCartSessionId('550e8400-e29b-41d4-a716-446655440000')).toBe(true)
    expect(normalizeCartSessionId('')).toBeNull()
    expect(normalizeCartSessionId('   ')).toBeNull()
    expect(normalizeCartSessionId(' 550e8400-e29b-41d4-a716-446655440000')).toBeNull()
    expect(normalizeCartSessionId('not-a-cart-session')).toBeNull()
    expect(normalizeCartSessionId(null)).toBeNull()
  })

  it('uses one SSR-safe 14-day Nuxt cookie containing only the Cart UUID', () => {
    const composable = source('app/composables/useCartSession.ts')
    const config = source('nuxt.config.ts')

    expect(composable).toContain("const CART_SESSION_COOKIE = 'mc_cart_session'")
    expect(composable).toContain('useCookie<string | null>(CART_SESSION_COOKIE')
    expect(composable).toContain("path: '/'")
    expect(composable).toContain("sameSite: 'lax'")
    expect(composable).toContain("secure: String(config.public.cartCookieSecure) !== 'false'")
    expect(config).toContain("cartCookieSecure: process.env.NUXT_PUBLIC_CART_COOKIE_SECURE !== 'false'")
    expect(composable).toContain('httpOnly: false')
    expect(composable).toContain('14 * 24 * 60 * 60')
    expect(composable).toContain("useState<string | null>('cart-session-id', () => normalizedCookie)")
    expect(composable).not.toContain('localStorage')
    expect(composable).not.toContain('sessionStorage')
    expect(composable).not.toContain('document.cookie')
    expect(composable).not.toContain('randomUUID')
  })

  it('clears an invalid cookie before requests and persists valid backend rotation', () => {
    const session = source('app/composables/useCartSession.ts')
    const api = source('app/composables/useCartApi.ts')

    expect(session).toContain('cookie.value = null')
    expect(session).toContain('sessionId.value = null')
    expect(api).toContain("...(sentSession ? { 'X-Cart-Session': sentSession } : {})")
    expect(api).toContain('cartSession.persist(normalized)')
    expect(api.indexOf('cartSession.persist(normalized)')).toBeLessThan(api.indexOf('return response'))
  })
})
