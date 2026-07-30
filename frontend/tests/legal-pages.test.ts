import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('Bulgarian legal pages', () => {
  it('renders approved, indexable and safely escaped legal documents', () => {
    const component = source('app/components/legal/LegalDocumentPage.vue')
    const terms = source('app/pages/obshti-usloviya.vue')
    const privacy = source('app/pages/politika-za-poveritelnost.vue')

    expect(component).toContain('<article')
    expect(component).toContain('<h1')
    expect(component).toContain('<h2')
    expect(component).toContain("htmlAttrs: { lang: 'bg' }")
    expect(component).toContain("content: 'index, follow'")
    expect(component).toContain('@media print')
    expect(component).not.toContain('Проект за правен преглед')
    expect(component).not.toContain('noindex')
    expect(component).not.toContain('nofollow')
    expect(component).not.toContain('noarchive')
    expect(component).not.toContain('v-html')
    expect(terms).toContain('legalManifest.terms.route')
    expect(terms).toContain("locale.value !== 'bg'")
    expect(privacy).toContain('legalManifest.privacy.route')
    expect(privacy).toContain("locale.value !== 'bg'")
  })

  it('contains all required Terms sections and a static withdrawal form', () => {
    const terms = source('app/data/legal/terms.bg.ts')

    for (const heading of [
      'Търговец и контакти',
      'Обхват и клиенти',
      'Информация за продуктите',
      'Цени и промоции',
      'Поръчка и сключване на договор',
      'Начини на плащане',
      'Доставка',
      'Право на отказ',
      'Изключения от правото на отказ',
      'Съответствие, гаранции и рекламации',
      'Връщане и възстановяване на суми',
      'Лични данни',
      'Интелектуална собственост',
      'Отговорност',
      'Жалби и извънсъдебно решаване на спорове',
      'Приложимо право и компетентност',
      'Версия и влизане в сила',
    ]) {
      expect(terms).toContain(heading)
    }

    expect(terms).toContain('Стандартен формуляр за отказ')
    expect(terms).toContain('Номер на поръчка')
    expect(terms).toContain('Подпис на потребителя (само ако формулярът е на хартия)')
    expect(terms).toContain('Поръчка със задължение за плащане')
    expect(terms).not.toMatch(/ec\.europa\.eu\/consumers\/odr/i)
  })

  it('contains all required Privacy transparency sections', () => {
    const privacy = source('app/data/legal/privacy.bg.ts')

    for (const heading of [
      'Администратор на лични данни',
      'Категории лични данни',
      'Цели и правни основания',
      'Получатели',
      'Международни трансфери',
      'Срокове за съхранение',
      'Вашите права',
      'Задължителни данни',
      'Автоматизирано вземане на решения',
      'Бисквитки и анализи',
      'Сигурност',
      'Деца',
      'Промени в политиката',
      'Версия и влизане в сила',
    ]) {
      expect(privacy).toContain(heading)
    }

    expect(privacy).toContain('не съхранява номера на платежни карти')
    expect(privacy).toContain('Комисията за защита на личните данни')
  })

  it('uses only confirmed operator facts and keeps unverified claims out', () => {
    const content = [
      source('app/data/legal/terms.bg.ts'),
      source('app/data/legal/privacy.bg.ts'),
    ].join('\n')

    expect(content).toContain('„Тандем компютърс“ ЕООД')
    expect(content).toContain('202410637')
    expect(content).toContain('sales@mycomputer.bg')
    expect(content).toContain('гр. Перник, ул. „Г. С. Раковски“ №3/6А')
    expect(content).not.toMatch(/IBAN|ДДС номер|DPO|длъжностно лице/i)
  })

  it('keeps exact Bulgarian footer links available independently of commerce', () => {
    const footer = source('app/components/layout/AppFooter.vue')
    const navigation = source('app/utils/storefrontRouteAvailability.ts')

    expect(footer).toContain('storefrontLegalNavigation')
    expect(navigation).toContain("path: '/obshti-usloviya'")
    expect(navigation).toContain("path: '/politika-za-poveritelnost'")
    expect(navigation).toContain("bg: 'Общи условия'")
    expect(navigation).toContain("bg: 'Политика за поверителност'")
  })
})
