import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const store = readFileSync(resolve(__dirname, '../app/stores/cart.ts'), 'utf8')

describe('Cart analytics safety', () => {
  it('emits add and remove analytics only after confirmed backend state', () => {
    const confirmedIndex = store.indexOf('const confirmed = await runMutation')
    const addAnalyticsIndex = store.indexOf('await useAnalytics().addToCart')
    const removeAnalyticsIndex = store.indexOf('await useAnalytics().removeFromCart')

    expect(confirmedIndex).toBeGreaterThan(-1)
    expect(addAnalyticsIndex).toBeGreaterThan(confirmedIndex)
    expect(removeAnalyticsIndex).toBeGreaterThan(confirmedIndex)
    expect(store).toContain('if (item && confirmedQuantity > 0)')
    expect(store).toContain('if (confirmed !== null && previous)')
  })

  it('uses authoritative Cart line prices and catalog currency without internal costs', () => {
    expect(store).toContain('Number(item.unit_price) * confirmedQuantity')
    expect(store).toContain('Number(previous.unit_price) * previous.quantity')
    expect(store).toContain('currency: confirmed.currency')
    expect(store).not.toContain('promo_price')
    expect(store).not.toContain('purchase_price')
    expect(store).not.toContain('supplier_price')
  })

  it('contains no fallback analytics path for failed mutations', () => {
    expect(store).not.toContain('backendAvailable')
    expect(store).not.toContain('lines.value')
    expect(store).not.toMatch(/catch[\s\S]{0,200}addToCart/)
    expect(store).not.toMatch(/catch[\s\S]{0,200}removeFromCart/)
  })
})
