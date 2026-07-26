import { describe, expect, it } from 'vitest'
import {
  FIXTURE_CART_KEYS,
  createFixtureState,
  makeCartFixture,
  safeError,
  sanitizeAnalyticsEvent,
} from '../test/browser/fixtures/cart-fixture.mjs'

describe('Cart browser fixture contract', () => {
  it('matches the approved Cart response envelope', () => {
    const cart = makeCartFixture('11111111-1111-4111-8111-111111111111', 'product')

    expect(Object.keys(cart)).toEqual(expect.arrayContaining(FIXTURE_CART_KEYS))
    expect(cart.items[0]).toMatchObject({
      id: expect.any(Number),
      product_id: expect.any(Number),
      quantity: expect.any(Number),
      is_gift: false,
      unit_price: expect.any(String),
      total_price: expect.any(String),
      product: {
        id: expect.any(Number),
        sku: expect.any(String),
        name: expect.any(String),
        slug: expect.any(String),
      },
      readiness: {
        is_eligible: true,
        can_checkout: true,
        issues: [],
        stock: {
          requested_quantity: expect.any(Number),
          is_sufficient: true,
        },
      },
    })
  })

  it('provides deterministic gift, bundle, and blocked-readiness variants', () => {
    const gift = makeCartFixture('11111111-1111-4111-8111-111111111111', 'gift')
    const bundle = makeCartFixture('22222222-2222-4222-8222-222222222222', 'bundle')
    const blocked = makeCartFixture(
      '33333333-3333-4333-8333-333333333333',
      'blocked',
      'insufficient_stock',
    )

    expect(gift.items.find(item => item.is_gift)).toMatchObject({
      promotion_id: expect.any(Number),
      unit_price: '0.00',
    })
    expect(gift.gift_products).toHaveLength(1)
    expect(bundle.bundle_items[0]).toMatchObject({
      bundle_id: expect.any(Number),
      selected_items: expect.any(Array),
      readiness: {
        can_checkout: true,
      },
    })
    expect(blocked.readiness).toMatchObject({
      can_checkout: false,
      has_product_issues: true,
      has_stock_issues: true,
    })
  })

  it('returns a safe error envelope', () => {
    const error = safeError('cart_mutation_conflict', 409, {
      retry_after: 1,
    })

    expect(error).toEqual({
      status: 409,
      body: {
        message: 'Fixture request failed.',
        error: {
          code: 'cart_mutation_conflict',
          message: 'Fixture request failed.',
          details: {
            retry_after: 1,
          },
        },
      },
    })
  })

  it('redacts sensitive analytics fields and request diagnostics', () => {
    const analytics = sanitizeAnalyticsEvent({
      event_name: 'add_to_cart',
      source: 'ga4',
      payload: {
        product_id: 101,
        value: 999.9,
        currency: 'EUR',
        cart_session_id: '11111111-1111-4111-8111-111111111111',
        customer_email: 'customer@example.test',
        supplier_id: 99,
        supplier_price: 500,
        purchase_price: 400,
        margin: 20,
        authorization: 'Bearer fixture-token',
      },
    })
    const state = createFixtureState()
    state.recordRequest({
      method: 'GET',
      path: '/api/v1/cart',
      cartSession: '11111111-1111-4111-8111-111111111111',
      bearerToken: 'fixture-token-user-a',
      origin: 'http://127.0.0.1:3000',
    })

    expect(analytics).toEqual({
      event_name: 'add_to_cart',
      source: 'ga4',
      payload: {
        product_id: 101,
        value: 999.9,
        currency: 'EUR',
      },
    })
    expect(state.snapshot().requests[0]).toEqual({
      method: 'GET',
      path: '/api/v1/cart',
      cart_session: '11111111-1111-4111-8111-111111111111',
      authorization_present: true,
      origin: 'http://127.0.0.1:3000',
    })
    expect(JSON.stringify(state.snapshot())).not.toContain('fixture-token-user-a')
  })
})
