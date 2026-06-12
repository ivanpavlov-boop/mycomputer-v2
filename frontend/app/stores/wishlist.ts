import { defineStore } from 'pinia'
import type { ProductCard } from '~/types/api'

interface WishlistItem {
  id: number
  product_id: number
  product: ProductCard | null
}

interface Wishlist {
  id: number
  name: string
  is_default: boolean
  items_count?: number
  items?: WishlistItem[]
}

export const useWishlistStore = defineStore('wishlist', () => {
  const wishlists = ref<Wishlist[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const defaultWishlist = computed(() => wishlists.value.find((wishlist) => wishlist.is_default) || wishlists.value[0] || null)
  const count = computed(() => wishlists.value.reduce((total, wishlist) => total + (wishlist.items_count || wishlist.items?.length || 0), 0))
  const productIds = computed(() => new Set(wishlists.value.flatMap((wishlist) => wishlist.items?.map((item) => item.product_id) || [])))

  async function load() {
    const auth = useAuthStore()
    if (!auth.isAuthenticated) return
    loading.value = true
    error.value = null
    try {
      const api = useApi()
      const response = await api.get<{ data: Wishlist[] }>('/account/wishlists')
      wishlists.value = response.data
    } catch {
      error.value = 'Не успяхме да заредим любимите продукти.'
    } finally {
      loading.value = false
    }
  }

  async function create(payload: { name: string, is_default?: boolean }) {
    const api = useApi()
    await api.post('/account/wishlists', payload)
    await load()
  }

  async function rename(id: number, name: string) {
    const api = useApi()
    await api.patch(`/account/wishlists/${id}`, { name })
    await load()
  }

  async function removeWishlist(id: number) {
    const api = useApi()
    await api.destroy(`/account/wishlists/${id}`)
    await load()
  }

  async function toggle(productId: number) {
    const auth = useAuthStore()
    if (!auth.isAuthenticated) {
      await navigateTo('/login')
      return
    }
    const api = useApi()
    await api.post('/account/wishlist/toggle', { product_id: productId })
    await load()
  }

  async function removeProduct(wishlistId: number, productId: number) {
    const api = useApi()
    await api.destroy(`/account/wishlists/${wishlistId}/items/${productId}`)
    await load()
  }

  function has(productId: number) {
    return productIds.value.has(productId)
  }

  return { wishlists, defaultWishlist, count, loading, error, load, create, rename, removeWishlist, toggle, removeProduct, has }
})
