import { describe, expect, it } from 'vitest'
import {
  createLeasingApplicationForm,
  validateLeasingApplication,
  withLeasingApplication,
} from '../app/utils/leasingCheckout'
import type { LeasingPaymentOptions } from '../app/types/api'

const options: LeasingPaymentOptions = {
  term_months: [6, 12, 24],
  contact_methods: [
    { value: 'phone', label: 'Телефон' },
    { value: 'email', label: 'E-mail' },
  ],
  contact_time_slots: [
    { value: 'anytime', label: 'Без предпочитание' },
    { value: 'afternoon', label: 'Следобед' },
  ],
  currency: 'EUR',
}

describe('manual leasing checkout payload', () => {
  it('submits the nested application only for leasing', () => {
    const form = {
      ...createLeasingApplicationForm(options),
      term_months: 24,
      down_payment: '100.00',
      contact_method: 'phone',
      contact_time: 'afternoon',
      note: 'След 14:00 ч.',
      consent: true,
    }
    const leasing = withLeasingApplication({ payment_method: 'leasing' }, 'leasing', form)
    const cod = withLeasingApplication({ payment_method: 'cash_on_delivery' }, 'cash_on_delivery', form)

    expect(leasing.leasing_application).toEqual({
      term_months: 24,
      down_payment: '100.00',
      contact_method: 'phone',
      contact_time: 'afternoon',
      note: 'След 14:00 ч.',
      consent: true,
    })
    expect(cod).not.toHaveProperty('leasing_application')
  })

  it('rejects unsupported terms, invalid down payments and missing consent', () => {
    const errors = validateLeasingApplication({
      ...createLeasingApplicationForm(options),
      term_months: 36,
      down_payment: '1000.001',
      consent: false,
    }, options, 999)

    expect(errors.term_months).toContain('поддържан')
    expect(errors.down_payment).toContain('два знака')
    expect(errors.consent).toContain('съгласие')
  })

  it('rejects a down payment above the trusted displayed total', () => {
    const errors = validateLeasingApplication({
      ...createLeasingApplicationForm(options),
      down_payment: '1000.00',
      consent: true,
    }, options, 999.99)

    expect(errors.down_payment).toContain('общата сума')
  })
})
