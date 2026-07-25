import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('Cart SSR and hydration isolation', () => {
  it('initializes shared in-memory state from the request cookie', () => {
    const session = source('app/composables/useCartSession.ts')

    expect(session).toContain('const normalizedCookie = normalizeCartSessionId(cookie.value)')
    expect(session).toContain("useState<string | null>('cart-session-id', () => normalizedCookie)")
    expect(session).toContain('sessionId.value = normalizedCookie')
  })

  it('uses request-scoped Nuxt primitives without browser-only initialization', () => {
    const session = source('app/composables/useCartSession.ts')

    expect(session).toContain('useCookie')
    expect(session).toContain('useState')
    expect(session).not.toContain('window')
    expect(session).not.toContain('document')
    expect(session).not.toContain('localStorage')
    expect(session).not.toContain('sessionStorage')
  })

  it('does not keep mutable Cart session state at module scope', () => {
    const session = source('app/composables/useCartSession.ts')

    expect(session).not.toMatch(/^let\s+/m)
    expect(session).not.toMatch(/^const\s+\w+\s*=\s*ref\(/m)
    expect(session).not.toMatch(/^const\s+\w+\s*=\s*reactive\(/m)
  })
})
