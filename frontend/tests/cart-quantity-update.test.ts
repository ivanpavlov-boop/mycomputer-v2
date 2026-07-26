import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')
const item = source('app/components/cart/CartItem.vue')
const store = source('app/stores/cart.ts')

describe('Cart quantity update', () => {
  it('uses an explicit draft submission separate from confirmed quantity', () => {
    expect(item).toContain('const draftQuantity = ref(String(props.item.quantity))')
    expect(item).toContain('@submit.prevent="submitQuantity"')
    expect(item).toContain('parsedQuantity.value !== props.item.quantity')
    expect(item).toContain('await cart.update(props.item.id, parsedQuantity.value)')
    expect(item).not.toContain('cart.items')
    expect(item).not.toContain('props.item.quantity =')
  })

  it('validates shape locally while the backend remains quantity authority', () => {
    expect(item).toContain('Number.isInteger(parsedQuantity.value)')
    expect(item).toContain('parsedQuantity.value >= 1')
    expect(item).toContain('parsedQuantity.value <= 20')
    expect(item).toContain('confirmed.items.find')
    expect(item).toContain('watch(() => props.item.quantity')
  })

  it('blocks duplicate updates and clears pending on success or error', () => {
    expect(store).toContain('`update:${itemId}`')
    expect(store).toContain('if (isOperationPending(key))')
    expect(store).toContain('finally {')
    expect(store).toContain('finishOperation(key, token)')
    expect(item).toContain(':disabled="!canSubmitQuantity"')
    expect(item).toContain("updatePending ? 'Обновяване…' : 'Обнови'")
  })

  it('keeps promotional gift quantities immutable', () => {
    expect(item).toContain('v-if="!item.is_gift"')
    expect(item).toContain('Подарък от активна промоция')
    expect(item).toContain('Количеството и премахването се управляват от промоцията.')
  })
})
