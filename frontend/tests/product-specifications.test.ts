import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(__dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('public product specifications', () => {
  it('uses the existing Product detail response without a second specification request', () => {
    const page = source('app/pages/p/[slug].vue')
    const products = source('app/composables/useProducts.ts')

    expect(page).toContain(':specification-groups="product.specification_groups || []"')
    expect(page).toContain('products.detail(slug.value)')
    expect(products).toContain('api.get<{ data: ProductDetail } | ProductDetail>(`/products/${slug}`)')
    expect(products).not.toContain('/specifications')
  })

  it('shows the Characteristics tab only when displayable groups exist', () => {
    const tabs = source('app/components/product/ProductTabs.vue')

    expect(tabs).toContain('props.specificationGroups.length')
    expect(tabs).toContain("{ key: 'specifications' as const, label: 'Характеристики' }")
    expect(tabs).toContain('v-else-if="specificationGroups.length"')
    expect(tabs).toContain('<ProductSpecifications :groups="specificationGroups" />')
    expect(tabs).toContain("active.value = 'description'")
  })

  it('preserves the Description tab and existing empty description copy', () => {
    const tabs = source('app/components/product/ProductTabs.vue')

    expect(tabs).toContain("{ key: 'description' as const, label: 'Описание' }")
    expect(tabs).toContain("v-if=\"active === 'description'\"")
    expect(tabs).toContain('Няма добавено подробно описание.')
  })

  it('renders ordered groups and rows with semantic customer-facing markup', () => {
    const component = source('app/components/product/ProductSpecifications.vue')

    expect(component).toContain('v-for="group in visibleGroups"')
    expect(component).toContain('v-for="item in group.items"')
    expect(component).toContain('<section')
    expect(component).toContain('<h2')
    expect(component).toContain('<h3')
    expect(component).toContain('<dl')
    expect(component).toContain('<dt')
    expect(component).toContain('<dd')
    expect(component).toContain('{{ group.label }}')
    expect(component).toContain('{{ item.label }}')
    expect(component).toContain('{{ item.display_value }}')
  })

  it('uses normal Vue escaping and never renders specification HTML', () => {
    const component = source('app/components/product/ProductSpecifications.vue')

    expect(component).not.toContain('v-html')
    expect(component).not.toContain('innerHTML')
    expect(component).not.toContain('dangerouslySetInnerHTML')
    expect(component).toContain('{{ item.display_value }}')
  })

  it('keeps long labels multiselect values and units responsive without horizontal page overflow', () => {
    const component = source('app/components/product/ProductSpecifications.vue')

    expect(component).toContain('min-w-0')
    expect(component).toContain('break-words')
    expect(component).toContain('sm:grid-cols-[minmax(0,40%)_minmax(0,1fr)]')
    expect(component).not.toContain('whitespace-nowrap')
    expect(component).not.toContain('overflow-x-auto')
  })

  it('keeps tab and panel relationships accessible with visible focus styles', () => {
    const tabs = source('app/components/product/ProductTabs.vue')
    const component = source('app/components/product/ProductSpecifications.vue')

    expect(tabs).toContain('role="tablist"')
    expect(tabs).toContain('role="tab"')
    expect(tabs).toContain(':aria-selected="active === tab.key"')
    expect(tabs).toContain(':aria-controls="`product-panel-${tab.key}`"')
    expect(tabs).not.toContain(':tabindex="active === tab.key ? 0 : -1"')
    expect(tabs).toContain('role="tabpanel"')
    expect(tabs).toContain('focus-visible:outline')
    expect(component).toContain(':aria-labelledby="`specification-group-${group.key}`"')
  })

  it('keeps loading error not-found gallery price and availability behavior intact', () => {
    const page = source('app/pages/p/[slug].vue')

    expect(page).toContain('UiLoadingState v-if="pending"')
    expect(page).toContain('v-else-if="error || !product"')
    expect(page).toContain('ProductGallery')
    expect(page).toContain('ProductPrice')
    expect(page).toContain('ProductAvailabilityBadge')
    expect(page).toContain('ProductStockBadge')
  })

  it('defines a typed specification contract and remains SSR deterministic', () => {
    const types = source('app/types/api.ts')
    const tabs = source('app/components/product/ProductTabs.vue')
    const component = source('app/components/product/ProductSpecifications.vue')

    expect(types).toContain('export interface ProductSpecificationItem')
    expect(types).toContain('display_value: string')
    expect(types).toContain('export interface ProductSpecificationGroup')
    expect(types).toContain('specification_groups: ProductSpecificationGroup[]')
    expect(tabs).not.toContain('<ClientOnly')
    expect(component).not.toContain('<ClientOnly')
    expect(component).not.toContain('window.')
    expect(component).not.toContain('document.')
  })

  it('does not add commerce comparison filters or supplier data to Product detail', () => {
    const page = source('app/pages/p/[slug].vue')
    const component = source('app/components/product/ProductSpecifications.vue')
    const combined = `${page}\n${component}`

    expect(combined).not.toContain('useCartStore')
    expect(combined).not.toContain('checkout')
    expect(combined).not.toContain('useCompareStore')
    expect(combined).not.toContain('ProductFilters')
    expect(combined).not.toContain('supplier_products')
  })
})
