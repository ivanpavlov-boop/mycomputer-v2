import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('Checkout success navigation', () => {
  it('navigates to the clean success path without serializing order data', () => {
    const checkout = source('app/pages/checkout/index.vue')
    const submit = checkout.slice(checkout.indexOf('async function submit()'))

    expect(submit).toContain("await router.push('/checkout/success')")
    expect(submit).not.toContain('query:')
    expect(submit).not.toContain('URLSearchParams')
    expect(submit).not.toContain('redirect_url')
    expect(submit).not.toContain('customer_email')
    expect(submit).not.toContain('payment_transactions')
  })

  it('does not derive success authority from the route', () => {
    const page = source('app/pages/checkout/success.vue')

    expect(page).not.toContain('route.query')
    expect(page).not.toContain('route.params')
    expect(page).not.toContain('URLSearchParams')
    expect(page).toContain('route.fullPath !== route.path')
    expect(page).toContain('await router.replace(route.path)')
  })
})
