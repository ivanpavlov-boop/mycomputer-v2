import type { ApiCollection, Brand, Category, ProductCard } from '~/types/api'

export function useSearch() {
  const api = useApi()

  const run = (query: Record<string, unknown> = {}) => api.get<{
    data: {
      products: ApiCollection<ProductCard>
      categories: { data: Category[] }
      brands: { data: Brand[] }
      suggestions: string[]
      filters: Record<string, unknown>
    }
  }>('/search', query)

  return { run }
}
