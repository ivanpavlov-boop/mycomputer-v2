import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const api = readFileSync(resolve(frontendRoot, 'app/composables/useCartApi.ts'), 'utf8')

describe('checkout idempotency API contract', () => {
  it('requires the key explicitly and preserves checkout credentials and identity headers', () => {
    expect(api).toContain('checkout: (body: Record<string, unknown>, idempotencyKey: string)')
    expect(api).toContain("'Idempotency-Key': idempotencyKey")
    expect(api).toContain("credentials: 'include'")
    expect(api).toContain("'X-Cart-Session': sentSession")
    expect(api).toContain('...auth.authHeaders()')
  })

  it('does not introduce automatic checkout retries', () => {
    const checkoutBlock = api.slice(api.indexOf('checkout:'), api.indexOf('checkout:') + 500)

    expect(checkoutBlock).not.toContain('retryInvalidSessionGet')
    expect(checkoutBlock).not.toContain('setTimeout')
    expect(checkoutBlock).not.toContain('setInterval')
  })
})
