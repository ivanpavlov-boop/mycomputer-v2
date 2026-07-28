import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const actionPanel = readFileSync(
  resolve(frontendRoot, 'app/components/payments/PaymentActionPanel.vue'),
  'utf8',
)

describe('Account payment acceptance UI foundation', () => {
  it('uses direct account retry only when rendered in account mode', () => {
    expect(actionPanel).toContain("props.mode === 'account'")
    expect(actionPanel).toContain('props.orderId !== null')
    expect(actionPanel).toContain('attempts.retryAccountOrder(props.orderId)')
    expect(actionPanel).toContain('attempts.retryGuestOrder()')
  })

  it('does not invent an account order-detail route in this phase', () => {
    expect(existsSync(resolve(frontendRoot, 'app/pages/account/orders/[id].vue'))).toBe(false)
  })
})
