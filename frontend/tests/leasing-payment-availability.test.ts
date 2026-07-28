import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('leasing payment availability', () => {
  it('keeps leasing absent in the default browser fixture', () => {
    const fixture = source('test/browser/fixtures/cart-fixture.mjs')
    const server = source('test/browser/fixtures/cart-api-server.mjs')

    expect(fixture).toContain('leasing_enabled: false')
    expect(server).toContain('if (state.scenario.leasing_enabled)')
  })

  it('shows leasing only from payment API data', () => {
    const page = source('app/pages/checkout/index.vue')
    const selector = source('app/components/checkout/PaymentMethodSelect.vue')

    expect(page).toContain('payments.methods()')
    expect(selector).toContain('v-for="method in methods"')
    expect(page).not.toContain('paymentMethods.value.push')
  })
})
