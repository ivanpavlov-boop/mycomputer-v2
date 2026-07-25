import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (path: string) => readFileSync(resolve(__dirname, `../${path}`), 'utf8')
const store = source('app/stores/cart.ts')
const analytics = source('app/composables/useCartAnalytics.ts')
const events = source('app/utils/cartAnalytics.ts')

describe('Cart analytics safety', () => {
  it('emits add and remove analytics only after confirmed backend state', () => {
    const acceptedIndex = store.indexOf('const accepted = await runMutation')
    const currentIndex = store.indexOf('if (isAcceptedMutationCurrent(accepted))')
    const addAnalyticsIndex = store.indexOf('await useCartAnalytics().productAdded')
    const removeAnalyticsIndex = store.indexOf('await useCartAnalytics().productRemoved')

    expect(acceptedIndex).toBeGreaterThan(-1)
    expect(currentIndex).toBeGreaterThan(acceptedIndex)
    expect(addAnalyticsIndex).toBeGreaterThan(currentIndex)
    expect(removeAnalyticsIndex).toBeGreaterThan(currentIndex)
    expect(analytics).toContain('emitOnce(operationId, productAddedEvent')
    expect(analytics).toContain('emitOnce(operationId, productRemovedEvent')
  })

  it('uses authoritative Cart line prices and catalog currency without internal costs', () => {
    expect(events).toContain('unitPrice = Number(line.unit_price)')
    expect(events).toContain('value: unitPrice * quantity')
    expect(events).toContain('currency,')
    expect(events).not.toContain('promo_price')
    expect(events).not.toContain('purchase_price')
    expect(events).not.toContain('supplier_price')
  })

  it('contains no fallback analytics path for failed mutations', () => {
    const production = [store, analytics, events].join('\n')

    expect(production).not.toContain('backendAvailable')
    expect(production).not.toContain('lines.value')
    expect(store).not.toMatch(/catch[\s\S]{0,300}productAdded/)
    expect(store).not.toMatch(/catch[\s\S]{0,300}productRemoved/)
  })
})
