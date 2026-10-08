// @vitest-environment happy-dom
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { compileScript, parse } from '@vue/compiler-sfc'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { ModuleKind, ScriptTarget, transpileModule } from 'typescript'
import { afterEach, describe, expect, it } from 'vitest'
import * as Vue from 'vue'

function component(relativePath: string) {
  const filename = resolve(__dirname, relativePath)
  const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename })
  const compiled = compileScript(descriptor, { id: relativePath, inlineTemplate: true })
  const { outputText } = transpileModule(compiled.content, { compilerOptions: { module: ModuleKind.CommonJS, target: ScriptTarget.ESNext } })
  const exports = { default: {} as Vue.Component }
  new Function('exports', 'require', 'ref', 'computed', 'watch', 'useRuntimeConfig', 'useLocalePath', outputText)(
    exports, (id: string) => { if (id !== 'vue') throw new Error(id); return Vue },
    Vue.ref, Vue.computed, Vue.watch,
    () => ({ public: { apiBaseUrl: '/api/v1' } }), () => (p: string) => '/en' + p,
  )
  return exports.default
}
const Card = component('../app/components/catalog/ProductCard.vue')
const Grid = component('../app/components/catalog/ProductGrid.vue')
const global = {
  components: { CatalogProductCard: Card },
  stubs: {
    NuxtImg: Vue.defineComponent({ inheritAttrs: false, setup: (_, { attrs }) => () => Vue.h('img', attrs) }),
    NuxtLink: Vue.defineComponent({ props: ['to'], setup: (p, { slots }) => () => Vue.h('a', { href: p.to }, slots.default?.()) }),
    ProductAvailabilityBadge: true, ProductStockBadge: true, ProductPrice: true, UiEmptyState: true,
  },
}
const product = (id = 1) => ({
  id, sku: 'TEST', slug: `test-${id}`, name: 'Base', localized: { name: 'Localized' },
  price: '12.00', stock_status: 'in_stock',
  primary_image: { path: `https://apcom.shop/media/catalog/product/${id}.png`, alt_text: '  Manual alt  ' },
})
enableAutoUnmount(afterEach)

describe('real ProductCard and ProductGrid loading behavior', () => {
  it('keeps bounded variants, verbatim ALT and localized product links', () => {
    const p = product()
    const before = structuredClone(p)
    const wrapper = mount(Card, { props: { product: p }, global })
    const img = wrapper.get('img')
    expect(img.attributes()).toMatchObject({ provider: 'card', loading: 'lazy', densities: 'x1 x2', sizes: '640px sm:320px xl:240px', alt: '  Manual alt  ' })
    expect(wrapper.get('a').attributes('href')).toBe('/en/p/test-1')
    expect(img.attributes('src')).toBe(p.primary_image.path)
    expect(p).toEqual(before)
  })

  it('falls back to original, then placeholder, and resets for a replacement product', async () => {
    const wrapper = mount(Card, { props: { product: product() }, global })
    await wrapper.get('img').trigger('error')
    expect(wrapper.get('img').attributes('provider')).toBe('none')
    expect(wrapper.get('img').attributes('src')).toBe(product().primary_image.path)
    await wrapper.get('img').trigger('error')
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('Няма снимка')
    await wrapper.setProps({ product: product(2) })
    expect(wrapper.get('img').attributes('provider')).toBe('card')
    await wrapper.setProps({ product: { ...product(2), primary_image: null } })
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('prioritizes only two leading cards with just one high priority image', async () => {
    const products = Array.from({ length: 24 }, (_, i) => product(i))
    const wrapper = mount(Grid, { props: { products, prioritizeImages: true }, global })
    expect(wrapper.findAll('img').map(i => i.attributes('loading'))).toEqual(['eager', 'eager', ...Array(22).fill('lazy')])
    expect(wrapper.findAll('img[fetchpriority="high"]')).toHaveLength(1)
    await wrapper.setProps({ products: products.slice().reverse() })
    expect(wrapper.get('img[fetchpriority="high"]').attributes('src')).toBe(product(23).primary_image.path)
    await wrapper.setProps({ prioritizeImages: false })
    expect(wrapper.findAll('img').every(i => i.attributes('loading') === 'lazy')).toBe(true)
    expect(wrapper.find('img[fetchpriority="high"]').exists()).toBe(false)
  })
})
