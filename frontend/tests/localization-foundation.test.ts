import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createI18n } from 'vue-i18n'
import { describe, expect, it } from 'vitest'
import bg from '../i18n/locales/bg'
import en from '../i18n/locales/en'
import {
  localizedStorefrontPath,
  normalizeStorefrontLocale,
  storefrontLocales,
  stripStorefrontLocalePrefix,
} from '../app/utils/locales'
import { isReadOnlyStorefrontPath } from '../app/composables/useReadOnlyStorefrontRoute'

const frontendRoot = resolve(__dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('multilingual storefront foundation', () => {
  it('keeps Bulgarian as the default locale and English as the prefixed secondary locale', () => {
    expect(storefrontLocales).toEqual([
      expect.objectContaining({ code: 'bg', name: 'Български', language: 'bg-BG' }),
      expect.objectContaining({ code: 'en', name: 'English', language: 'en-GB' }),
    ])
    expect(localizedStorefrontPath('/', 'bg')).toBe('/')
    expect(localizedStorefrontPath('/catalog', 'bg')).toBe('/catalog')
    expect(localizedStorefrontPath('/', 'en')).toBe('/en')
    expect(localizedStorefrontPath('/catalog', 'en')).toBe('/en/catalog')
    expect(localizedStorefrontPath('/p/example-product', 'en')).toBe('/en/p/example-product')
    expect(stripStorefrontLocalePrefix('/en/categories')).toBe('/categories')
  })

  it('falls back safely to Bulgarian for unsupported values and missing English copy', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      fallbackLocale: 'bg',
      fallbackWarn: false,
      missingWarn: false,
      messages: { bg, en },
    })

    expect(normalizeStorefrontLocale('en')).toBe('en')
    expect(normalizeStorefrontLocale('de')).toBe('bg')
    expect(normalizeStorefrontLocale('../../en')).toBe('bg')
    expect(i18n.global.t('common.fallbackContent')).toBe('Съдържанието е достъпно на български.')
  })

  it('configures SSR-safe i18n routing and locale-aware API requests', () => {
    const config = source('nuxt.config.ts')
    const api = source('app/composables/useApi.ts')
    const app = source('app/app.vue')
    const switcher = source('app/components/layout/LanguageSwitcher.vue')

    expect(config).toContain("defaultLocale: 'bg'")
    expect(config).toContain("strategy: 'prefix_except_default'")
    expect(config).toContain('detectBrowserLanguage: false')
    expect(config).toContain('@nuxtjs/i18n')
    expect(api).toContain("'X-Locale': normalizeStorefrontLocale(locale.value)")
    expect(app).toContain('htmlAttrs')
    expect(app).toContain('lang: locale.value')
    expect(switcher).toContain('useSwitchLocalePath()')
    expect(switcher).toContain('storefrontLocales')
    expect(switcher).toContain('supportedLocale.shortLabel')
  })

  it('keeps all localized catalog paths in the existing read-only route boundary', () => {
    expect(isReadOnlyStorefrontPath('/en/catalog')).toBe(true)
    expect(isReadOnlyStorefrontPath('/en/categories')).toBe(true)
    expect(isReadOnlyStorefrontPath('/en/c/laptops')).toBe(true)
    expect(isReadOnlyStorefrontPath('/en/p/example-product')).toBe(true)
    expect(isReadOnlyStorefrontPath('/en/account')).toBe(false)
  })

  it('uses locale-aware catalog links and prevents unreviewed English indexing by default', () => {
    const productCard = source('app/components/catalog/ProductCard.vue')
    const categoryCard = source('app/components/catalog/CategoryCard.vue')
    const seo = source('app/composables/useSeo.ts')

    expect(productCard).toContain('localePath(`/p/${product.slug}`)')
    expect(categoryCard).toContain('localePath(`/c/${category.slug}`)')
    expect(productCard).toContain('props.product.localized?.name || props.product.name')
    expect(seo).toContain("{ rel: 'alternate', hreflang: 'bg'")
    expect(seo).toContain("{ rel: 'alternate', hreflang: 'x-default'")
    expect(seo).toContain("content: 'noindex, follow'")
    expect(seo).toContain('englishLocaleIndexable')
  })
})
