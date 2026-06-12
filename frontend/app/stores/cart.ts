import { defineStore } from 'pinia'
import type { CartResponse, ProductCard } from '~/types/api'

export interface CartLine {
  product: ProductCard
  quantity: number
}

export const useCartStore = defineStore('cart', () => {
  const lines = ref<CartLine[]>([])
  const backendCart = ref<CartResponse | null>(null)
  const backendAvailable = ref(false)

  const count = computed(() => backendCart.value?.items_count ?? lines.value.reduce((sum, line) => sum + line.quantity, 0))
  const subtotal = computed(() => Number(backendCart.value?.subtotal ?? lines.value.reduce((sum, line) => sum + Number(line.product.promo_price || line.product.price) * line.quantity, 0)))
  const backendItems = computed(() => backendCart.value?.items || [])

  async function sync() {
    try {
      const api = useCartApi()
      const response = await api.get() as { data: CartResponse }
      backendCart.value = response.data
      backendAvailable.value = true
    } catch {
      backendAvailable.value = false
    }
  }

  async function add(product: ProductCard, quantity = 1) {
    try {
      const api = useCartApi()
      const response = await api.add(product.id, quantity) as { data: CartResponse }
      backendCart.value = response.data
      backendAvailable.value = true
      await useAnalytics().addToCart({ product_id: product.id, sku: product.sku, quantity, value: Number(product.promo_price || product.price) * quantity })
      return
    } catch {
      backendAvailable.value = false
    }

    const existing = lines.value.find((line) => line.product.id === product.id)
    if (existing) {
      existing.quantity += quantity
      return
    }
    lines.value.push({ product, quantity })
    await useAnalytics().addToCart({ product_id: product.id, sku: product.sku, quantity, value: Number(product.promo_price || product.price) * quantity })
  }

  async function remove(productId: number, cartItemId?: number) {
    if (cartItemId) {
      try {
        const api = useCartApi()
        const response = await api.remove(cartItemId) as { data: CartResponse }
        backendCart.value = response.data
        backendAvailable.value = true
        await useAnalytics().removeFromCart({ product_id: productId, cart_item_id: cartItemId })
        return
      } catch {
        backendAvailable.value = false
      }
    }
    lines.value = lines.value.filter((line) => line.product.id !== productId)
    await useAnalytics().removeFromCart({ product_id: productId })
  }

  async function update(productId: number, quantity: number, cartItemId?: number) {
    if (cartItemId) {
      try {
        const api = useCartApi()
        const response = await api.update(cartItemId, quantity) as { data: CartResponse }
        backendCart.value = response.data
        backendAvailable.value = true
        return
      } catch {
        backendAvailable.value = false
      }
    }
    const line = lines.value.find((line) => line.product.id === productId)
    if (!line) return
    line.quantity = Math.max(1, quantity)
  }

  async function clear() {
    try {
      const api = useCartApi()
      const response = await api.clear() as { data: CartResponse }
      backendCart.value = response.data
      backendAvailable.value = true
    } catch {
      backendAvailable.value = false
    }
    lines.value = []
  }

  return { lines, backendCart, backendItems, backendAvailable, count, subtotal, sync, add, remove, update, clear }
})
