import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('Bulgarian legal pages', () => {
  it('renders structured Terms and Privacy pages through one escaped component', () => {
    const component = source('app/components/legal/LegalDocumentPage.vue')
    const terms = source('app/pages/obshti-usloviya.vue')
    const privacy = source('app/pages/politika-za-poveritelnost.vue')

    expect(component).toContain('<article')
    expect(component).toContain('<h1')
    expect(component).toContain('<h2')
    expect(component).toContain('Проект за правен преглед')
    expect(component).toContain('noindex, nofollow, noarchive')
    expect(component).toContain('<LayoutBreadcrumbs')
    expect(component).not.toContain('v-html')
    expect(terms).toContain("legalManifest.terms.route")
    expect(terms).toContain("locale.value !== 'bg'")
    expect(privacy).toContain("legalManifest.privacy.route")
    expect(privacy).toContain("locale.value !== 'bg'")
  })

  it('uses only the confirmed operator facts and keeps unverified claims out', () => {
    const content = [
      source('app/data/legal/terms.bg.ts'),
      source('app/data/legal/privacy.bg.ts'),
    ].join('\n')

    expect(content).toContain('„Тандем компютърс“ ЕООД')
    expect(content).toContain('202410637')
    expect(content).toContain('sales@mycomputer.bg')
    expect(content).toContain('гр. Перник, ул. „Г. С. Раковски“ №3/6А')
    expect(content).not.toMatch(/IBAN|ДДС номер|телефон|DPO|длъжностно лице/i)
  })

  it('keeps exact Bulgarian footer links available independently of commerce', () => {
    const footer = source('app/components/layout/AppFooter.vue')

    expect(footer).toContain('to="/obshti-usloviya"')
    expect(footer).toContain('to="/politika-za-poveritelnost"')
    expect(footer).toContain('Общи условия')
    expect(footer).toContain('Политика за поверителност')
  })
})
