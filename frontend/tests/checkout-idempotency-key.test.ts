import { describe, expect, it, vi } from 'vitest'
import {
  generateCheckoutIdempotencyKey,
  shouldRetainCheckoutIdempotencyKey,
} from '../app/composables/useCheckoutIdempotency'

describe('checkout idempotency key', () => {
  it('uses Web Crypto to generate 32 random bytes as 43 Base64URL characters', () => {
    const getRandomValues = vi.fn((bytes: Uint8Array) => {
      expect(bytes).toHaveLength(32)
      bytes.forEach((_, index) => {
        bytes[index] = index
      })

      return bytes
    })

    const key = generateCheckoutIdempotencyKey({ getRandomValues })

    expect(getRandomValues).toHaveBeenCalledOnce()
    expect(key).toHaveLength(43)
    expect(key).toMatch(/^[A-Za-z0-9_-]{43}$/u)
  })

  it('retains only ambiguous failures for an explicit retry', () => {
    expect(shouldRetainCheckoutIdempotencyKey({ statusCode: null })).toBe(true)
    expect(shouldRetainCheckoutIdempotencyKey({ statusCode: 500 })).toBe(true)
    expect(shouldRetainCheckoutIdempotencyKey({ statusCode: 429 })).toBe(true)
    expect(shouldRetainCheckoutIdempotencyKey({ statusCode: 422 })).toBe(false)
    expect(shouldRetainCheckoutIdempotencyKey({ statusCode: 409 })).toBe(false)
  })
})
