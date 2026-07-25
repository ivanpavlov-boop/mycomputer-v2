import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('Cart mutation controls', () => {
  it('blocks duplicate logical operations while leaving keys scoped', () => {
    const store = source('app/stores/cart.ts')

    expect(store).toContain('if (isOperationPending(key))')
    expect(store).toContain('return null')
    expect(store).toContain('`add:${product.id}`')
    expect(store).toContain('`remove:${itemId}`')
    expect(store).toContain('`bundle:add:${bundleId}`')
    expect(store).toContain('`bundle:update:${bundleItemId}`')
    expect(store).toContain('`bundle:remove:${bundleItemId}`')
    expect(store).toContain("'coupon:apply'")
    expect(store).toContain("'coupon:remove'")
  })

  it('preserves confirmed Cart data until a mutation response is accepted', () => {
    const store = source('app/stores/cart.ts')

    const requestIndex = store.indexOf('const response = await request()')
    const assignmentIndex = store.indexOf('cart.value = nextCart')
    const beginBlock = store.slice(
      store.indexOf('function beginOperation'),
      store.indexOf('function finishOperation'),
    )

    expect(requestIndex).toBeGreaterThan(-1)
    expect(assignmentIndex).toBeGreaterThan(-1)
    expect(beginBlock).not.toMatch(/cart\.value\s*=(?!=)/)
    expect(store).toContain("status.value = cart.value === null ? 'error' : 'ready'")
  })

  it('gives remove, clear, coupon, bundle, and PC Builder controls pending feedback', () => {
    const controls = [
      source('app/components/cart/CartItem.vue'),
      source('app/components/cart/CartBundleItem.vue'),
      source('app/components/promotions/CouponInput.vue'),
      source('app/components/bundles/BundlePriceBox.vue'),
      source('app/pages/cart.vue'),
      source('app/pages/pc-builder/build/[id].vue'),
    ].join('\n')

    expect(controls).toContain('Премахване…')
    expect(controls).toContain('Изчистване…')
    expect(controls).toContain('Прилагане…')
    expect(controls).toContain('Добавяне…')
    expect(controls).toContain('aria-busy')
    expect(controls).toContain(':disabled=')
  })

  it('does not fabricate per-item analytics for clearing the Cart', () => {
    const store = source('app/stores/cart.ts')
    const clearBlock = store.slice(
      store.indexOf('async function clear()'),
      store.indexOf('async function addBundle'),
    )

    expect(clearBlock).toContain("runMutation('clear'")
    expect(clearBlock).not.toContain('useCartAnalytics')
    expect(clearBlock).not.toContain('removeFromCart')
  })
})
