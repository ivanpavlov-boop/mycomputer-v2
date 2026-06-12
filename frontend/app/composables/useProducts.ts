import type { ApiCollection, ProductCard, ProductDetail } from '~/types/api'

export function useProducts() {
  const api = useApi()

  const list = (query: Record<string, unknown> = {}) => api.get<ApiCollection<ProductCard>>('/products', query)
  const detail = (slug: string) => api.get<{ data: ProductDetail }>(`/products/${slug}`)
  const related = (slug: string) => api.get<ApiCollection<ProductCard>>(`/products/${slug}/related`)
  const accessories = (slug: string) => api.get<ApiCollection<ProductCard>>(`/products/${slug}/accessories`)

  return { list, detail, related, accessories }
}
