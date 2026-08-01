import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { CartLineReadiness } from '../app/types/api'
import { cartReadinessMessage } from '../app/utils/cartReadiness'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

const knownCodes = [
  'cart_inactive',
  'cart_no_paid_items',
  'product_missing',
  'product_deleted',
  'product_inactive',
  'product_unpublished',
  'product_status_inactive',
  'product_slug_missing',
  'product_category_unavailable',
  'product_purchase_disabled',
  'insufficient_stock',
  'bundle_unavailable',
  'bundle_selection_invalid',
  'bundle_product_unavailable',
  'bundle_insufficient_stock',
]

const readiness = (maximum: number | null = null): CartLineReadiness => ({
  is_eligible: false,
  can_checkout: false,
  issues: [],
  stock: {
    tracked: true,
    requested_quantity: 5,
    available_quantity: maximum,
    max_purchasable_quantity: maximum,
    is_sufficient: false,
  },
})

describe('Cart readiness UI', () => {
  it.each(knownCodes)('maps %s to safe Bulgarian copy', code => {
    const message = cartReadinessMessage({ code, message: 'internal model state' }, readiness())

    expect(message).toMatch(/[А-Яа-я]/)
    expect(message).not.toContain('internal model state')
    expect(message).not.toContain(code)
  })

  it('shows a backend-provided safe maximum without inferring stock', () => {
    expect(cartReadinessMessage(
      { code: 'insufficient_stock', message: 'hidden stock' },
      readiness(3),
    )).toContain('до 3 бр.')
    expect(cartReadinessMessage(
      { code: 'insufficient_stock', message: 'hidden stock' },
      readiness(null),
    )).not.toContain('до ')
  })

  it('renders an actionable Cart summary and keeps invalid paid lines removable', () => {
    const page = source('app/pages/cart/index.vue')
    const item = source('app/components/cart/CartItem.vue')
    const bundle = source('app/components/cart/CartBundleItem.vue')

    expect(page).toContain('Количката съдържа продукти, които трябва да прегледате.')
    expect(page).toContain('readinessProblems')
    expect(page).toContain('v-if="cart.canCheckout && !cart.isMutating"')
    expect(item).toContain('Премахни')
    expect(bundle).toContain('readinessMessages')
  })
})
