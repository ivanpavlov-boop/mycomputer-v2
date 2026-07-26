import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const page = readFileSync(resolve(frontendRoot, 'app/pages/checkout/success.vue'), 'utf8')

describe('Checkout success analytics', () => {
  it('emits purchase only from server-confirmed data', () => {
    expect(page).toContain('watch(confirmation')
    expect(page).toContain('purchaseEmitted.value')
    expect(page).toContain('await analytics.purchase({')
    expect(page).toContain('order_number: value.order_number')
    expect(page).toContain('value: Number(value.grand_total)')
    expect(page).toContain('currency: value.currency')
    expect(page).not.toContain('route.query')
  })

  it('guards duplicate and server-side emission', () => {
    expect(page).toContain('!import.meta.client || !value || purchaseEmitted.value')
    expect(page).toContain('purchaseEmitted.value = true')
    expect(page).toContain('{ immediate: true }')
  })
})
