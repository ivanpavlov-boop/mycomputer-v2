import type { ApiDataResponse, PaymentAttemptResponse } from '~/types/api'
import { normalizeApiError } from '~/utils/apiError'
import {
  generatePaymentAttemptIdempotencyKey,
  shouldRetainPaymentAttemptKey,
} from '~/utils/paymentAttemptIdempotency'

export function usePaymentAttempts() {
  const config = useRuntimeConfig()
  const auth = useAuthStore()
  const activeKey = ref<string | null>(null)

  function keyForAttempt(): string {
    activeKey.value ??= generatePaymentAttemptIdempotencyKey()

    return activeKey.value
  }

  async function request(path: string): Promise<ApiDataResponse<PaymentAttemptResponse>> {
    const key = keyForAttempt()

    try {
      const response = await $fetch<ApiDataResponse<PaymentAttemptResponse>>(path, {
        baseURL: config.public.apiBaseUrl,
        method: 'POST',
        body: {},
        credentials: 'include',
        headers: {
          ...auth.authHeaders(),
          'Idempotency-Key': key,
        },
      })

      activeKey.value = null

      return response
    } catch (error) {
      const normalized = normalizeApiError(error)

      if (!shouldRetainPaymentAttemptKey(normalized)) {
        activeKey.value = null
      }

      throw normalized
    }
  }

  return {
    activeKey: readonly(activeKey),
    retryAccountOrder: (orderId: number) => request(
      `/account/orders/${encodeURIComponent(String(orderId))}/payment-attempts`,
    ),
    retryGuestOrder: () => request('/checkout/payment-attempts'),
  }
}
