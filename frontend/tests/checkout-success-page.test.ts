import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const page = readFileSync(resolve(frontendRoot, 'app/pages/checkout/success.vue'), 'utf8')

describe('Checkout success page', () => {
  it('renders loading, trusted confirmation, and unavailable states', () => {
    expect(page).toContain("status === 'idle' || status === 'pending'")
    expect(page).toContain('v-else-if="confirmation"')
    expect(page).toContain('Поръчката е приета')
    expect(page).toContain('Потвърждението за поръчката не е налично.')
    expect(page).toContain("confirmationApi.get()")
  })

  it('renders only the minimal confirmation presentation', () => {
    for (const field of [
      'confirmation.order_number',
      'confirmation.grand_total',
      'confirmation.currency',
      'confirmation.order_status',
      'confirmation.payment_status',
      'confirmation.payment_method.name',
      'confirmation.payment.presentation',
      'confirmation.customer_email_masked',
    ]) {
      expect(page).toContain(field)
    }

    expect(page).toContain('PaymentsPaymentActionPanel')

    for (const forbidden of [
      'customer_phone',
      'billing_address',
      'shipping_address',
      'vat_number',
      'payment_transactions',
      'raw_response',
      'cart_session_id',
      'supplier_products',
      'cardPaymentText',
      'Адрес за плащане',
      'подготвителен режим',
    ]) {
      expect(page).not.toContain(forbidden)
    }
  })
})
