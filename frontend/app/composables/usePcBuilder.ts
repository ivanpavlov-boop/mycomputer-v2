import type { ApiCollection, CartResponse, PcBuild, PcBuilderMeta, PcCompatibility, PcComponentType } from '~/types/api'

const SESSION_KEY = 'mycomputer_pc_build_session'

export function usePcBuilder() {
  const config = useRuntimeConfig()
  const auth = useAuthStore()
  const baseURL = config.public.apiBaseUrl

  function sessionId() {
    if (!import.meta.client) return ''

    const existing = localStorage.getItem(SESSION_KEY)
    if (existing) return existing

    const created = crypto.randomUUID()
    localStorage.setItem(SESSION_KEY, created)

    return created
  }

  function headers() {
    return {
      ...auth.authHeaders(),
      'X-PC-Build-Session': sessionId(),
    }
  }

  async function meta() {
    return await $fetch<{ data: PcBuilderMeta }>('/pc-builder', { baseURL, headers: headers() })
  }

  async function builds() {
    return await $fetch<ApiCollection<PcBuild>>('/pc-builder/builds', { baseURL, headers: headers() })
  }

  async function build(id: number | string) {
    return await $fetch<{ data: PcBuild }>(`/pc-builder/builds/${id}`, { baseURL, headers: headers() })
  }

  async function create(payload: { name: string; description?: string }) {
    return await $fetch<{ data: PcBuild }>('/pc-builder/builds', {
      baseURL,
      method: 'POST',
      body: payload,
      headers: headers(),
    })
  }

  async function update(id: number | string, payload: Partial<Pick<PcBuild, 'name' | 'description' | 'status'>>) {
    return await $fetch<{ data: PcBuild }>(`/pc-builder/builds/${id}`, {
      baseURL,
      method: 'PATCH',
      body: payload,
      headers: headers(),
    })
  }

  async function remove(id: number | string) {
    return await $fetch<{ data: { deleted: boolean } }>(`/pc-builder/builds/${id}`, {
      baseURL,
      method: 'DELETE',
      headers: headers(),
    })
  }

  async function addItem(id: number | string, productId: number, componentType: PcComponentType, quantity = 1) {
    return await $fetch<{ data: PcBuild }>(`/pc-builder/builds/${id}/items`, {
      baseURL,
      method: 'POST',
      body: { product_id: productId, component_type: componentType, quantity },
      headers: headers(),
    })
  }

  async function removeItem(id: number | string, itemId: number) {
    return await $fetch<{ data: PcBuild }>(`/pc-builder/builds/${id}/items/${itemId}`, {
      baseURL,
      method: 'DELETE',
      headers: headers(),
    })
  }

  async function compatibility(id: number | string) {
    return await $fetch<{ data: PcCompatibility }>(`/pc-builder/builds/${id}/compatibility`, { baseURL, headers: headers() })
  }

  async function recommendations(id: number | string) {
    return await $fetch<{ data: { missing_required_components: PcComponentType[]; missing_components: PcComponentType[]; suggested_products: unknown[]; presets: unknown[] } }>(`/pc-builder/builds/${id}/recommendations`, {
      baseURL,
      headers: headers(),
    })
  }

  async function addToCart(id: number | string) {
    return await $fetch<{ data: CartResponse }>(`/pc-builder/builds/${id}/add-to-cart`, {
      baseURL,
      method: 'POST',
      headers: headers(),
    })
  }

  async function aiGenerate(query: string) {
    return await $fetch<{ data: PcBuild }>('/pc-builder/ai-generate', {
      baseURL,
      method: 'POST',
      body: { query },
      headers: headers(),
    })
  }

  return { meta, builds, build, create, update, remove, addItem, removeItem, compatibility, recommendations, addToCart, aiGenerate }
}
