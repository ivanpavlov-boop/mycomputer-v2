import { describe, expect, it } from 'vitest'
import {
  orderStatusLabel,
  paymentMethodLabel,
} from '../app/utils/checkoutConfirmationPresentation'

describe('Checkout confirmation presentation', () => {
  it.each([
    ['pending', 'Очаква обработка'],
    ['confirmed', 'Потвърдена'],
    ['processing', 'Обработва се'],
    ['shipped', 'Изпратена'],
    ['completed', 'Завършена'],
    ['cancelled', 'Отказана'],
    ['refunded', 'Възстановена'],
  ])('maps the %s order status to Bulgarian', (status, label) => {
    expect(orderStatusLabel(status)).toBe(label)
  })

  it('fails closed without exposing an unknown order status', () => {
    expect(orderStatusLabel('internal_future_state')).toBe('Статусът се актуализира')
    expect(orderStatusLabel('internal_future_state')).not.toContain('internal_future_state')
    expect(orderStatusLabel(null)).not.toContain('pending')
  })

  it.each([
    ['cash_on_delivery', 'Наложен платеж'],
    ['bank_transfer', 'Банков превод'],
    ['card', 'Плащане с карта'],
    ['leasing', 'Покупка на изплащане'],
  ])('maps the %s payment method without exposing its code', (code, label) => {
    expect(paymentMethodLabel(code, code)).toBe(label)
    expect(paymentMethodLabel(code, code)).not.toBe(code)
  })

  it('uses a valid supplied customer-facing label for an unknown method', () => {
    expect(paymentMethodLabel('store_credit', 'Плащане с ваучер')).toBe('Плащане с ваучер')
  })

  it.each([
    ['unknown_method', undefined],
    ['unknown_method', 'unknown_method'],
    ['unknown_method', 'raw_machine_name'],
    [undefined, undefined],
  ])('fails closed for unusable method data', (code, name) => {
    const label = paymentMethodLabel(code, name)

    expect(label).toBe('Избран начин на плащане')
    expect(label).not.toContain(String(code))
  })
})
