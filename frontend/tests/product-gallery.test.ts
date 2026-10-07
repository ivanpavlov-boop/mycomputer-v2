// @vitest-environment happy-dom
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { compileScript, parse } from '@vue/compiler-sfc'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { ModuleKind, ScriptTarget, transpileModule } from 'typescript'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as Vue from 'vue'
import type { ProductImage } from '../app/types/api'
import * as productGalleryImages from '../app/utils/productGalleryImages'

// Compile the actual SFC in the existing Vitest setup, supplying Nuxt auto-imports.
const filename = resolve(__dirname, '../app/components/product/ProductGallery.vue')
const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename })
const script = compileScript(descriptor, { id: 'product-gallery-test', inlineTemplate: true })
const { outputText } = transpileModule(script.content, {
  compilerOptions: { module: ModuleKind.CommonJS, target: ScriptTarget.ESNext },
})
const componentExports = { default: {} as Vue.Component }
new Function('exports', 'require', 'ref', 'computed', 'watch', 'useRuntimeConfig', 'useImage', outputText)(
  componentExports,
  (id: string) => {
    if (id === '~/utils/productGalleryImages') return productGalleryImages
    if (id !== 'vue') throw new Error(`Unexpected component import: ${id}`)
    return Vue
  },
  Vue.ref,
  Vue.computed,
  Vue.watch,
  () => ({ public: { apiBaseUrl: 'https://catalog.example/api/v1' } }),
  () => (source: string) => source,
)

enableAutoUnmount(afterEach)
afterEach(() => vi.unstubAllGlobals())

function gallery(images: ProductImage[]) {
  return mount(componentExports.default, {
    props: { images, productName: 'Test product' },
    global: {
      stubs: {
        NuxtImg: Vue.defineComponent({
          inheritAttrs: false,
          setup: (_, { attrs }) => () => Vue.h('img', attrs),
        }),
      },
    },
  })
}

const src = (path: string) => `https://catalog.example/storage/${path}`
const placeholder = '\u041d\u044f\u043c\u0430 \u0441\u043d\u0438\u043c\u043a\u0430'

describe('ProductGallery', () => {
  it('starts the first thumbnail row immediately while keeping the main image at high priority', () => {
    const wrapper = gallery(Array.from({ length: 8 }, (_, index) => ({ path: `image-${index}.jpg` })))

    expect(wrapper.get('.surface img').attributes()).toMatchObject({ loading: 'eager', fetchpriority: 'high' })
    expect(wrapper.findAll('button img').map(image => image.attributes('loading')))
      .toEqual(['eager', 'eager', 'eager', 'eager', 'eager', 'lazy', 'lazy', 'lazy'])
  })

  it('uses the resized alias only for thumbnails and keeps the selected full image and image order', async () => {
    const images = [
      { path: 'https://apcom.shop/media/catalog/product/a/front.jpg' },
      { path: 'https://apcom.shop/media/catalog/product/a/back.jpg', is_primary: true },
    ]
    const wrapper = gallery(images)

    expect(wrapper.get('.surface img').attributes('src')).toBe(images[1]!.path)
    expect(wrapper.findAll('button img').map(image => image.attributes('src')))
      .toEqual(['/product-gallery/apcom/a/front.jpg', '/product-gallery/apcom/a/back.jpg'])
    await wrapper.findAll('button')[0]!.trigger('click')
    expect(wrapper.get('.surface img').attributes('src')).toBe(images[0]!.path)
  })

  it('retries a failed thumbnail with the original before removing it and resets on a new image set', async () => {
    const images = [
      { path: 'https://apcom.shop/media/catalog/product/a/primary.jpg', is_primary: true },
      { path: 'https://apcom.shop/media/catalog/product/a/other.jpg' },
    ]
    const wrapper = gallery(images)

    await wrapper.findAll('button img')[0]!.trigger('error')
    expect(wrapper.findAll('button')).toHaveLength(2)
    expect(wrapper.get('.surface img').attributes('src')).toBe(images[0]!.path)
    expect(wrapper.findAll('button img')[0]!.attributes()).toMatchObject({ src: images[0]!.path, provider: 'none' })

    await wrapper.findAll('button img')[0]!.trigger('error')
    expect(wrapper.get('.surface img').attributes('src')).toBe(images[1]!.path)

    await wrapper.setProps({ images: [...images] })
    expect(wrapper.findAll('button img')[0]!.attributes('src')).toBe('/product-gallery/apcom/a/primary.jpg')
    expect(wrapper.findAll('button img')[0]!.attributes('provider')).toBeUndefined()
  })

  it('preloads full images only on hover or keyboard focus and deduplicates intent', async () => {
    const requested: Array<{ src?: string; fetchPriority?: string }> = []
    vi.stubGlobal('Image', class {
      constructor() { requested.push(this) }
    })
    const wrapper = gallery([{ path: 'primary.jpg', is_primary: true }, { path: 'other.jpg' }])
    expect(requested).toHaveLength(0)
    await wrapper.findAll('button')[0]!.trigger('mouseenter')
    expect(requested).toHaveLength(0)
    await wrapper.findAll('button')[1]!.trigger('mouseenter')
    await wrapper.findAll('button')[1]!.trigger('focus')
    expect(requested).toEqual([{ src: src('other.jpg'), fetchPriority: 'low' }])
  })

  it.each([1, 2])('initially displays the primary image at index %i', (primaryIndex) => {
    const images = ['first.jpg', 'second.jpg', 'third.jpg'].map((path, index) => ({
      path, is_primary: index === primaryIndex,
    }))
    const wrapper = gallery(images)

    expect(wrapper.get('.surface img').attributes('src')).toBe(src(images[primaryIndex]!.path))
  })

  it('uses the first image when primary flags are false or absent', () => {
    const wrapper = gallery([{ path: 'first.jpg', is_primary: false }, { path: 'second.jpg' }])

    expect(wrapper.get('.surface img').attributes('src')).toBe(src('first.jpg'))
  })

  it('keeps the existing empty state for an empty image set', () => {
    const wrapper = gallery([])

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.get('.surface').text()).toContain(placeholder)
  })

  it('selects the new primary or first image when the input array is replaced', async () => {
    const wrapper = gallery([{ path: 'old.jpg', is_primary: true }, { path: 'chosen.jpg' }])
    await wrapper.findAll('button')[1]!.trigger('click')

    await wrapper.setProps({ images: [{ path: 'new-first.jpg' }, { path: 'new-primary.jpg', is_primary: true }] })
    expect(wrapper.get('.surface img').attributes('src')).toBe(src('new-primary.jpg'))

    await wrapper.setProps({ images: [{ path: 'fallback.jpg' }, { path: 'other.jpg' }] })
    expect(wrapper.get('.surface img').attributes('src')).toBe(src('fallback.jpg'))
  })

  it('handles empty to populated and populated to empty transitions', async () => {
    const wrapper = gallery([])

    await wrapper.setProps({ images: [{ path: 'first.jpg' }, { path: 'primary.jpg', is_primary: true }] })
    expect(wrapper.get('.surface img').attributes('src')).toBe(src('primary.jpg'))

    await wrapper.setProps({ images: [] })
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.get('.surface').text()).toContain(placeholder)
  })

  it('preserves manual selection across unrelated rerenders and leaves input and thumbnail order unchanged', async () => {
    const images = [
      { path: 'first.jpg', sort_order: 3 },
      { path: 'primary.jpg', is_primary: true, sort_order: 1 },
      { path: 'chosen.jpg', sort_order: 2 },
    ]
    const snapshot = structuredClone(images)
    images.forEach(Object.freeze)
    Object.freeze(images)
    const wrapper = gallery(images)
    const thumbnailSources = () => wrapper.findAll('button img').map(image => image.attributes('src'))

    expect(thumbnailSources()).toEqual(snapshot.map(image => src(image.path)))
    await wrapper.findAll('button')[2]!.trigger('click')
    expect(wrapper.get('.surface img').attributes('src')).toBe(src('chosen.jpg'))

    await wrapper.setProps({ productName: 'Renamed product' })
    expect(wrapper.get('.surface img').attributes('src')).toBe(src('chosen.jpg'))
    expect(wrapper.get('.surface img').attributes('alt')).toBe('Renamed product')
    expect(thumbnailSources()).toEqual(snapshot.map(image => src(image.path)))
    expect(images).toEqual(snapshot)
  })

  it('falls back through remaining images after errors and resets failures on array replacement', async () => {
    const images = [{ path: 'first.jpg' }, { path: 'primary.jpg', is_primary: true }, { path: 'last.jpg' }]
    const wrapper = gallery(images)

    await wrapper.get('.surface img').trigger('error')
    expect(wrapper.get('.surface img').attributes('src')).toBe(src('first.jpg'))
    expect(wrapper.findAll('button img').map(image => image.attributes('src'))).toEqual([src('first.jpg'), src('last.jpg')])

    await wrapper.get('.surface img').trigger('error')
    expect(wrapper.get('.surface img').attributes('src')).toBe(src('last.jpg'))
    await wrapper.get('.surface img').trigger('error')
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.get('.surface').text()).toContain(placeholder)

    await wrapper.setProps({ images: [...images] })
    expect(wrapper.get('.surface img').attributes('src')).toBe(src('primary.jpg'))
  })
})
