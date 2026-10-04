import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSeo } from '../app/composables/useSeo'
import type { ProductDetail } from '../app/types/api'

// Resolve Nuxt's alias to the real locale helpers without changing Vitest config.
vi.mock('~/utils/locales', () => import('../app/utils/locales'))

const seoMeta = vi.fn()
const head = vi.fn()
const missingValues = [
  { label: 'undefined', value: undefined },
  { label: 'null', value: null },
  { label: 'empty', value: '' },
  { label: 'spaces', value: '   ' },
  { label: 'tabs', value: '\t\t' },
  { label: 'newlines', value: '\r\n\n' },
  { label: 'mixed whitespace', value: ' \t\r\n ' },
]

function product(overrides: Partial<ProductDetail> = {}): ProductDetail {
  return {
    id: 1,
    sku: 'TEST-1',
    slug: 'test-product',
    name: 'Base product name',
    short_description: 'Base product description',
    localized: { name: 'Localized product name', short_description: 'Localized product description' },
    price: 100,
    stock_status: 'in_stock',
    images: [],
    attributes: [],
    specification_groups: [],
    related_products: [],
    accessory_products: [],
    seo: {},
    structured_data: {},
    ...overrides,
  }
}

function expectProductMeta(title: string, description: string) {
  expect(seoMeta).toHaveBeenCalledExactlyOnceWith({
    title,
    description,
    ogTitle: title,
    ogDescription: description,
    ogType: 'product',
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.stubGlobal('useRuntimeConfig', () => ({ public: { siteUrl: 'https://catalog.example', englishLocaleIndexable: false } }))
  vi.stubGlobal('useI18n', () => ({ locale: { value: 'bg' } }))
  vi.stubGlobal('useSeoMeta', seoMeta)
  vi.stubGlobal('useHead', head)
})

afterEach(() => vi.unstubAllGlobals())

describe('product SEO fallback', () => {
  it('prefers nonblank localized SEO and preserves its original whitespace', () => {
    const input = product({
      localized: { meta_title: '  Local title\t', meta_description: '\n Local description  ' },
      seo: { meta_title: 'Base title', meta_description: 'Base description' },
    })
    const snapshot = structuredClone(input)

    useSeo().product(input)

    expectProductMeta('  Local title\t', '\n Local description  ')
    expect(input).toEqual(snapshot)
  })

  it.each(missingValues)('skips $label localized SEO for base SEO', ({ value }) => {
    useSeo().product(product({
      localized: { meta_title: value, meta_description: value, name: 'Localized name', short_description: 'Localized description' },
      seo: { meta_title: 'Base SEO title', meta_description: 'Base SEO description' },
    }))

    expectProductMeta('Base SEO title', 'Base SEO description')
  })

  it.each(missingValues)('skips $label in both SEO sources for localized product content', ({ value }) => {
    useSeo().product(product({
      localized: { meta_title: value, meta_description: value, name: ' Local product ', short_description: '\t Local summary\n' },
      seo: { meta_title: value, meta_description: value },
    }))

    expectProductMeta(' Local product ', '\t Local summary\n')
  })

  it.each(missingValues)('skips $label localized product fallbacks for base product content', ({ value }) => {
    useSeo().product(product({
      localized: { meta_title: value, meta_description: value, name: value, short_description: value },
      seo: { meta_title: value, meta_description: value },
    }))

    expectProductMeta('Base product name', 'Base product description')
  })

  it('preserves nonblank base SEO verbatim', () => {
    useSeo().product(product({
      localized: { meta_title: ' \n', meta_description: '\t' },
      seo: { meta_title: '\t Base title  ', meta_description: ' Base description\n' },
    }))

    expectProductMeta('\t Base title  ', ' Base description\n')
  })

  it('selects a localized title independently of a base description', () => {
    useSeo().product(product({
      localized: { meta_title: 'Local title', meta_description: ' \t' },
      seo: { meta_title: 'Base title', meta_description: 'Base description' },
    }))

    expectProductMeta('Local title', 'Base description')
  })

  it('selects a product title independently of a localized description', () => {
    useSeo().product(product({
      localized: { meta_title: '\n', name: '\t', meta_description: 'Local description' },
      seo: { meta_title: '  ', meta_description: 'Base description' },
    }))

    expectProductMeta('Base product name', 'Local description')
  })

  it.each(missingValues)('keeps empty metadata when no content is available ($label)', ({ value }) => {
    useSeo().product(product({
      name: '',
      short_description: value,
      localized: { meta_title: value, meta_description: value, name: value, short_description: value },
      seo: { meta_title: value, meta_description: value },
    }))

    expectProductMeta('', '')
  })

  it('handles omitted optional fields and leaves canonical and structured data behavior intact', () => {
    useSeo().product(product({ localized: undefined, short_description: undefined, structured_data: { '@type': 'Product', name: 'Schema name' } }))

    expectProductMeta('Base product name', '')
    expect(head).toHaveBeenCalledTimes(1)
    expect(head.mock.calls[0]![0]()).toEqual({
      link: [
        { rel: 'canonical', href: 'https://catalog.example/p/test-product' },
        { rel: 'alternate', hreflang: 'bg', href: 'https://catalog.example/p/test-product' },
        { rel: 'alternate', hreflang: 'x-default', href: 'https://catalog.example/p/test-product' },
      ],
      meta: [],
      script: [{ type: 'application/ld+json', children: JSON.stringify({ '@context': 'https://schema.org', '@type': 'Product', name: 'Schema name' }) }],
    })
  })

  it('does not change the non-product page SEO behavior', () => {
    useSeo().page(' \t', '\n')

    expect(seoMeta).toHaveBeenCalledExactlyOnceWith({ title: ' \t', description: '\n', ogTitle: ' \t', ogDescription: '\n' })
  })
})
