import type { ApiDataResponse, CartResponse, CheckoutResponse } from '~/types/api'
import { invalidCartSessionResponseError, normalizeApiError } from '~/utils/apiError'
import { normalizeCartSessionId } from '~/utils/cartSession'

type SessionResponsePolicy = 'required' | 'if-present'

interface CartRequestPolicy {
  sessionResponse?: SessionResponsePolicy
  retryInvalidSessionGet?: boolean
}

function responseSession(response: unknown): { present: boolean, value: unknown } {
  if (typeof response !== 'object' || response === null || !('data' in response)) {
    return { present: false, value: null }
  }

  const data = response.data

  if (typeof data !== 'object' || data === null || !('cart_session_id' in data)) {
    return { present: false, value: null }
  }

  return { present: true, value: data.cart_session_id }
}

export function useCartApi() {
  const config = useRuntimeConfig()
  const baseURL = import.meta.server
    ? String(config.apiServerBaseUrl || config.public.apiBaseUrl)
    : config.public.apiBaseUrl
  const auth = useAuthStore()
  const cartSession = useCartSession()

  async function request<T>(
    path: string,
    options: Record<string, unknown> = {},
    policy: CartRequestPolicy = {},
    hasRetried = false,
  ): Promise<T> {
    const callerHeaders = typeof options.headers === 'object' && options.headers !== null
      ? options.headers as Record<string, string>
      : {}
    const sentSession = normalizeCartSessionId(cartSession.sessionId.value)

    if (cartSession.sessionId.value !== null && sentSession === null) {
      cartSession.clear()
    }

    try {
      const response = await $fetch<T>(path, {
        baseURL,
        ...options,
        headers: {
          ...auth.authHeaders(),
          ...callerHeaders,
          ...(sentSession ? { 'X-Cart-Session': sentSession } : {}),
        },
      })
      const returned = responseSession(response)

      if (returned.present) {
        const normalized = normalizeCartSessionId(returned.value)

        if (normalized === null) {
          throw invalidCartSessionResponseError()
        }

        cartSession.persist(normalized)
      } else if (policy.sessionResponse === 'required') {
        throw invalidCartSessionResponseError()
      }

      return response
    } catch (error) {
      const normalized = normalizeApiError(error)
      const canRetry = policy.retryInvalidSessionGet === true
        && normalized.code === 'invalid_cart_session'
        && sentSession !== null
        && !hasRetried

      if (canRetry) {
        cartSession.clear()

        return request<T>(path, options, policy, true)
      }

      throw normalized
    }
  }

  const cartRequest = (
    path: string,
    options: Record<string, unknown> = {},
    retryInvalidSessionGet = false,
  ) => request<ApiDataResponse<CartResponse>>(path, options, {
    sessionResponse: 'required',
    retryInvalidSessionGet,
  })

  return {
    sessionId: cartSession.sessionId,
    request,
    get: () => cartRequest('/cart', {}, true),
    add: (productId: number, quantity: number) => cartRequest('/cart/items', {
      method: 'POST',
      body: { product_id: productId, quantity },
    }),
    addBundle: (bundleId: number, quantity: number, selectedItems: Array<Record<string, unknown>> = []) => cartRequest('/cart/bundles', {
      method: 'POST',
      body: { bundle_id: bundleId, quantity, selected_items: selectedItems },
    }),
    updateBundle: (bundleItemId: number, quantity: number, selectedItems: Array<Record<string, unknown>> = []) => cartRequest(`/cart/bundles/${bundleItemId}`, {
      method: 'PATCH',
      body: { quantity, selected_items: selectedItems },
    }),
    removeBundle: (bundleItemId: number) => cartRequest(`/cart/bundles/${bundleItemId}`, { method: 'DELETE' }),
    applyCoupon: (code: string) => cartRequest('/cart/coupon', { method: 'POST', body: { code } }),
    removeCoupon: () => cartRequest('/cart/coupon', { method: 'DELETE' }),
    email: (email: string) => cartRequest('/cart/email', { method: 'POST', body: { email } }),
    recover: (token: string) => cartRequest(`/cart/recover/${token}`, { method: 'POST' }),
    update: (itemId: number, quantity: number) => cartRequest(`/cart/items/${itemId}`, {
      method: 'PATCH',
      body: { quantity },
    }),
    remove: (itemId: number) => cartRequest(`/cart/items/${itemId}`, { method: 'DELETE' }),
    clear: () => cartRequest('/cart', { method: 'DELETE' }),
    checkout: (body: Record<string, unknown>, idempotencyKey: string) => request<ApiDataResponse<CheckoutResponse>>('/checkout', {
      method: 'POST',
      body,
      credentials: 'include',
      headers: {
        'Idempotency-Key': idempotencyKey,
      },
    }, {
      sessionResponse: 'if-present',
    }),
  }
}
