import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('Cart analytics stale-response protection', () => {
  it('rejects stale successes and failures before state, errors, or analytics', () => {
    const store = source('app/stores/cart.ts')

    expect(store.match(/token < latestAcceptedSequence\.value/g)?.length).toBeGreaterThanOrEqual(4)
    expect(store).toContain('if (isAcceptedMutationCurrent(accepted))')
    expect(store).toContain('latestAcceptedSequence.value === accepted.sequence')
    expect(store).toContain('authorityVersion.value === accepted.authorityVersion')
  })

  it('deduplicates each accepted frontend operation in one analytics boundary', () => {
    const analytics = source('app/composables/useCartAnalytics.ts')

    expect(analytics).toContain('emittedOperations.value.includes(operationId)')
    expect(analytics).toContain('emittedOperations.value = [...emittedOperations.value, operationId]')
    expect(analytics).toContain('MAX_REMEMBERED_OPERATIONS')
    expect(analytics.match(/analytics\.addToCart/g)).toHaveLength(1)
    expect(analytics.match(/analytics\.removeFromCart/g)).toHaveLength(1)
    expect(analytics.match(/analytics\.beginCheckout/g)).toHaveLength(1)
  })

  it('discards pending analytics across an authentication authority change', () => {
    const store = source('app/stores/cart.ts')

    expect(store).toContain('authorityVersion.value += 1')
    expect(store).toContain('pendingOperations.value = {}')
    expect(store).toContain('operationErrors.value = {}')
    expect(store).toMatch(/authorityVersion\.value !== expectedAuthorityVersion[\s\S]{0,120}return null/)
  })

  it('does not duplicate analytics from components or reactive watchers', () => {
    const components = [
      source('app/components/cart/CartItem.vue'),
      source('app/components/cart/CartBundleItem.vue'),
      source('app/components/bundles/BundlePriceBox.vue'),
      source('app/components/promotions/CouponInput.vue'),
      source('app/pages/cart.vue'),
    ].join('\n')

    expect(components).not.toContain('useCartAnalytics')
    expect(components).not.toContain('.addToCart(')
    expect(components).not.toContain('.removeFromCart(')
  })
})
