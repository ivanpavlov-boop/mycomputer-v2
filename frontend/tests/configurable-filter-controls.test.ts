import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { boundedRange, normalizedRangeSelection } from '../app/utils/rangeValues'

const frontendRoot = resolve(__dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('configurable storefront filter controls', () => {
  it('bounds integer and decimal ranges without allowing crossed handles', () => {
    expect(boundedRange(1, 10, 1, 3, 8)).toEqual({ minimum: 3, maximum: 8 })
    expect(boundedRange(1.1, 3.8, 0.1, 1.26, 3.74)).toEqual({ minimum: 1.3, maximum: 3.7 })
    expect(boundedRange(0, 100, 1, 90, 20)).toEqual({ minimum: 20, maximum: 20 })
    expect(boundedRange(0, 100, 1, undefined, undefined)).toEqual({ minimum: 0, maximum: 100 })
  })

  it('removes redundant full-range URL bounds and preserves partial bounds', () => {
    expect(normalizedRangeSelection(100, 900, 100, 900)).toEqual({ min: undefined, max: undefined })
    expect(normalizedRangeSelection(100, 900, 250, 900)).toEqual({ min: '250', max: undefined })
    expect(normalizedRangeSelection(100, 900, 100, 750)).toEqual({ min: undefined, max: '750' })
  })

  it('renders two native accessible handles and commits separately from local input', () => {
    const slider = source('app/components/catalog/DualRangeSlider.vue')

    expect(slider.match(/type="range"/g)).toHaveLength(2)
    expect(slider).toContain('Минимална стойност за ${label}')
    expect(slider).toContain('Максимална стойност за ${label}')
    expect(slider).toContain('@input="updateMinimum"')
    expect(slider).toContain('@input="updateMaximum"')
    expect(slider).toContain('@change="commit"')
    expect(slider).toContain("emit('update:modelMin', value)")
    expect(slider).toContain("emit('update:modelMax', value)")
    expect(slider).toContain("emit('commit', { minimum: localMinimum.value, maximum: localMaximum.value })")
    expect(slider).toContain('width: 32px')
    expect(slider).not.toContain('router.push')
    expect(slider).not.toContain('v-html')
  })

  it('renders options yes-no min-max and slider controls with compatible fallbacks', () => {
    const groups = source('app/components/catalog/AttributeFilterGroups.vue')

    expect(groups).toContain("resolvedControl(filter) === 'options'")
    expect(groups).toContain("resolvedControl(filter) === 'yes_no'")
    expect(groups).toContain("resolvedControl(filter) === 'min_max'")
    expect(groups).toContain("resolvedControl(filter) === 'range_slider'")
    expect(groups).toContain('type="checkbox"')
    expect(groups).toContain('type="radio"')
    expect(groups).toContain('type="number"')
    expect(groups).toContain('@change="commitRange')
    expect(groups).toContain('@blur="commitRange')
    expect(groups).toContain('@keyup.enter="commitRange')
    expect(groups).toContain("return 'yes_no'")
    expect(groups).toContain("return 'options'")
  })

  it('uses the same price slider in desktop and mobile with explicit clear semantics', () => {
    const panel = source('app/components/catalog/AttributeFilterPanel.vue')
    const price = source('app/components/catalog/PriceFilterControl.vue')
    const activePrice = source('app/components/catalog/ActivePriceFilter.vue')

    expect(panel.match(/<CatalogPriceFilterControl/g)).toHaveLength(2)
    expect(panel).toContain("$emit('clear-price')")
    expect(panel).toContain("$emit('clear-attributes')")
    expect(panel).toContain('Изчисти характеристиките')
    expect(price).toContain('<CatalogDualRangeSlider')
    expect(price).toContain(':currency="filter.currency"')
    expect(price).toContain('normalizedRangeSelection')
    expect(activePrice).toContain('Активен ценови филтър')
    expect(activePrice).toContain('style: \'currency\'')
  })

  it('keeps catalog pages SSR-driven read-only and free of commerce mutations', () => {
    const files = [
      source('app/pages/catalog.vue'),
      source('app/pages/c/[slug].vue'),
      source('app/components/catalog/AttributeFilterPanel.vue'),
      source('app/components/catalog/AttributeFilterGroups.vue'),
      source('app/components/catalog/PriceFilterControl.vue'),
    ].join('\n')

    expect(files).toContain('useAsyncData')
    expect(files).toContain('watch: [catalogQuery, locale]')
    expect(files).toContain('watch: [() => route.params.slug, categoryProductQuery, locale]')
    expect(files).not.toContain('v-html')
    expect(files).not.toContain('useCartStore')
    expect(files).not.toContain('/checkout')
    expect(files).not.toContain('/orders')
    expect(files).not.toContain('supplier_products')
    expect(files).not.toContain('CatalogSync')
  })
})
