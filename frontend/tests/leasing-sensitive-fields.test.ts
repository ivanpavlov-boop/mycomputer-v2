import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const sources = [
  'app/components/checkout/LeasingApplicationForm.vue',
  'app/utils/leasingCheckout.ts',
].map(path => readFileSync(resolve(frontendRoot, path), 'utf8')).join('\n')

describe('manual leasing data minimisation', () => {
  it('does not collect sensitive finance identity fields or provide a calculator', () => {
    expect(sources).not.toMatch(/name=["']?(egn|lnch|personal_id|identity_card|passport|employer|income|salary|iban|bank_account|card_number|pan|cvv|cvc|expiry)/i)
    expect(sources).not.toMatch(/monthly[_-]?payment|apr|gpr|interest[_-]?rate|provider[_-]?selection/i)
  })

  it('does not persist leasing preferences or send them to analytics', () => {
    const utility = readFileSync(resolve(frontendRoot, 'app/utils/leasingCheckout.ts'), 'utf8')
    const page = readFileSync(resolve(frontendRoot, 'app/pages/checkout/index.vue'), 'utf8')

    expect(utility).not.toMatch(/localStorage|sessionStorage|useStorage/)
    expect(page).not.toMatch(/analytics\.(?:track|event).*(?:leasing|term_months|down_payment)/i)
  })
})
