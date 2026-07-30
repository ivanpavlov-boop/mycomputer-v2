import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('Cart API SSR base URL', () => {
  it('uses the private runtime base on the server with a public fallback', () => {
    const api = source('app/composables/useCartApi.ts')

    expect(api).toContain('const baseURL = import.meta.server')
    expect(api).toContain('? String(config.apiServerBaseUrl || config.public.apiBaseUrl)')
    expect(api).toContain(': config.public.apiBaseUrl')
  })

  it('keeps the browser on the public runtime base without exposing the private base', () => {
    const api = source('app/composables/useCartApi.ts')
    const config = source('nuxt.config.ts')

    expect(config).toContain('apiServerBaseUrl: process.env.NUXT_API_SERVER_BASE_URL')
    expect(config).toContain('apiBaseUrl: process.env.NUXT_PUBLIC_API_BASE_URL')
    expect(config).not.toContain('apiBaseUrl: process.env.NUXT_API_SERVER_BASE_URL')
    expect(api).not.toMatch(/:\s*config\.apiServerBaseUrl/)
  })

  it('preserves Cart identity, retry, validation and checkout idempotency contracts', () => {
    const api = source('app/composables/useCartApi.ts')

    expect(api).toContain("'X-Cart-Session': sentSession")
    expect(api).toContain("normalized.code === 'invalid_cart_session'")
    expect(api).toContain('&& !hasRetried')
    expect(api).toContain("sessionResponse: 'required'")
    expect(api).toContain('throw invalidCartSessionResponseError()')
    expect(api).toContain("'Idempotency-Key': idempotencyKey")
  })

  it('does not log Cart session, cookie or request header values', () => {
    const api = source('app/composables/useCartApi.ts')

    expect(api).not.toMatch(/console\.(?:log|error)|logger\(|\bLog::|dump\(|dd\(/)
  })
})
