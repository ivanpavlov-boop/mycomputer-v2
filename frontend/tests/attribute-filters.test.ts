import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { paginatedResource } from '../app/utils/apiCollections'
import {
  attributeFilterApiQuery,
  clearAttributeFilters,
  hasAttributeFilters,
  parseAttributeFilterQuery,
  replaceAttributeFilter,
} from '../app/utils/attributeFilters'
import {
  clearPriceFilters,
  parsePriceFilterQuery,
  replacePriceFilters,
} from '../app/utils/priceFilters'

const frontendRoot = resolve(__dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('storefront attribute filters', () => {
  it('parses select multiselect boolean and numeric URL state deterministically', () => {
    const query = {
      'attribute_filters[ram][]': ['16-gb', '32-gb'],
      'attribute_filters[wifi][]': 'yes',
      'attribute_filters[weight][min]': '1.2',
      'attribute_filters[weight][max]': '2.5',
      page: '3',
    }

    expect(parseAttributeFilterQuery(query)).toEqual({
      ram: { values: ['16-gb', '32-gb'] },
      wifi: { values: ['yes'] },
      weight: { min: '1.2', max: '2.5' },
    })
    expect(attributeFilterApiQuery(query)).toEqual({
      'attribute_filters[ram][]': ['16-gb', '32-gb'],
      'attribute_filters[wifi][]': 'yes',
      'attribute_filters[weight][min]': '1.2',
      'attribute_filters[weight][max]': '2.5',
    })
    expect(hasAttributeFilters(query)).toBe(true)
  })

  it('updates one attribute resets pagination and preserves unrelated filters and sorting', () => {
    const query = replaceAttributeFilter({
      category: 'laptops',
      brand: 'lenovo',
      price_min: '1000',
      availability: 'in-stock',
      search: 'business',
      sort: 'price_asc',
      page: '4',
      'attribute_filters[ram][]': '16-gb',
      'attribute_filters[wifi][]': 'yes',
    }, 'ram', { values: ['16-gb', '32-gb'] })

    expect(query).toMatchObject({
      category: 'laptops',
      brand: 'lenovo',
      price_min: '1000',
      availability: 'in-stock',
      search: 'business',
      sort: 'price_asc',
      'attribute_filters[ram][]': ['16-gb', '32-gb'],
      'attribute_filters[wifi][]': 'yes',
    })
    expect(query).not.toHaveProperty('page')
  })

  it('removes one group or all attribute filters without clearing category and search state', () => {
    const current = {
      category: 'laptops',
      search: 'thinkpad',
      page: '2',
      'attribute_filters[ram][]': ['16-gb', '32-gb'],
      'attribute_filters[weight][min]': '1',
    }

    expect(replaceAttributeFilter(current, 'ram', {})).toEqual({
      category: 'laptops',
      search: 'thinkpad',
      'attribute_filters[weight][min]': '1',
    })
    expect(clearAttributeFilters(current)).toEqual({
      category: 'laptops',
      search: 'thinkpad',
    })
  })

  it('ignores malformed keys and unsafe object values', () => {
    const query = {
      'attribute_filters[ram][sql]': 'drop',
      'attribute_filters[Bad Key][]': 'value',
      'attribute_filters[ram][]': { value: '16-gb' },
      attributes: ['legacy-filter'],
    }

    expect(parseAttributeFilterQuery(query)).toEqual({})
    expect(attributeFilterApiQuery(query)).toEqual({})
    expect(hasAttributeFilters(query)).toBe(true)
  })

  it('normalizes product collections with filters and active metadata in one response', () => {
    const response = paginatedResource({
      data: [{ id: 1, slug: 'product' }],
      filters: [{
        key: 'ram',
        label: 'Оперативна памет',
        type: 'select',
        control: 'options',
        position: 10,
        options: [{ key: '16-gb', label: '16 GB' }, { key: '32-gb', label: '32 GB' }],
      }],
      active_filters: [{
        key: 'ram',
        label: 'Оперативна памет',
        type: 'select',
        values: [{ key: '16-gb', label: '16 GB' }],
      }],
      price_filter: {
        key: 'price',
        label: 'Цена',
        control: 'range_slider',
        currency: 'EUR',
        min: 100,
        max: 500,
        step: 0.01,
      },
    })

    expect(response.data).toHaveLength(1)
    expect(response.filters[0]?.key).toBe('ram')
    expect(response.active_filters[0]?.values?.[0]?.label).toBe('16 GB')
    expect(response.price_filter?.control).toBe('range_slider')
  })

  it('updates and clears price only while preserving category search sort and attribute state', () => {
    const current = {
      category: 'laptops',
      search: 'business',
      sort: 'price_asc',
      page: '4',
      'attribute_filters[ram][]': '16-gb',
      price_min: '100',
      price_max: '900',
    }

    expect(parsePriceFilterQuery(current)).toEqual({ min: '100', max: '900' })
    expect(replacePriceFilters(current, { min: '250', max: '750' })).toMatchObject({
      category: 'laptops',
      search: 'business',
      sort: 'price_asc',
      'attribute_filters[ram][]': '16-gb',
      price_min: '250',
      price_max: '750',
    })
    expect(replacePriceFilters(current, { min: '250', max: '750' })).not.toHaveProperty('page')
    expect(clearPriceFilters(current)).toEqual({
      category: 'laptops',
      search: 'business',
      sort: 'price_asc',
      'attribute_filters[ram][]': '16-gb',
    })
  })

  it('renders accessible desktop and mobile filter controls with Bulgarian labels', () => {
    const panel = source('app/components/catalog/AttributeFilterPanel.vue')
    const groups = source('app/components/catalog/AttributeFilterGroups.vue')

    expect(panel).toContain('aria-label="Филтри за каталога"')
    expect(panel).toContain('aria-haspopup="dialog"')
    expect(panel).toContain('role="dialog"')
    expect(panel).toContain('aria-modal="true"')
    expect(panel).toContain('@keydown.esc="closeMobile"')
    expect(panel).toContain('closeButton.value?.focus()')
    expect(panel).toContain('trigger.value?.focus()')
    expect(panel).toContain('Филтри')
    expect(panel).toContain('Покажи продуктите')
    expect(groups).toContain('<fieldset')
    expect(groups).toContain('<legend')
    expect(groups).toContain('type="checkbox"')
    expect(groups).toContain('type="radio"')
    expect(groups).toContain('type="number"')
    expect(groups).toContain("resolvedControl(filter) === 'range_slider'")
    expect(groups).toContain('Минимална стойност')
    expect(groups).toContain('Максимална стойност')
  })

  it('renders option counts only when provided and keeps long labels responsive', () => {
    const groups = source('app/components/catalog/AttributeFilterGroups.vue')

    expect(groups).toContain("typeof option.count === 'number'")
    expect(groups).toContain('min-w-0 break-words')
    expect(groups).toContain('max-h-56')
    expect(groups).toContain('overflow-y-auto')
  })

  it('renders removable active chips with meaningful labels and no raw ids', () => {
    const chips = source('app/components/catalog/ActiveAttributeFilters.vue')

    expect(chips).toContain('Активни филтри по характеристики')
    expect(chips).toContain('Премахни ${filter.label}: ${value.label}')
    expect(chips).toContain('{{ filter.label }}: {{ value.label }}')
    expect(chips).toContain('Изчисти всички')
    expect(chips).not.toContain('filter.id')
    expect(chips).not.toContain('value.id')
  })

  it('wires catalog SSR query state filters chips product grid and empty recovery', () => {
    const page = source('app/pages/catalog.vue')

    expect(page).toContain('attributeFilterApiQuery(route.query)')
    expect(page).toContain('parseAttributeFilterQuery(route.query)')
    expect(page).toContain('replaceAttributeFilter(route.query, key, selection)')
    expect(page).toContain('clearAttributeFilters(route.query)')
    expect(page).toContain('CatalogAttributeFilterPanel')
    expect(page).toContain('CatalogActivePriceFilter')
    expect(page).toContain('replacePriceFilters(route.query, selection)')
    expect(page).toContain('clearPriceFilters(route.query)')
    expect(page).toContain('CatalogActiveAttributeFilters')
    expect(page).toContain('CatalogProductGrid v-if="products.length"')
    expect(page).toContain('CatalogPagination :meta="catalogMeta"')
    expect(page).toContain('Изчисти филтрите по характеристики')
    expect(page).toContain('watch: [catalogQuery, locale]')
  })

  it('wires category SSR query state without changing exact category scope', () => {
    const page = source('app/pages/c/[slug].vue')

    expect(page).toContain('categories.products(slug.value, categoryProductQuery.value)')
    expect(page).toContain('attributeFilterApiQuery(route.query)')
    expect(page).toContain('CatalogAttributeFilterPanel')
    expect(page).toContain("for (const key of ['price_min', 'price_max'] as const)")
    expect(page).toContain('CatalogActivePriceFilter')
    expect(page).toContain('CatalogActiveAttributeFilters')
    expect(page).toContain('ProductGrid v-if="products.length"')
    expect(page).toContain('Pagination :meta="productsMeta"')
    expect(page).toContain('watch: [() => route.params.slug, categoryProductQuery, locale]')
  })

  it('keeps filter metadata escaped and the storefront read only', () => {
    const files = [
      source('app/components/catalog/AttributeFilterGroups.vue'),
      source('app/components/catalog/AttributeFilterPanel.vue'),
      source('app/components/catalog/ActiveAttributeFilters.vue'),
      source('app/components/catalog/DualRangeSlider.vue'),
      source('app/components/catalog/PriceFilterControl.vue'),
      source('app/components/catalog/ActivePriceFilter.vue'),
      source('app/pages/catalog.vue'),
      source('app/pages/c/[slug].vue'),
    ].join('\n')

    expect(files).not.toContain('v-html')
    expect(files).not.toContain('innerHTML')
    expect(files).not.toContain('useCartStore')
    expect(files).not.toContain('/checkout')
    expect(files).not.toContain('/orders')
    expect(files).not.toContain('supplier_products')
    expect(files).not.toContain('CatalogSync')
  })

  it('declares typed public filters without internal quality or supplier metadata', () => {
    const types = source('app/types/api.ts')

    expect(types).toContain('export interface PublicProductAttributeFilter')
    expect(types).toContain("type: 'select' | 'multiselect' | 'boolean' | 'number_range'")
    expect(types).toContain("control: 'options' | 'yes_no' | 'range_slider' | 'min_max'")
    expect(types).toContain('export interface PublicProductPriceFilter')
    expect(types).toContain('active_filters?: PublicProductActiveAttributeFilter[]')
    expect(types).not.toContain('template_source:')
    expect(types).not.toContain('missing_required:')
    expect(types).not.toContain('supplier_id:')
  })
})
