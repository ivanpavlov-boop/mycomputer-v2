import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { collectionData } from '../app/utils/apiCollections'

const frontendRoot = resolve(__dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('categories page', () => {
  it('parses API collection responses with data arrays', () => {
    const parent = { id: 20, slug: 'power-cable', name: 'Power & Cable', children: [] }

    expect(collectionData({ data: [parent] })).toEqual([parent])
    expect(collectionData([])).toEqual([])
    expect(collectionData(null)).toEqual([])
  })

  it('uses the navigation category tree endpoint and renders parent and child links', () => {
    const page = source('app/pages/categories/index.vue')

    expect(page).toContain('categoryApi.navigation()')
    expect(page).toContain('collectionData<Category>(categoryResponse.value)')
    expect(page).toContain('v-for="category in categories"')
    expect(page).toContain('v-for="child in childCategories(category)"')
    expect(page).toContain(':to="`/c/${category.slug}`"')
    expect(page).toContain(':to="`/c/${child.slug}`"')
    expect(page).toContain('categoryImagePath(category)')
    expect(page).toContain('@error="markCategoryImageFailed(categoryImagePath(category)!)"')
    expect(page).toContain("category.icon || '□'")
    expect(page).toContain('Подкатегории')
    expect(page).toContain('Виж категорията')
  })

  it('renders loading error and empty states without exposing unfinished assistant or commerce flows', () => {
    const page = source('app/pages/categories/index.vue')

    expect(page).toContain('LoadingState')
    expect(page).toContain('ErrorState')
    expect(page).toContain('Няма активни категории за показване')
    expect(page).not.toContain('AiChatWidget')
    expect(page).not.toContain('useAiAssistant')
    expect(page).not.toContain('useCartStore')
    expect(page).not.toContain('checkout')
  })

  it('hides the global AI widget on the categories page', () => {
    const app = source('app/app.vue')

    expect(app).toContain('AiChatWidget v-if="showAiChatWidget"')
    expect(app).toContain('const isReadOnlyStorefrontRoute = useReadOnlyStorefrontRoute()')
    expect(app).toContain('const showAiChatWidget = computed(() => !isReadOnlyStorefrontRoute.value)')
  })
})
