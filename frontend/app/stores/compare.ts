import { defineStore } from 'pinia'
import type { ProductCard } from '~/types/api'

interface CompareItem {
  id: number
  product_id: number
  sort_order: number
  product: ProductCard | null
}

interface CompareList {
  id: number
  session_id?: string | null
  name?: string | null
  max_products: number
  items_count: number
  items: CompareItem[]
}

export const useCompareStore = defineStore('compare', () => {
  const products = ref<ProductCard[]>([])
  const list = ref<CompareList | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const config = useRuntimeConfig()

  const count = computed(() => products.value.length)

  function sessionHeaders() {
    const auth = useAuthStore()
    const headers: Record<string, string> = { ...auth.authHeaders() }
    if (import.meta.client) {
      const session = localStorage.getItem('compare_session_id')
      if (session) headers['X-Compare-Session'] = session
    }
    return headers
  }

  function rememberSession(nextList: CompareList) {
    if (import.meta.client && nextList.session_id) {
      localStorage.setItem('compare_session_id', nextList.session_id)
    }
  }

  function syncProducts(nextList: CompareList) {
    list.value = nextList
    products.value = nextList.items.map((item) => item.product).filter(Boolean) as ProductCard[]
    rememberSession(nextList)
  }

  async function load() {
    loading.value = true
    error.value = null
    try {
      const response = await $fetch<{ data: CompareList }>('/compare/list', {
        baseURL: config.public.apiBaseUrl,
        headers: sessionHeaders(),
      })
      syncProducts(response.data)
    } catch {
      error.value = 'Не успяхме да заредим сравнението.'
    } finally {
      loading.value = false
    }
  }

  async function add(product: ProductCard) {
    const response = await $fetch<{ data: CompareList }>('/compare/items', {
      baseURL: config.public.apiBaseUrl,
      method: 'POST',
      body: { product_id: product.id },
      headers: sessionHeaders(),
    })
    syncProducts(response.data)
  }

  async function toggle(product: ProductCard) {
    if (products.value.some((item) => item.id === product.id)) {
      await remove(product.id)
      return
    }
    await add(product)
  }

  async function remove(productId: number) {
    const response = await $fetch<{ data: CompareList }>(`/compare/items/${productId}`, {
      baseURL: config.public.apiBaseUrl,
      method: 'DELETE',
      headers: sessionHeaders(),
    })
    syncProducts(response.data)
  }

  async function clear() {
    const response = await $fetch<{ data: CompareList }>('/compare/list', {
      baseURL: config.public.apiBaseUrl,
      method: 'DELETE',
      headers: sessionHeaders(),
    })
    syncProducts(response.data)
  }

  async function mergeAfterLogin() {
    const response = await $fetch<{ data: CompareList }>('/compare/merge', {
      baseURL: config.public.apiBaseUrl,
      method: 'POST',
      headers: sessionHeaders(),
    })
    syncProducts(response.data)
  }

  function has(productId: number) {
    return products.value.some((product) => product.id === productId)
  }

  return { products, list, count, loading, error, load, add, toggle, remove, clear, mergeAfterLogin, has }
})
