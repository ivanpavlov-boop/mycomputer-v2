import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { normalizeApiError } from '../app/utils/apiError'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('Cart notifications', () => {
  it('uses Bulgarian loading, retry, success, and readiness copy', () => {
    const production = [
      source('app/pages/cart.vue'),
      source('app/components/cart/CartDrawer.vue'),
      source('app/components/cart/CartItem.vue'),
      source('app/components/bundles/BundlePriceBox.vue'),
      source('app/components/promotions/CouponInput.vue'),
    ].join('\n')

    expect(production).toContain('Зареждаме количката…')
    expect(production).toContain('Опитай отново')
    expect(production).toContain('Количеството е обновено.')
    expect(production).toContain('Комплектът е добавен в количката.')
    expect(production).toContain('Купонът е приложен.')
    expect(production).toContain('Количката съдържа продукти, които трябва да прегледате.')
  })

  it('uses safe Bulgarian mutation and review errors', () => {
    for (const code of [
      'cart_product_unavailable',
      'cart_quantity_unavailable',
      'cart_mutation_conflict',
      'cart_price_changed',
      'cart_promotion_changed',
    ]) {
      const message = normalizeApiError({ data: { error: { code, message: 'SQLSTATE secret' } } }).message

      expect(message).toMatch(/[А-Яа-я]/)
      expect(message).not.toContain('SQLSTATE')
    }
  })

  it('clears relevant errors after accepted operations and suppresses stale notifications', () => {
    const store = source('app/stores/cart.ts')

    expect(store).toContain('delete nextErrors[key]')
    expect(store).toContain('operationErrors.value = nextErrors')
    expect(store).toMatch(/token < latestAcceptedSequence\.value[\s\S]{0,80}return null/)
  })

  it('resets temporary add feedback deterministically', () => {
    const bundle = source('app/components/bundles/BundlePriceBox.vue')

    expect(bundle).toContain('scheduleFeedbackReset()')
    expect(bundle).toContain('setTimeout(clearFeedback, 3000)')
    expect(bundle).toContain('onBeforeUnmount(clearFeedback)')
  })
})
