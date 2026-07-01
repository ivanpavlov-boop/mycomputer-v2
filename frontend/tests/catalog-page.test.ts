import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { collectionData, paginatedResource, resourceCollection } from '../app/utils/apiCollections'
import { catalogSortOptions, normalizeCatalogSort } from '../app/utils/catalogSorts'
import { positiveInteger, queryString, routeQueryValue } from '../app/utils/routeQuery'

const frontendRoot = resolve(__dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('catalog page', () => {
  it('parses paginated product API responses with data arrays', () => {
    const product = { id: 101, slug: 'public-product', name: 'Public Product' }

    expect(collectionData({ data: [product], meta: { total: 1 } })).toEqual([product])
    expect(resourceCollection({ data: [product], links: {}, meta: { total: '1', current_page: '1', last_page: '1' } })).toMatchObject({
      data: [product],
      links: {},
      meta: {
        total: 1,
        current_page: 1,
        last_page: 1,
      },
    })
    expect(collectionData({ data: [] })).toEqual([])
  })

  it('normalizes valid search sort and sparse paginated responses without errors', () => {
    const product = {
      id: 1020,
      sku: '920-011851',
      name: 'Logitech Pebble Keys 2 Slim Keyboard',
      slug: 'logitech-pebble-keys-2-slim-keyboard',
      price: '26.87',
      stock_status: 'out_of_stock',
      availability: {
        code: 'out_of_stock',
        name: 'Out Of Stock',
        message: null,
      },
      brand: null,
      category: null,
      primary_image: null,
      short_description: null,
    }

    expect(paginatedResource({ data: [product] }).data).toEqual([product])
    expect(paginatedResource({ data: [product], links: {}, meta: {} }).data).toEqual([product])
    expect(paginatedResource({ data: [product], links: [], meta: null })).toMatchObject({
      data: [product],
      links: {},
    })
    expect(() => paginatedResource({ data: [product], links: {}, meta: {} })).not.toThrow()
  })

  it('fetches products from the public products API and parses the response collection', () => {
    const page = source('app/pages/catalog.vue')

    expect(page).toContain('useProducts')
    expect(page).toContain('productsApi.list')
    expect(page).toContain('paginatedResource<ProductCard>(await productsApi.list(catalogQuery.value))')
    expect(page).toContain('per_page: positiveInteger(route.query.per_page, 24)')
    expect(page).not.toContain('productsApi.list({ ...route.query')
    expect(page).toContain('productsResponse.value?.data ?? []')
  })

  it('maps search sort and pagination to supported API query params only', () => {
    const page = source('app/pages/catalog.vue')
    const sort = source('app/components/catalog/SortSelect.vue')
    const pagination = source('app/components/catalog/Pagination.vue')

    expect(page).toContain('query.search = activeSearch.value')
    expect(page).toContain("updateQuery({ search: search || undefined, q: undefined, page: undefined })")
    expect(page).toContain('const search = searchTerm.value.trim()')
    expect(page).toContain('normalizeCatalogSort(route.query.sort)')
    expect(page).toContain('query.sort = sort.value')
    expect(page).toContain('page: page > 1 ? page : undefined')
    expect(page).toContain('routeQueryValue(value)')
    expect(sort).toContain('<select')
    expect(sort).toContain(':value="currentSort"')
    expect(sort).toContain('@change="handleSortChange"')
    expect(sort).toContain('(event.target as HTMLSelectElement | null)?.value')
    expect(sort).toContain('normalizeCatalogSort(value)')
    expect(sort).not.toContain('UiBaseSelect')
    expect(sort).not.toContain('@update:model-value')
    expect(sort).not.toContain('$emit')
    expect(sort).toContain('catalogSortOptions')
    expect(pagination).toContain('<UiBaseButton')
  })

  it('normalizes search page and route query values without leaking invalid params', () => {
    expect(queryString('  logitech  ')).toBe('logitech')
    expect(queryString(['  logitech  '])).toBe('logitech')
    expect(queryString({ value: 'logitech' })).toBe('')
    expect(queryString(undefined)).toBe('')
    expect(queryString(null)).toBe('')
    expect(routeQueryValue('  ')).toBeUndefined()
    expect(routeQueryValue(['', '  '])).toBeUndefined()
    expect(routeQueryValue(['  logitech  ', 'ignored'])).toEqual(['logitech', 'ignored'])
    expect(routeQueryValue({ label: 'Newest', value: 'price_asc' })).toBeUndefined()
    expect(positiveInteger('2', 1)).toBe(2)
    expect(positiveInteger('0', 1)).toBe(1)
    expect(positiveInteger('-3', 1)).toBe(1)
    expect(positiveInteger('not-a-page', 1)).toBe(1)
  })

  it('keeps catalog sort values compatible with the public products API', () => {
    const supportedOptions = [
      { label: 'Най-нови', value: 'newest' },
      { label: 'Цена възходящо', value: 'price_asc' },
      { label: 'Цена низходящо', value: 'price_desc' },
      { label: 'Име А-Я', value: 'name_asc' },
      { label: 'Име Я-А', value: 'name_desc' },
      { label: 'Препоръчани', value: 'featured' },
      { label: 'Най-продавани', value: 'bestseller' },
    ] as const

    for (const option of supportedOptions) {
      expect(normalizeCatalogSort(option.value)).toBe(option.value)
    }

    expect(normalizeCatalogSort(['price_asc'])).toBe('price_asc')
    expect(normalizeCatalogSort({ label: 'Newest', value: 'newest' })).toBe('newest')
    expect(normalizeCatalogSort({ label: 'Price ascending', value: 'price_asc' })).toBe('price_asc')
    expect(normalizeCatalogSort({ label: 'Price descending', value: 'price_desc' })).toBe('price_desc')
    expect(normalizeCatalogSort({ label: 'Name ascending', value: 'name_asc' })).toBe('name_asc')
    expect(normalizeCatalogSort({ label: 'Name descending', value: 'name_desc' })).toBe('name_desc')
    expect(normalizeCatalogSort({ label: 'Featured', value: 'featured' })).toBe('featured')
    expect(normalizeCatalogSort({ label: 'Bestseller', value: 'bestseller' })).toBe('bestseller')

    expect(normalizeCatalogSort('latest')).toBe('newest')
    expect(normalizeCatalogSort('created_desc')).toBe('newest')
    expect(normalizeCatalogSort('new_product')).toBe('newest')
    expect(normalizeCatalogSort('[object Object]')).toBe('newest')
    expect(normalizeCatalogSort(undefined)).toBe('newest')
    expect(normalizeCatalogSort(null)).toBe('newest')
    expect(normalizeCatalogSort('')).toBe('newest')
    expect(normalizeCatalogSort({ label: 'Newest' })).toBe('newest')
    expect(normalizeCatalogSort({ label: 'Newest', value: 'latest' })).toBe('newest')
    expect(normalizeCatalogSort({ label: 'Newest', value: undefined })).toBe('newest')
    expect(normalizeCatalogSort({ label: 'Newest', value: null })).toBe('newest')
    expect(normalizeCatalogSort({ value: '[object Object]' })).toBe('newest')

    expect(catalogSortOptions).toEqual(supportedOptions)
  })

  it('renders product grid pagination and Bulgarian empty state only after an empty data array', () => {
    const page = source('app/pages/catalog.vue')

    expect(page).toContain('CatalogProductGrid v-if="products.length"')
    expect(page).toContain('CatalogPagination :meta="catalogMeta"')
    expect(page).toContain('Няма активни продукти за показване.')
    expect(page).toContain('Намерени продукти: {{ catalogMeta.total }}')
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

    const products = paginatedResource({ data: [product], links: {}, meta: { total: 1 } }).data

    expect(products).toHaveLength(1)
    expect(products[0]?.name).toBe('Logitech Universal Folio Keyboard')
    expect(page).toContain('CatalogProductGrid v-if="products.length" :products="products"')
    expect(grid).toContain('CatalogProductCard v-for="product in products"')
    expect(card).toContain('{{ product.name }}')
    expect(card).toContain(':to="`/p/${product.slug}`"')
    expect(card).toContain('Няма снимка')
  })

  it('renders the empty state only when there are no products', () => {
    const products = paginatedResource({ data: [], links: {}, meta: { total: 0 } }).data
    const page = source('app/pages/catalog.vue')

    expect(products).toEqual([])
    expect(page).toContain('UiEmptyState')
    expect(page).toContain('v-else')
  })

  it('keeps the generic catalog error state for real request failures only', () => {
    const page = source('app/pages/catalog.vue')

    expect(page).toContain('v-else-if="error"')
    expect(page).toContain('paginatedResource<ProductCard>(await productsApi.list(catalogQuery.value))')
    expect(page).toContain("key === 'sort' ? normalizeCatalogSort(value) : routeQueryValue(value)")
    expect(page).not.toContain('throw new Error')
    expect(page).not.toContain('throw createError')
  })

  it('keeps product cards linked to product detail pages with image placeholders', () => {
    const card = source('app/components/catalog/ProductCard.vue')

    expect(card).toContain(':to="`/p/${product.slug}`"')
    expect(card).toContain('Няма снимка')
    expect(card).toContain('@error="primaryImageFailed = true"')
    expect(card).toContain('watch(primaryImagePath')
    expect(card).toContain('Виж продукта')
  })

  it('keeps catalog read-only with no unfinished assistant or commerce flows', () => {
    const app = source('app/app.vue')
    const page = source('app/pages/catalog.vue')

    expect(app).toContain('const isReadOnlyStorefrontRoute = useReadOnlyStorefrontRoute()')
    expect(app).toContain('const showAiChatWidget = computed(() => !isReadOnlyStorefrontRoute.value)')
    expect(page).not.toContain('AiChatWidget')
    expect(page).not.toContain('useAiAssistant')
    expect(page).not.toContain('useCartStore')
    expect(page).not.toContain('/cart')
    expect(page).not.toContain('checkout')
    expect(page).not.toContain('/orders')
    expect(page).not.toContain('wishlist')
    expect(page).not.toContain('compare')
  })
})
