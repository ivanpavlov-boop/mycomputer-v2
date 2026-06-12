import type { ApiCollection, ProductBundle } from '~/types/api'

export function useBundles() {
  const api = useApi()

  return {
    list: (query?: Record<string, unknown>) => api.get<ApiCollection<ProductBundle>>('/bundles', query),
    show: (slug: string) => api.get<{ data: ProductBundle }>(`/bundles/${slug}`),
    forProduct: (slug: string) => api.get<ApiCollection<ProductBundle>>(`/products/${slug}/bundles`),
  }
}
