import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { collectionData } from '../app/utils/apiCollections'

const frontendRoot = resolve(__dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('catalog page', () => {
  it('parses paginated product API responses with data arrays', () => {
    const product = { id: 101, slug: 'public-product', name: 'Public Product' }

    expect(collectionData({ data: [product], meta: { total: 1 } })).toEqual([product])
    expect(collectionData({ data: [] })).toEqual([])
  })

  it('fetches products from the public products API and parses the response collection', () => {
    const page = source('app/pages/catalog.vue')

    expect(page).toContain('useProducts')
    expect(page).toContain('productsApi.list')
    expect(page).toContain('per_page: route.query.per_page || 24')
    expect(page).toContain('collectionData<ProductCard>(productsResponse.value)')
  })

  it('renders product grid pagination and Bulgarian empty state only after an empty data array', () => {
    const page = source('app/pages/catalog.vue')

    expect(page).toContain('CatalogProductGrid v-if="products.length"')
    expect(page).toContain('CatalogPagination :meta="productsResponse?.meta"')
    expect(page).toContain('Няма активни продукти за показване.')
    expect(page).toContain('Намерени продукти: {{ productsResponse.meta.total }}')
  })

  it('renders visible product cards from a paginated API collection', () => {
    const page = source('app/pages/catalog.vue')
    const grid = source('app/components/catalog/ProductGrid.vue')
    const card = source('app/components/catalog/ProductCard.vue')
    const product = {
      id: 101,
      sku: 'LOGI-FOLIO-001',
      slug: 'logitech-universal-folio-keyboard',
      name: 'Logitech Universal Folio Keyboard',
      price: '99.00',
      quantity: 3,
      stock_status: 'in_stock',
      featured: false,
      new_product: false,
      bestseller: false,
      primary_image: null,
    }

    const products = collectionData({ data: [product], links: {}, meta: { total: 1 } })

    expect(products).toHaveLength(1)
    expect(products[0]?.name).toBe('Logitech Universal Folio Keyboard')
    expect(page).toContain('CatalogProductGrid v-if="products.length" :products="products"')
    expect(grid).toContain('CatalogProductCard v-for="product in products"')
    expect(card).toContain('{{ product.name }}')
    expect(card).toContain(':to="`/p/${product.slug}`"')
    expect(card).toContain('Няма снимка')
  })

  it('renders the empty state only when there are no products', () => {
    const products = collectionData({ data: [], links: {}, meta: { total: 0 } })
    const page = source('app/pages/catalog.vue')

    expect(products).toEqual([])
    expect(page).toContain('UiEmptyState')
    expect(page).toContain('v-else')
  })

  it('keeps product cards linked to product detail pages with image placeholders', () => {
    const card = source('app/components/catalog/ProductCard.vue')

    expect(card).toContain(':to="`/p/${product.slug}`"')
    expect(card).toContain('Няма снимка')
    expect(card).toContain('Виж продукта')
  })

  it('keeps catalog read-only with no unfinished assistant or commerce flows', () => {
    const app = source('app/app.vue')
    const page = source('app/pages/catalog.vue')

    expect(app).toContain("route.path !== '/catalog'")
    expect(page).not.toContain('AiChatWidget')
    expect(page).not.toContain('useAiAssistant')
    expect(page).not.toContain('useCartStore')
    expect(page).not.toContain('checkout')
    expect(page).not.toContain('/orders')
  })
})
