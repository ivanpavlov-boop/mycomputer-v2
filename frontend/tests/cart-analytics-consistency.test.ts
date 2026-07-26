import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { CartResponse } from '../app/types/api'
import {
  beginCheckoutEvent,
  bundleAddedEvent,
  bundleRemovedEvent,
  productAddedEvent,
  productQuantityEvent,
  productRemovedEvent,
} from '../app/utils/cartAnalytics'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

function cart(options: {
  productQuantity?: number
  includeProduct?: boolean
  bundleQuantity?: number
  includeBundle?: boolean
  ready?: boolean
  currency?: string
} = {}): CartResponse {
  const {
    productQuantity = 1,
    includeProduct = true,
    bundleQuantity = 1,
    includeBundle = false,
    ready = true,
    currency = 'EUR',
  } = options

  return {
    id: 42,
    cart_session_id: 'must-not-leak',
    status: 'active',
    currency,
    coupon_code: null,
    items: includeProduct
      ? [{
          id: 10,
          product_id: 7,
          quantity: productQuantity,
          is_gift: false,
          promotion_id: null,
          unit_price: '25.50',
          total_price: String(25.5 * productQuantity),
          product: { id: 7, sku: 'SAFE-SKU' },
          readiness: null,
        } as never]
      : [],
    bundle_items: includeBundle
      ? [{
          id: 20,
          bundle_id: 9,
          bundle_name: 'Safe bundle',
          selected_items: [],
          quantity: bundleQuantity,
          unit_price: '50.00',
          total_price: String(50 * bundleQuantity),
          original_price: '55.00',
          savings: '5.00',
          readiness: null,
        }]
      : [],
    items_count: (includeProduct ? productQuantity : 0) + (includeBundle ? bundleQuantity : 0),
    subtotal: '100.00',
    applied_promotions: [],
    promotion_discount_total: '0',
    shipping_discount: '0',
    gift_products: [],
    readiness: {
      can_checkout: ready,
      issues_count: ready ? 0 : 1,
      has_product_issues: !ready,
      has_stock_issues: false,
    },
    expires_at: null,
  }
}

describe('Cart analytics consistency', () => {
  it('builds an add event from the authoritative accepted quantity delta', () => {
    const event = productAddedEvent(cart({ productQuantity: 1 }), cart({ productQuantity: 3, currency: 'BGN' }), 7)

    expect(event).toEqual({
      name: 'add_to_cart',
      payload: {
        product_id: 7,
        sku: 'SAFE-SKU',
        quantity: 2,
        unit_price: 25.5,
        value: 51,
        currency: 'BGN',
      },
    })
    expect(productAddedEvent(cart(), cart(), 7)).toBeNull()
  })

  it('proves removal against the returned Cart and uses pre-mutation values', () => {
    const event = productRemovedEvent(cart({ productQuantity: 2 }), cart({ includeProduct: false }), 10)

    expect(event?.name).toBe('remove_from_cart')
    expect(event?.payload).toMatchObject({ product_id: 7, quantity: 2, value: 51, currency: 'EUR' })
    expect(productRemovedEvent(cart(), cart(), 10)).toBeNull()
  })

  it('represents quantity deltas with the existing add/remove events', () => {
    expect(productQuantityEvent(cart({ productQuantity: 1 }), cart({ productQuantity: 4 }), 10))
      .toMatchObject({ name: 'add_to_cart', payload: { quantity: 3 } })
    expect(productQuantityEvent(cart({ productQuantity: 4 }), cart({ productQuantity: 2 }), 10))
      .toMatchObject({ name: 'remove_from_cart', payload: { quantity: 2 } })
    expect(productQuantityEvent(cart(), cart(), 10)).toBeNull()
  })

  it('supports confirmed bundle add and proven bundle removal', () => {
    expect(bundleAddedEvent(cart(), cart({ includeBundle: true, bundleQuantity: 2 }), 9))
      .toMatchObject({ name: 'add_to_cart', payload: { bundle_id: 9, quantity: 2, value: 100 } })
    expect(bundleRemovedEvent(
      cart({ includeBundle: true }),
      cart({ includeBundle: false }),
      20,
    )).toMatchObject({ name: 'remove_from_cart', payload: { bundle_id: 9, quantity: 1 } })
  })

  it('emits begin checkout only for a confirmed ready Cart', () => {
    expect(beginCheckoutEvent(cart({ ready: true }))).toMatchObject({
      name: 'begin_checkout',
      payload: { value: 100, currency: 'EUR' },
    })
    expect(beginCheckoutEvent(cart({ ready: false }))).toBeNull()
  })

  it('keeps Cart identity, recovery, Supplier, and internal costs out of payload builders', () => {
    const events = source('app/utils/cartAnalytics.ts')
    const store = source('app/stores/cart.ts')
    const checkout = source('app/pages/checkout/index.vue')

    for (const forbidden of [
      'cart_session_id',
      'recovery',
      'supplier',
      'purchase_price',
      'supplier_price',
      'margin',
      'source_payload',
      'email',
    ]) {
      expect(events.toLowerCase()).not.toContain(forbidden)
    }

    expect(store).not.toContain('viewCart(')
    expect(store.slice(store.indexOf('async function clear()'), store.indexOf('async function addBundle')))
      .not.toContain('useCartAnalytics')
    expect(checkout).not.toMatch(/onMounted\([^)]*beginCheckout/)
  })
})
