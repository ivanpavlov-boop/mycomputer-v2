export function useCartApi() {
  const config = useRuntimeConfig()
  const baseURL = config.public.apiBaseUrl
  const auth = useAuthStore()

  const sessionId = useState<string | null>('cart-session-id', () => null)
  const headers = computed(() => sessionId.value ? { 'X-Cart-Session': sessionId.value } : {})

  async function request<T>(path: string, options: Record<string, unknown> = {}) {
    const response = await $fetch<T>(path, {
      baseURL,
      ...options,
      headers: {
        ...auth.authHeaders(),
        ...(options.headers as Record<string, string> || {}),
        ...headers.value,
      },
    })

    const maybeSession = (response as any)?.data?.cart_session_id
    if (maybeSession) sessionId.value = maybeSession

    return response
  }

  return {
    sessionId,
    request,
    get: () => request('/cart'),
    add: (productId: number, quantity: number) => request('/cart/items', { method: 'POST', body: { product_id: productId, quantity } }),
    addBundle: (bundleId: number, quantity: number, selectedItems: Array<Record<string, unknown>> = []) => request('/cart/bundles', {
      method: 'POST',
      body: { bundle_id: bundleId, quantity, selected_items: selectedItems },
    }),
    updateBundle: (bundleItemId: number, quantity: number, selectedItems: Array<Record<string, unknown>> = []) => request(`/cart/bundles/${bundleItemId}`, {
      method: 'PATCH',
      body: { quantity, selected_items: selectedItems },
    }),
    removeBundle: (bundleItemId: number) => request(`/cart/bundles/${bundleItemId}`, { method: 'DELETE' }),
    applyCoupon: (code: string) => request('/cart/coupon', { method: 'POST', body: { code } }),
    removeCoupon: () => request('/cart/coupon', { method: 'DELETE' }),
    email: (email: string) => request('/cart/email', { method: 'POST', body: { email } }),
    recover: (token: string) => request(`/cart/recover/${token}`, { method: 'POST' }),
    update: (itemId: number, quantity: number) => request(`/cart/items/${itemId}`, { method: 'PATCH', body: { quantity } }),
    remove: (itemId: number) => request(`/cart/items/${itemId}`, { method: 'DELETE' }),
    clear: () => request('/cart', { method: 'DELETE' }),
    checkout: (body: Record<string, unknown>) => request('/checkout', { method: 'POST', body }),
  }
}
