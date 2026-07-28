import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const source = (path: string) => readFileSync(resolve(frontendRoot, path), 'utf8')

describe('manual leasing checkout form', () => {
  const form = source('app/components/checkout/LeasingApplicationForm.vue')
  const page = source('app/pages/checkout/index.vue')

  it('renders only when the API returns leasing and it is selected', () => {
    expect(page).toContain("selectedPaymentMethod?.code === 'leasing' && leasingOptions")
    expect(page).toContain('<CheckoutLeasingApplicationForm')
  })

  it('uses API-derived terms and contact options', () => {
    expect(form).toContain('v-for="term in options.term_months"')
    expect(form).toContain('v-for="option in options.contact_methods"')
    expect(form).toContain('v-for="option in options.contact_time_slots"')
  })

  it('shows the approved Bulgarian explanation and consent', () => {
    expect(form).toContain('Покупка на изплащане')
    expect(form).toContain('Изпращането на заявката не гарантира одобрение')
    expect(form).toContain('Съгласен/на съм данните от поръчката')
  })
})
