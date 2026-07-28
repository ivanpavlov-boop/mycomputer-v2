import { describe, expect, it, vi } from 'vitest'
import {
  generatePaymentAttemptIdempotencyKey,
  shouldRetainPaymentAttemptKey,
} from '../app/utils/paymentAttemptIdempotency'

describe('payment attempt idempotency', () => {
  it('generates exactly 32 random bytes as a Base64URL key', () => {
    const getRandomValues = vi.fn((bytes: Uint8Array) => {
      expect(bytes).toHaveLength(32)
      bytes.fill(31)

      return bytes
    })

    const key = generatePaymentAttemptIdempotencyKey({ getRandomValues })

    expect(getRandomValues).toHaveBeenCalledOnce()
    expect(key).toHaveLength(43)
    expect(key).toMatch(/^[A-Za-z0-9_-]{43}$/u)
  })

  it('retains a key only when an explicit same-request retry is safe', () => {
    expect(shouldRetainPaymentAttemptKey({ statusCode: null })).toBe(true)
    expect(shouldRetainPaymentAttemptKey({ statusCode: 503 })).toBe(true)
    expect(shouldRetainPaymentAttemptKey({
      statusCode: 409,
      code: 'payment_attempt_in_progress',
    })).toBe(true)
    expect(shouldRetainPaymentAttemptKey({
      statusCode: 409,
      code: 'payment_idempotency_conflict',
    })).toBe(false)
    expect(shouldRetainPaymentAttemptKey({ statusCode: 422 })).toBe(false)
  })
})
