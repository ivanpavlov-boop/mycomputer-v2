import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { collectionData, paginatedResource } from '../app/utils/apiCollections'

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

  it('uses the directory-prefixed Nuxt component names registered for the category listing', () => {
    const page = source('app/pages/c/[slug].vue')
    const expectedComponents = [
      'LayoutBreadcrumbs',
      'UiLoadingState',
      'UiErrorState',
      'CatalogSortSelect',
      'CatalogProductGrid',
      'UiEmptyState',
      'CatalogPagination',
    ]
    const obsoleteComponents = [
      'Breadcrumbs',
      'LoadingState',
      'ErrorState',
      'SortSelect',
      'ProductGrid',
      'EmptyState',
      'Pagination',
    ]

    for (const component of expectedComponents) {
      expect(page).toMatch(new RegExp(`<${component}(?:\\s|>)`))
    }

    for (const component of obsoleteComponents) {
      expect(page).not.toMatch(new RegExp(`<\\/?${component}(?:\\s|>)`))
    }
  })

  it('renders category title product cards pagination and Bulgarian empty state', () => {
    const page = source('app/pages/c/[slug].vue')

    expect(page).toContain('{{ category.localized?.name || category.name }}')
    expect(page).toContain('CatalogProductGrid v-if="products.length"')
    expect(page).toContain('CatalogPagination :meta="productsMeta"')
    expect(page).toContain('Няма активни продукти в тази категория.')
    expect(page).toContain('Всички категории')
    expect(page).toContain('localePath(\'/categories\')')
  })

  it('keeps one product on the grid path with its product card data intact', () => {
    const page = source('app/pages/c/[slug].vue')
    const grid = source('app/components/catalog/ProductGrid.vue')
    const card = source('app/components/catalog/ProductCard.vue')
    const response = paginatedResource({
      data: [{
        id: 501,
        slug: 'lenovo-thinkpad-e16-gen-2',
        name: 'Lenovo ThinkPad E16 Gen 2',
        price: '1699.00',
        stock_status: 'in_stock',
        category: { id: 50, name: 'Business Laptops', slug: 'business-laptops' },
        brand: { id: 5, name: 'Lenovo', slug: 'lenovo' },
        primary_image: null,
      }],
      meta: { total: 1, current_page: 1, last_page: 1 },
    })

    expect(response.data).toHaveLength(1)
    expect(response.meta?.total).toBe(1)
    expect(response.data[0]).toMatchObject({
      name: 'Lenovo ThinkPad E16 Gen 2',
      price: '1699.00',
      category: { name: 'Business Laptops' },
      brand: { name: 'Lenovo' },
    })
    expect(page).toContain('CatalogProductGrid v-if="products.length" :products="products"')
    expect(grid).toContain('CatalogProductCard v-for="product in products"')
    expect(card).toContain('{{ productName }}')
    expect(card).toContain('product.category.name')
    expect(card).toContain('product.brand.name')
    expect(card).toContain('ProductPrice')
  })

  it('keeps zero products on the category-specific empty-state path', () => {
    const page = source('app/pages/c/[slug].vue')
    const response = paginatedResource({
      data: [],
      meta: { total: 0, current_page: 1, last_page: 1 },
    })

    expect(response.data).toEqual([])
    expect(response.meta?.total).toBe(0)
    expect(page).toContain('<UiEmptyState')
    expect(page).toContain('Няма активни продукти в тази категория.')
    expect(page).toContain('v-if="hasAttributeRouteFilters"')
    expect(page).toContain('v-if="hasPriceRouteFilters"')
    expect(page).toContain('localePath(\'/categories\')')
  })

  it('keeps multiple products and pagination metadata on the listing path', () => {
    const page = source('app/pages/c/[slug].vue')
    const pagination = source('app/components/catalog/Pagination.vue')
    const response = paginatedResource({
      data: [
        { id: 1, slug: 'product-one', name: 'Product One' },
        { id: 2, slug: 'product-two', name: 'Product Two' },
      ],
      meta: { total: 26, current_page: 1, last_page: 2 },
    })

    expect(response.data).toHaveLength(2)
    expect(response.meta).toMatchObject({ total: 26, current_page: 1, last_page: 2 })
    expect(page).toContain('CatalogProductGrid v-if="products.length" :products="products"')
    expect(page).toContain('CatalogPagination :meta="productsMeta" @change="setPage"')
    expect(pagination).toContain('lastPage.value > 1')
  })

  it('keeps loading error and filter components on their existing branches', () => {
    const page = source('app/pages/c/[slug].vue')

    expect(page).toContain('<UiLoadingState v-if="categoryPending || productsPending"')
    expect(page).toContain('v-else-if="categoryError"')
    expect(page).toContain('v-else-if="productsError"')
    expect(page.match(/<UiErrorState/g)).toHaveLength(3)
    expect(page).toContain('<CatalogAttributeFilterPanel')
    expect(page).toContain('<CatalogActivePriceFilter')
    expect(page).toContain('<CatalogActiveAttributeFilters')
    expect(page).toContain(':filters="attributeFilters"')
    expect(page).toContain(':price-filter="priceFilter"')
    expect(page).toContain(':filters="activeAttributeFilters"')
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
