import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { resourceData } from '../app/utils/apiCollections'

const frontendRoot = resolve(__dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('product detail page', () => {
  it('parses both resource-wrapped and raw product detail API responses', () => {
    const product = { id: 42, slug: 'sample-product', name: 'Sample Product' }

    expect(resourceData({ data: product })).toEqual(product)
    expect(resourceData(product)).toEqual(product)
    expect(resourceData(null)).toBeNull()
  })

  it('fetches product details from the public product slug endpoint', () => {
    const page = source('app/pages/p/[slug].vue')
    const composable = source('app/composables/useProducts.ts')

    expect(page).toContain('products.detail(slug.value)')
    expect(page).toContain('resourceData<ProductDetail>(data.value)')
    expect(composable).toContain('api.get<{ data: ProductDetail } | ProductDetail>(`/products/${slug}`)')
  })

  it('uses resolved Nuxt product components and renders key public product fields', () => {
    const page = source('app/pages/p/[slug].vue')
    const stockBadge = source('app/components/product/StockBadge.vue')
    const availabilityBadge = source('app/components/product/AvailabilityBadge.vue')
    const gallery = source('app/components/product/ProductGallery.vue')
    const price = source('app/components/product/ProductPrice.vue')
    const relatedProducts = source('app/components/product/RelatedProducts.vue')
    const accessoryProducts = source('app/components/product/AccessoryProducts.vue')

    expect(page).toContain('LayoutBreadcrumbs')
    expect(page).toContain('ProductGallery')
    expect(page).toContain('ProductPrice')
    expect(page).toContain('ProductAvailabilityBadge')
    expect(page).toContain('ProductStockBadge')
    expect(page).toContain('ProductAvailabilityInfo')
    expect(page).toContain('ProductTabs')
    expect(page).toContain('ProductRelatedProducts')
    expect(page).toContain('ProductAccessoryProducts')
    expect(page).toContain('SKU: {{ product.sku }}')
    expect(page).toContain('{{ product.brand.name }}')
    expect(page).toContain('{{ product.category.name }}')
    expect(stockBadge).toContain('ProductAvailabilityBadge')
    expect(stockBadge).toContain("in_stock: 'В наличност'")
    expect(stockBadge).toContain("limited_stock: 'Ограничена наличност'")
    expect(stockBadge).toContain("out_of_stock: 'Няма наличност'")
    expect(availabilityBadge).toContain('const displayName = computed')
    expect(availabilityBadge).toContain("out_of_stock: 'Няма наличност'")
    expect(gallery).toContain('activeVisibleImage')
    expect(gallery).toContain('@error="markImageFailed(activeVisibleImage.path)"')
    expect(gallery).toContain('Няма снимка')
    expect(price).toContain("Number(value)")
    expect(relatedProducts).toContain('CatalogProductGrid')
    expect(accessoryProducts).toContain('ProductRelatedProducts')
    expect(page).not.toContain('<Breadcrumbs')
    expect(page).not.toContain('<AvailabilityBadge')
    expect(page).not.toContain('<RelatedProducts')
  })

  it('shows a safe not-found state for missing or non-public products', () => {
    const page = source('app/pages/p/[slug].vue')

    expect(page).toContain('UiErrorState')
    expect(page).toContain('v-else-if="error || !product"')
  })

  it('keeps product detail read-only with no commerce analytics wishlist or compare flows', () => {
    const page = source('app/pages/p/[slug].vue')

    expect(page).not.toContain('useCartStore')
    expect(page).not.toContain('addToCart')
    expect(page).not.toContain('checkout')
    expect(page).not.toContain('/orders')
    expect(page).not.toContain('useWishlistStore')
    expect(page).not.toContain('useCompareStore')
    expect(page).not.toContain('useAnalytics')
    expect(page).not.toContain('supplier_products')
  })
})
