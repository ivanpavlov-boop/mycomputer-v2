import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const panel = readFileSync(
  resolve(frontendRoot, 'app/components/payments/PaymentActionPanel.vue'),
  'utf8',
)

describe('Payment action presentation', () => {
  it('renders only server-authoritative explicit actions', () => {
    expect(panel).toContain("current.value.action.type === 'continue_payment'")
    expect(panel).toContain("current.value.action.type === 'retry_payment'")
    expect(panel).toContain('current.value.action.available')
    expect(panel).not.toContain('payment_method.code')
    expect(panel).not.toContain('payment_status ===')
  })

  it('opens only HTTPS provider continuations with safe link attributes', () => {
    expect(panel).toContain("current.value.redirect_url.startsWith('https://')")
    expect(panel).toContain('target="_blank"')
    expect(panel).toContain('rel="noopener noreferrer"')
    expect(panel).toContain('referrerpolicy="no-referrer"')
  })

  it('announces retry results and guards duplicate clicks', () => {
    expect(panel).toContain('aria-live="polite"')
    expect(panel).toContain('role="alert"')
    expect(panel).toContain(':disabled="pending"')
    expect(panel).toContain('if (pending.value || !canRetry.value)')
    expect(panel).toContain('statusRegion.value?.focus()')
  })
})
