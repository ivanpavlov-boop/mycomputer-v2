import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { collectionData } from '../app/utils/apiCollections'

const frontendRoot = resolve(__dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('category detail page', () => {
  it('parses paginated category product API responses with data arrays', () => {
    const product = { id: 10, slug: 'iphone-15', name: 'iPhone 15' }

    expect(collectionData({ data: [product], meta: { total: 1 } })).toEqual([product])
    expect(collectionData({ data: [] })).toEqual([])
  })

  it('fetches category details and products from the public category API endpoints', () => {
    const page = source('app/pages/c/[slug].vue')

    expect(page).toContain('categories.detail(slug.value)')
    expect(page).toContain('categories.products(slug.value')
    expect(page).toContain('paginatedResource<ProductCard>(productsResponse.value)')
    expect(page).toContain('per_page: positiveInteger(route.query.per_page, 24)')
    expect(page).toContain('normalizeCatalogSort(route.query.sort)')
    expect(page).toContain('categories.products(slug.value, categoryProductQuery.value)')
    expect(page).not.toContain('{ ...route.query, per_page: route.query.per_page || 24 }')
  })

  it('renders category title product cards pagination and Bulgarian empty state', () => {
    const page = source('app/pages/c/[slug].vue')

    expect(page).toContain('{{ category.localized?.name || category.name }}')
    expect(page).toContain('ProductGrid v-if="products.length"')
    expect(page).toContain('Pagination :meta="productsMeta"')
    expect(page).toContain('Няма активни продукти в тази категория.')
    expect(page).toContain('Всички категории')
    expect(page).toContain('localePath(\'/categories\')')
  })

  it('normalizes category sort search and pagination query values safely', () => {
    const page = source('app/pages/c/[slug].vue')

    expect(page).toContain('const categoryProductQuery = computed(() => {')
    expect(page).toContain('sort: sort.value')
    expect(page).toContain('const page = positiveInteger(route.query.page, 1)')
    expect(page).toContain('query.page = page')
    expect(page).toContain('const search = routeQueryValue(route.query.search) || routeQueryValue(route.query.q)')
    expect(page).toContain("updateQuery({ page: page > 1 ? page : undefined })")
    expect(page).toContain("key === 'sort' ? normalizeCatalogSort(value) : routeQueryValue(value)")
  })

  it('keeps product links read-only and pointed at product detail pages', () => {
    const productCard = source('app/components/catalog/ProductCard.vue')
    const page = source('app/pages/c/[slug].vue')

    expect(productCard).toContain(':to="localePath(`/p/${product.slug}`)"')
    expect(productCard).toContain('Виж продукта')
    expect(page).not.toContain('useCartStore')
    expect(page).not.toContain('checkout')
    expect(page).not.toContain('OrderHistory')
    expect(page).not.toContain('/orders')
  })

  it('does not render or enable the unfinished AI assistant on category detail pages', () => {
    const app = source('app/app.vue')
    const page = source('app/pages/c/[slug].vue')

    expect(app).toContain('const isReadOnlyStorefrontRoute = useReadOnlyStorefrontRoute()')
    expect(app).toContain('const showAiChatWidget = computed(() => !isReadOnlyStorefrontRoute.value)')
    expect(page).not.toContain('AiChatWidget')
    expect(page).not.toContain('useAiAssistant')
  })
})
