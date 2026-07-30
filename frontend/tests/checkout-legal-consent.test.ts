import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const page = readFileSync(
  resolve(import.meta.dirname, '../app/pages/checkout/index.vue'),
  'utf8',
)

describe('checkout legal consent', () => {
  it('keeps the required checkbox and links both Bulgarian legal documents', () => {
    expect(page).toContain('id="checkout-legal-consent"')
    expect(page).toContain('for="checkout-legal-consent"')
    expect(page).toContain('v-model="form.terms"')
    expect(page).toContain('type="checkbox" required')
    expect(page).toContain('to="/obshti-usloviya"')
    expect(page).toContain('to="/politika-za-poveritelnost"')
    expect(page).toContain('target="_blank"')
    expect(page).toContain('rel="noopener noreferrer"')
    expect(page).toContain('@click.stop')
  })

  it('does not send authoritative legal metadata from the browser', () => {
    expect(page).not.toContain('terms_version')
    expect(page).not.toContain('privacy_version')
    expect(page).not.toContain('legal_accepted_at')
    expect(page).not.toContain('legal_acceptance_locale')
  })

  it('uses an unambiguous final order label without changing submission behavior', () => {
    expect(page).toContain('Поръчка със задължение за плащане')
    expect(page).toContain('@submit.prevent="submit"')
    expect(page).not.toContain("'Изпрати поръчка'")
  })
})
