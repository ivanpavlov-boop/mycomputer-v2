import type { ApiCollection, Category, CategoryFilters, ProductCard } from '~/types/api'

export function useCategories() {
  const api = useApi()

  const navigation = () => api.get<ApiCollection<Category>>('/navigation/categories')
  const list = (query: Record<string, unknown> = {}) => api.get<ApiCollection<Category>>('/categories', query)
  const detail = (slug: string) => api.get<{ data: Category }>(`/categories/${slug}`)
  const products = (slug: string, query: Record<string, unknown> = {}) => api.get<ApiCollection<ProductCard>>(`/categories/${slug}/products`, query)
  const filters = (slug: string) => api.get<{ data: CategoryFilters }>(`/filters/categories/${slug}`)

  return { navigation, list, detail, products, filters }
}
