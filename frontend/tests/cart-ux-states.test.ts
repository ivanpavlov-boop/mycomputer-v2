import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(__dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('Cart UX states', () => {
  it('distinguishes unresolved, loading, failure, empty, ready, and mutating state', () => {
    const store = source('app/stores/cart.ts')
    const page = source('app/pages/cart.vue')

    expect(store).toContain('const isUnresolved = computed')
    expect(store).toContain('const isInitialLoading = computed')
    expect(store).toContain('const isConfirmedEmpty = computed')
    expect(store).toContain('const hasConfirmedContent = computed')
    expect(store).toContain('const isMutating = computed')
    expect(page).toContain('cart.isUnresolved || cart.isInitialLoading')
    expect(page).toContain('v-else-if="!cart.cart"')
    expect(page).toContain('v-if="cart.isConfirmedEmpty"')
    expect(page).toContain('v-if="cart.hasConfirmedContent"')
  })

  it('shows empty state only after a confirmed backend Cart', () => {
    const store = source('app/stores/cart.ts')
    const page = source('app/pages/cart.vue')

    expect(store).toContain('cart.value !== null && !hasConfirmedContent.value')
    expect(page.indexOf('cart.isUnresolved || cart.isInitialLoading'))
      .toBeLessThan(page.indexOf('cart.isConfirmedEmpty'))
    expect(page).toContain('Към каталога')
  })

  it('offers an explicit idempotent GET retry after initial failure', () => {
    const store = source('app/stores/cart.ts')
    const page = source('app/pages/cart.vue')

    expect(page).toContain('Не успяхме да заредим количката')
    expect(page).toContain('Опитай отново')
    expect(page).toContain('await cart.sync().catch(() => null)')
    expect(store).toContain('const response = await useCartApi().get()')
  })

  it('clears stale rendered content and responses across auth transitions', () => {
    const store = source('app/stores/cart.ts')

    expect(store).toContain('cart.value = null')
    expect(store).toContain("lastOperation.value = 'auth-transition'")
    expect(store).toContain('authorityVersion.value += 1')
    expect(store).toContain('authorityVersion.value !== expectedAuthorityVersion')
    expect(store).toContain('token < latestAcceptedSequence.value')
  })
})
