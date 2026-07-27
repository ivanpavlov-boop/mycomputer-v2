import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const page = readFileSync(resolve(frontendRoot, 'app/pages/checkout/index.vue'), 'utf8')
const keyComposable = readFileSync(
  resolve(frontendRoot, 'app/composables/useCheckoutIdempotency.ts'),
  'utf8',
)

describe('checkout page idempotency lifecycle', () => {
  it('keeps the pending-click guard and creates the key immediately before checkout', () => {
    expect(page).toContain('if (!cart.cart || !cart.canCheckout || submitting.value)')
    expect(page).toContain('const idempotencyKey = checkoutIdempotency.keyForAttempt()')
    expect(page).toContain('await api.checkout(form, idempotencyKey)')
    expect(page.indexOf('keyForAttempt()')).toBeLessThan(page.indexOf('api.checkout(form, idempotencyKey)'))
  })

  it('clears on success, definitive failure, form edits, and Cart changes', () => {
    expect(page).toContain('if (!shouldRetainCheckoutIdempotencyKey(normalized))')
    expect(page).toContain('watch(form, () => checkoutIdempotency.clear(), { deep: true })')
    expect(page).toContain('watch(cartCheckoutIdentity')
    expect(page).toContain("await router.push('/checkout/success')")
    expect(page.indexOf('checkoutIdempotency.clear()')).toBeLessThan(page.indexOf('await cartAnalytics.beginCheckout'))
  })

  it('keeps the key only in page memory', () => {
    expect(keyComposable).toContain('const activeKey = ref<string | null>(null)')
    expect(keyComposable).not.toContain('localStorage')
    expect(keyComposable).not.toContain('sessionStorage')
    expect(keyComposable).not.toContain('useCookie')
    expect(keyComposable).not.toContain('useState')
    expect(keyComposable).not.toContain('route.query')
    expect(keyComposable).not.toContain('Math.random')
  })
})
