import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { collectionData } from '../app/utils/apiCollections'
import { categoryTreeSummary, normalizeCategoryTree } from '../app/utils/categoryTree'
import type { Category } from '../app/types/api'

const frontendRoot = resolve(__dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

function category(id: number, slug: string, children: Category[] = []): Category {
  return {
    id,
    slug,
    name: slug,
    children,
  }
}

describe('categories page', () => {
  it('parses API collection responses with data arrays', () => {
    const parent = { id: 20, slug: 'power-cable', name: 'Power & Cable', children: [] }

    expect(collectionData({ data: [parent] })).toEqual([parent])
    expect(collectionData([])).toEqual([])
    expect(collectionData(null)).toEqual([])
  })

  it('uses the navigation tree and the recursive directory-prefixed component', () => {
    const page = source('app/pages/categories/index.vue')
    const tree = source('app/components/catalog/CategoryTree.vue')

    expect(page).toContain('categoryApi.navigation()')
    expect(page).toContain('collectionData<Category>(categoryResponse.value)')
    expect(page).toContain('v-for="category in categories"')
    expect(page).toContain(':to="localePath(`/c/${category.slug}`)"')
    expect(page).toContain('<CatalogCategoryTree')
    expect(page).toContain(':categories="category.children"')
    expect(page).toContain('categoryImagePath(category)')
    expect(page).toContain('@error="markCategoryImageFailed(categoryImagePath(category)!)"')
    expect(tree).toContain('<ul v-if="visibleCategories.length"')
    expect(tree).toContain('<li')
    expect(tree).toContain('v-for="category in visibleCategories"')
    expect(tree).toContain(':key="category.id"')
    expect(tree).toContain(':to="localePath(`/c/${category.slug}`)"')
    expect(tree).toContain(':depth="depth + 1"')
    expect(tree).toContain(':ancestor-ids="[...ancestorIds, category.id]"')
    expect(tree).toContain('border-l')
    expect(tree).toContain('focus-visible:ring-2')
    expect(tree).not.toContain('v-html')
    expect(page).toContain('Подкатегории')
    expect(page).toContain('Виж категорията')
  })

  it('renders third- and fourth-level fixtures once in API order', () => {
    const fourth = category(4, 'fourth')
    const third = category(3, 'third', [fourth])
    const second = category(2, 'second', [third])
    const root = category(1, 'root', [second])
    const otherRoot = category(5, 'other-root')

    const normalized = normalizeCategoryTree([root, otherRoot])
    const summary = categoryTreeSummary(normalized)

    expect(normalized.map(item => item.id)).toEqual([1, 5])
    expect(normalized[0]?.children?.[0]?.children?.[0]?.children?.[0]?.id).toBe(4)
    expect(summary).toEqual({
      rootCategoryCount: 2,
      totalCategoryCount: 5,
      maximumVisibleDepth: 4,
    })
  })

  it('drops duplicate and cyclic nodes without hiding valid siblings', () => {
    const root = category(1, 'root')
    const child = category(2, 'child')
    const sibling = category(3, 'sibling')

    root.children = [child, sibling]
    child.children = [root]

    const duplicateRoot = category(1, 'duplicate-root')
    const normalized = normalizeCategoryTree([root, duplicateRoot])

    expect(normalized).toHaveLength(1)
    expect(normalized[0]?.children?.map(item => item.id)).toEqual([2, 3])
    expect(normalized[0]?.children?.[0]?.children).toEqual([])
    expect(categoryTreeSummary(normalized).totalCategoryCount).toBe(3)
  })

  it('calculates an empty summary without staging-specific counts', () => {
    expect(categoryTreeSummary(normalizeCategoryTree([]))).toEqual({
      rootCategoryCount: 0,
      totalCategoryCount: 0,
      maximumVisibleDepth: 0,
    })
  })

  it('renders prefixed loading, error, empty and breadcrumb components', () => {
    const page = source('app/pages/categories/index.vue')

    expect(page).toContain('<LayoutBreadcrumbs')
    expect(page).toContain('<UiLoadingState')
    expect(page).toContain('<UiErrorState')
    expect(page).toContain('<UiEmptyState')
    expect(page).not.toContain('<Breadcrumbs')
    expect(page).not.toContain('<LoadingState')
    expect(page).not.toContain('<ErrorState')
    expect(page).not.toContain('<EmptyState')
    expect(page).toContain('Няма активни категории за показване')
    expect(page).not.toContain('AiChatWidget')
    expect(page).not.toContain('useAiAssistant')
    expect(page).not.toContain('useCartStore')
    expect(page).not.toContain('checkout')
  })

  it('keeps category tree rendering SSR-safe and escaped by Vue', () => {
    const page = source('app/pages/categories/index.vue')
    const tree = source('app/components/catalog/CategoryTree.vue')

    expect(page).toContain('normalizeCategoryTree')
    expect(page).toContain('categoryTreeSummary')
    expect(page).not.toContain('window.')
    expect(page).not.toContain('document.')
    expect(tree).not.toContain('window.')
    expect(tree).not.toContain('document.')
    expect(tree).not.toContain('v-html')
    expect(tree).not.toContain('innerHTML')
  })

  it('hides the global AI widget on the categories page', () => {
    const app = source('app/app.vue')

    expect(app).toContain('AiChatWidget v-if="showAiChatWidget"')
    expect(app).toContain('const isReadOnlyStorefrontRoute = useReadOnlyStorefrontRoute()')
    expect(app).toContain('const showAiChatWidget = computed(() => !isReadOnlyStorefrontRoute.value)')
  })
})
