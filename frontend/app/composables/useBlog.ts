import type { ApiCollection, BlogCategory, BlogPost, BlogPostDetail, BlogTag, SeoPage } from '~/types/api'

export function useBlog() {
  const api = useApi()

  const posts = (query?: Record<string, unknown>) => api.get<ApiCollection<BlogPost>>('/blog', query)
  const post = (slug: string) => api.get<{ data: BlogPostDetail }>(`/blog/${slug}`)
  const categories = () => api.get<ApiCollection<BlogCategory>>('/blog/categories')
  const category = (slug: string) => api.get<{ data: BlogCategory }>(`/blog/categories/${slug}`)
  const categoryPosts = (slug: string, query?: Record<string, unknown>) => api.get<ApiCollection<BlogPost>>(`/blog/categories/${slug}/posts`, query)
  const tags = () => api.get<ApiCollection<BlogTag>>('/blog/tags')
  const tagPosts = (slug: string, query?: Record<string, unknown>) => api.get<ApiCollection<BlogPost>>(`/blog/tags/${slug}`, query)
  const seoPage = (slug: string) => api.get<{ data: SeoPage }>(`/seo-pages/${slug}`)

  return { posts, post, categories, category, categoryPosts, tags, tagPosts, seoPage }
}
