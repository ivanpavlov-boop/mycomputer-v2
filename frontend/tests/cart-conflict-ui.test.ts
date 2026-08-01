import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { normalizeApiError } from '../app/utils/apiError'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

const expectedMessages: Record<string, string> = {
  cart_price_changed: 'Цената е променена. Прегледайте количката и опитайте отново.',
  cart_promotion_changed: 'Условията на промоцията са променени. Прегледайте количката.',
  cart_mutation_conflict: 'Количката беше променена по време на заявката. Опитайте отново.',
  cart_not_ready: 'Количката изисква преглед, преди да продължите.',
  cart_quantity_unavailable: 'Заявеното количество не е налично.',
  cart_product_unavailable: 'Продуктът вече не е наличен за покупка.',
  cart_gift_line_immutable: 'Подаръчният продукт се управлява от промоцията и не може да бъде променян.',
}

describe('Cart conflict UI', () => {
  it.each(Object.entries(expectedMessages))('maps %s distinctly', (code, message) => {
    expect(normalizeApiError({ data: { error: { code } } }).message).toBe(message)
  })

  it('summarizes important conflicts without discarding a confirmed Cart', () => {
    const store = source('app/stores/cart.ts')
    const page = source('app/pages/cart/index.vue')

    expect(store).toContain("'cart_price_changed'")
    expect(store).toContain("'cart_promotion_changed'")
    expect(store).toContain("'cart_mutation_conflict'")
    expect(store).toContain("status.value = cart.value === null ? 'error' : 'ready'")
    expect(page).toContain('cart.cartLevelError')
  })

  it('refreshes safely after checkout price or promotion review without replaying checkout', () => {
    const checkout = source('app/pages/checkout/index.vue')
    const catchBlock = checkout.slice(checkout.indexOf('} catch (failure)'), checkout.indexOf('} finally'))

    expect(catchBlock).toContain("'cart_price_changed', 'cart_promotion_changed'")
    expect(catchBlock).toContain('await cart.sync().catch(() => null)')
    expect(catchBlock).not.toContain('api.checkout')
    expect(catchBlock).not.toContain('beginCheckout')
  })
})
