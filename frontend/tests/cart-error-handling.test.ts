import { describe, expect, it } from 'vitest'
import { GENERIC_API_ERROR_MESSAGE, normalizeApiError } from '../app/utils/apiError'

const codes = [
  'invalid_cart_session',
  'cart_product_unavailable',
  'cart_quantity_unavailable',
  'cart_not_ready',
  'cart_price_changed',
  'cart_promotion_changed',
  'cart_mutation_conflict',
  'cart_gift_line_immutable',
  'cart_recovery_consumed',
  'cart_recovery_invalid',
  'cart_recovery_forbidden',
  'cart_recovery_requires_review',
]

describe('Cart API error normalization', () => {
  it.each(codes)('maps %s to safe Bulgarian copy', code => {
    const error = normalizeApiError({
      statusCode: code === 'invalid_cart_session' ? 422 : 409,
      data: {
        error: {
          code,
          message: `unsafe backend message ${code}`,
          details: {
            available_quantity: 2,
            cart_session_id: '550e8400-e29b-41d4-a716-446655440000',
            token: 'recovery-secret',
          },
        },
      },
    })

    expect(error.code).toBe(code)
    expect(error.message).toMatch(/[А-Яа-я]/)
    expect(error.message).not.toContain('unsafe backend')
    expect(error.message).not.toContain('550e8400')
    expect(error.message).not.toContain('recovery-secret')
    expect(error.details).toEqual({ available_quantity: 2 })
  })

  it('uses generic Bulgarian copy for unknown and network failures', () => {
    expect(normalizeApiError(new Error('SQLSTATE secret')).message).toBe(GENERIC_API_ERROR_MESSAGE)
    expect(normalizeApiError({ data: { message: 'raw internal failure' } }).message).toBe(GENERIC_API_ERROR_MESSAGE)
  })
})
