import type { ApiCollection, Brand, ProductCard } from '~/types/api'

export function useBrands() {
  const api = useApi()

  const list = (query: Record<string, unknown> = {}) => api.get<ApiCollection<Brand>>('/brands', query)
  const detail = (slug: string) => api.get<{ data: Brand }>(`/brands/${slug}`)
  const products = (slug: string, query: Record<string, unknown> = {}) => api.get<ApiCollection<ProductCard>>(`/brands/${slug}/products`, query)

  return { list, detail, products }
}
