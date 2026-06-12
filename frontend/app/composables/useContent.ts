import type { ContentPage, ContentTemplate } from '~/types/api'

export function useContent() {
  const api = useApi()

  const homepage = () => api.get<{ data: ContentPage }>('/content/homepage')
  const page = (slug: string) => api.get<{ data: ContentPage }>(`/content/pages/${slug}`)
  const templates = () => api.get<{ data: ContentTemplate[] }>('/content/templates')
  const blockTypes = () => api.get<{ data: Record<string, string[]> }>('/content/block-types')

  return { homepage, page, templates, blockTypes }
}
