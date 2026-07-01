import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '..')

function source(path: string) {
  return readFileSync(resolve(root, path), 'utf8')
}

describe('read-only public catalog foundation', () => {
  it('keeps product cards read-only and uses an image fallback', () => {
    const card = source('app/components/catalog/ProductCard.vue')

    expect(card).toContain('Виж продукта')
    expect(card).toContain('Няма снимка')
    expect(card).not.toContain('useCartStore')
    expect(card).not.toContain('useWishlistStore')
    expect(card).not.toContain('useCompareStore')
    expect(card).not.toContain('addToCart')
    expect(card).not.toContain('wishlist.toggle')
    expect(card).not.toContain('compare.toggle')
  })

  it('keeps product detail read-only with no purchase, quote, review, or analytics actions', () => {
    const detail = source('app/pages/p/[slug].vue')

    expect(detail).toContain('ProductGallery')
    expect(detail).toContain('ProductPrice')
    expect(detail).not.toContain('useCartStore')
    expect(detail).not.toContain('useWishlistStore')
    expect(detail).not.toContain('useCompareStore')
    expect(detail).not.toContain('useAnalytics')
    expect(detail).not.toContain('RequestQuoteButton')
    expect(detail).not.toContain('ProductReviewList')
    expect(detail).not.toContain('ProductReviewForm')
  })

  it('hides shell commerce and account actions on read-only catalog routes', () => {
    const header = source('app/components/layout/AppHeader.vue')
    const mobileMenu = source('app/components/layout/MobileMenu.vue')
    const layout = source('app/layouts/default.vue')
    const readOnlyRoute = source('app/composables/useReadOnlyStorefrontRoute.ts')

    expect(readOnlyRoute).toContain("route.path === '/catalog'")
    expect(readOnlyRoute).toContain("route.path === '/categories'")
    expect(readOnlyRoute).toContain("route.path.startsWith('/c/')")
    expect(readOnlyRoute).toContain("route.path.startsWith('/p/')")
    expect(header).toContain('const isReadOnlyStorefrontRoute = useReadOnlyStorefrontRoute()')
    expect(header).toContain('CartButton v-if="!isReadOnlyStorefrontRoute"')
    expect(header).toContain('v-if="!isReadOnlyStorefrontRoute" to="/compare"')
    expect(header).toContain('v-else-if="!isReadOnlyStorefrontRoute"')
    expect(mobileMenu).toContain('v-if="!isReadOnlyStorefrontRoute" to="/compare"')
    expect(mobileMenu).toContain('v-if="!isReadOnlyStorefrontRoute" to="/cart"')
    expect(layout).toContain('CartDrawer v-if="!isReadOnlyStorefrontRoute"')
  })

  it('adds catalog and category entry pages that read from catalog APIs only', () => {
    const catalog = source('app/pages/catalog.vue')
    const categories = source('app/pages/categories/index.vue')

    expect(catalog).toContain('useProducts')
    expect(catalog).toContain('ProductGrid')
    expect(catalog).toContain('SortSelect')
    expect(categories).toContain('useCategories')
    expect(categories).toContain('categoryApi.navigation()')
    expect(categories).toContain('v-for="category in categories"')
    expect(categories).toContain('v-for="child in childCategories(category)"')

    expect(catalog).not.toContain('supplier_products')
    expect(categories).not.toContain('supplier_products')
  })
})
