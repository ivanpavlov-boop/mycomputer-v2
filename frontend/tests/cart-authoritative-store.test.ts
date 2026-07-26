import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('authoritative Cart store', () => {
  it('has one backend Cart state and no local content fallback', () => {
    const store = source('app/stores/cart.ts')

    expect(store).toContain('const cart = ref<CartResponse | null>(null)')
    expect(store).toContain("export type CartRequestStatus = 'idle' | 'loading' | 'ready' | 'mutating' | 'error'")
    expect(store).not.toContain('CartLine')
    expect(store).not.toContain('backendAvailable')
    expect(store).not.toContain('backendCart')
    expect(store).not.toContain('lines.value')
    expect(store).not.toContain('promo_price ||')
  })

  it('derives every displayed Cart dimension from the confirmed response', () => {
    const store = source('app/stores/cart.ts')

    for (const expected of [
      'cart.value?.items ?? []',
      'cart.value?.bundle_items ?? []',
      'cart.value?.gift_products ?? []',
      'cart.value?.coupon_code ?? null',
      'cart.value?.readiness ?? null',
      'cart.value?.items_count ?? 0',
      'cart.value?.subtotal ?? 0',
    ]) {
      expect(store).toContain(expected)
    }
  })

  it('replaces state only after success and preserves a confirmed Cart on failure', () => {
    const store = source('app/stores/cart.ts')

    expect(store).toContain('const response = await request()')
    expect(store).toContain('acceptConfirmedCart(response.data, key, token)')
    expect(store).toContain("status.value = cart.value === null ? 'error' : 'ready'")
    expect(store).not.toContain('cart.value = null\n    error.value = normalizeApiError')
  })

  it('tracks per-operation pending keys and blocks duplicate logical mutations', () => {
    const store = source('app/stores/cart.ts')

    expect(store).toContain('const pendingOperations = ref<Record<string, number>>({})')
    expect(store).toContain('if (isOperationPending(key))')
    expect(store).toContain('return null')
    expect(store).toContain('`add:${product.id}`')
    expect(store).toContain('`update:${itemId}`')
    expect(store).toContain('`remove:${itemId}`')
    expect(store).toContain("'clear'")
    expect(store).toContain("'coupon:apply'")
    expect(store).toContain("'coupon:remove'")
    expect(store).toContain('delete nextPending[key]')
  })

  it('keeps cart pages and controls on the authoritative contract', () => {
    const combined = [
      source('app/components/cart/CartDrawer.vue'),
      source('app/components/cart/CartItem.vue'),
      source('app/pages/cart.vue'),
      source('app/pages/checkout/index.vue'),
    ].join('\n')

    expect(combined).toContain('cart.isInitialLoading')
    expect(combined).toContain('cart.isOperationPending')
    expect(combined).toContain('cart.readiness')
    expect(combined).toContain('item.is_gift')
    expect(combined).toContain('Опитай отново')
    expect(combined).not.toContain('backendItems')
    expect(combined).not.toContain('backendCart')
    expect(combined).not.toContain('cart.lines')
  })
})
