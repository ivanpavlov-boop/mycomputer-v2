// @vitest-environment happy-dom
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { compileScript, parse } from '@vue/compiler-sfc'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { ModuleKind, ScriptTarget, transpileModule } from 'typescript'
import { afterEach, describe, expect, it } from 'vitest'
import * as Vue from 'vue'
import type { ProductCard, ProductImage } from '../app/types/api'
import * as productGalleryImages from '../app/utils/productGalleryImages'

// Compile the real SFCs with the same Nuxt auto-import setup as the gallery tests.
function component(relativePath: string) {
  const filename = resolve(__dirname, relativePath)
  const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename })
  const script = compileScript(descriptor, { id: relativePath, inlineTemplate: true })
  const { outputText } = transpileModule(script.content, {
    compilerOptions: { module: ModuleKind.CommonJS, target: ScriptTarget.ESNext },
  })
  const exports = { default: {} as Vue.Component }
  new Function('exports', 'require', 'ref', 'computed', 'watch', 'useRuntimeConfig', 'useLocalePath', 'useImage', outputText)(
    exports,
    (id: string) => {
      if (id === '~/utils/productGalleryImages') return productGalleryImages
      if (id !== 'vue') throw new Error(`Unexpected component import: ${id}`)
      return Vue
    },
    Vue.ref,
    Vue.computed,
    Vue.watch,
    () => ({ public: { apiBaseUrl: 'https://catalog.example/api/v1' } }),
    () => (path: string) => path,
    () => (source: string) => source,
  )
  return exports.default
}

const Card = component('../app/components/catalog/ProductCard.vue')
const Gallery = component('../app/components/product/ProductGallery.vue')
const global = {
  stubs: {
    NuxtImg: Vue.defineComponent({
      inheritAttrs: false,
      setup: (_, { attrs }) => () => Vue.h('img', attrs),
    }),
    NuxtLink: Vue.defineComponent({
      setup: (_, { slots }) => () => Vue.h('a', slots.default?.()),
    }),
    ProductAvailabilityBadge: true,
    ProductStockBadge: true,
    ProductPrice: true,
  },
}

enableAutoUnmount(afterEach)

function product(image: ProductImage): ProductCard {
  return {
    id: 1, sku: 'TEST', slug: 'test-product', name: 'Base product',
    localized: { name: 'Localized product' }, price: '100.00', stock_status: 'in_stock',
    primary_image: image,
  }
}

const absentAlts: Array<[string, Partial<ProductImage>]> = [
  ['missing field', {}],
  ['undefined', { alt_text: undefined }],
  ['null', { alt_text: null }],
  ['empty', { alt_text: '' }],
  ['spaces', { alt_text: '   ' }],
  ['tabs', { alt_text: '\t\t' }],
  ['newlines', { alt_text: '\r\n\n' }],
  ['mixed whitespace', { alt_text: ' \t\n ' }],
]

describe('product image alt fallback', () => {
  it.each(absentAlts)('ProductCard uses the existing localized name for %s alt', (_, alt) => {
    const wrapper = mount(Card, { props: { product: product({ path: 'primary.jpg', ...alt }) }, global })

    expect(wrapper.get('img').attributes('alt')).toBe('Localized product')
  })

  it.each(absentAlts)('ProductGallery uses its product name for main and thumbnail %s alt', (_, alt) => {
    const images = [{ path: 'first.jpg', ...alt }, { path: 'primary.jpg', is_primary: true, ...alt }]
    const wrapper = mount(Gallery, { props: { images, productName: 'Localized product' }, global })

    expect(wrapper.get('.surface img').attributes('alt')).toBe('Localized product')
    expect(wrapper.findAll('button img').map(image => image.attributes('alt')))
      .toEqual(['Localized product', 'Localized product'])
  })

  it('preserves nonempty manual text verbatim in the card, active image and thumbnails', () => {
    const alt_text = ' \t Manual product view \n '
    const image = { path: 'manual.jpg', alt_text }
    const card = mount(Card, { props: { product: product(image) }, global })
    const gallery = mount(Gallery, {
      props: { images: [image, { path: 'other.jpg', alt_text }], productName: 'Product name' }, global,
    })

    expect(card.get('img').attributes('alt')).toBe(alt_text)
    expect(gallery.findAll('img').map(image => image.attributes('alt'))).toEqual([alt_text, alt_text, alt_text])
  })

  it('keeps the existing card name selection and updates alt when product props change', async () => {
    const original = product({ path: 'primary.jpg', alt_text: '\t' })
    const wrapper = mount(Card, { props: { product: { ...original, localized: { name: null } } }, global })
    expect(wrapper.get('img').attributes('alt')).toBe('Base product')

    await wrapper.setProps({ product: { ...original, localized: { name: '  Localized name  ' } } })
    expect(wrapper.get('img').attributes('alt')).toBe('  Localized name  ')
    await wrapper.setProps({ product: { ...original, primary_image: { path: 'new.jpg', alt_text: '  Manual  ' } } })
    expect(wrapper.get('img').attributes('alt')).toBe('  Manual  ')
  })

  it('updates alt on thumbnail selection, product renaming and image set replacement without mutation', async () => {
    const images = [
      { path: 'manual.jpg', alt_text: '  Manual view  ' },
      { path: 'primary.jpg', is_primary: true, alt_text: '\t\n' },
      { path: 'fallback.jpg', alt_text: null },
    ]
    const snapshot = structuredClone(images)
    images.forEach(Object.freeze)
    Object.freeze(images)
    const wrapper = mount(Gallery, { props: { images, productName: 'Initial name' }, global })
    const thumbnails = () => wrapper.findAll('button img').map(image => image.attributes('alt'))

    expect(wrapper.get('.surface img').attributes('alt')).toBe('Initial name')
    expect(thumbnails()).toEqual(['  Manual view  ', 'Initial name', 'Initial name'])
    await wrapper.findAll('button')[0]!.trigger('click')
    expect(wrapper.get('.surface img').attributes('alt')).toBe('  Manual view  ')
    await wrapper.setProps({ productName: '  Renamed product  ' })
    expect(wrapper.get('.surface img').attributes('alt')).toBe('  Manual view  ')
    expect(thumbnails()).toEqual(['  Manual view  ', '  Renamed product  ', '  Renamed product  '])
    await wrapper.findAll('button')[2]!.trigger('click')
    expect(wrapper.get('.surface img').attributes('alt')).toBe('  Renamed product  ')
    await wrapper.setProps({ images: [{ path: 'new.jpg', alt_text: ' ' }, { path: 'new-primary.jpg', is_primary: true, alt_text: ' New view ' }] })
    expect(wrapper.get('.surface img').attributes('alt')).toBe(' New view ')
    expect(thumbnails()).toEqual(['  Renamed product  ', ' New view '])
    expect(images).toEqual(snapshot)
  })
})
