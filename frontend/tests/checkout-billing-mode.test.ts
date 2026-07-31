import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  checkoutBillingPayload,
  clearCompanyBilling,
  type CheckoutBillingForm,
} from '../app/utils/checkoutBilling'

const page = readFileSync(
  resolve(import.meta.dirname, '../app/pages/checkout/index.vue'),
  'utf8',
)

function billingForm(overrides: Partial<CheckoutBillingForm> = {}): CheckoutBillingForm {
  return {
    company_name: 'Стара фирма',
    vat_number: 'BG123456789',
    billing_address: 'Стар адрес за фактуриране',
    shipping_address: 'Нормализиран адрес за доставка',
    ...overrides,
  }
}

describe('individual and company Checkout billing', () => {
  it('defaults to individual mode and hides company-only fields', () => {
    expect(page).toContain('const isCompany = ref(false)')
    expect(page).toContain('Желая фактура на фирма')
    expect(page).toContain('<div v-if="isCompany" class="mt-4 grid gap-4">')
    expect(page).not.toMatch(/v-model="form\.billing_address"[\s\S]*v-if="isCompany"/u)
  })

  it('normalizes an individual payload from shipping and drops stale company values', () => {
    expect(checkoutBillingPayload(billingForm(), false)).toEqual({
      is_company: false,
      company_name: null,
      vat_number: null,
      billing_address: 'Нормализиран адрес за доставка',
    })
  })

  it('preserves explicit company billing data and keeps VAT nullable', () => {
    expect(checkoutBillingPayload(billingForm(), true)).toEqual({
      is_company: true,
      company_name: 'Стара фирма',
      vat_number: 'BG123456789',
      billing_address: 'Стар адрес за фактуриране',
    })
    expect(checkoutBillingPayload(billingForm({ vat_number: '' }), true).vat_number).toBeNull()
  })

  it('clears all company-only state when returning to individual mode', () => {
    const form = billingForm()

    clearCompanyBilling(form)

    expect(form).toEqual({
      company_name: '',
      vat_number: '',
      billing_address: '',
      shipping_address: 'Нормализиран адрес за доставка',
    })
  })

  it('keeps required company fields, legal links and idempotency reset wired safely', () => {
    expect(page).toContain('v-model="form.company_name" placeholder="Име на фирма" required')
    expect(page).toContain('v-model="form.vat_number" placeholder="ЕИК / ДДС номер"')
    expect(page).toContain('v-model="form.billing_address"')
    expect(page).toContain('placeholder="Адрес за фактуриране" required')
    expect(page).toContain('clearCompanyBilling(form)')
    expect(page).toContain('checkoutIdempotency.clear()')
    expect(page).toContain('...checkoutBillingPayload(form, isCompany.value)')
    expect(page).toContain('@submit.prevent="submit"')
    expect(page).toContain('@click.stop')
  })
})
