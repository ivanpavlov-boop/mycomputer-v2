import type { ApiCollection, ShippingCalculation, ShippingMethod, ShippingOffice, ShippingProvider } from '~/types/api'

export function useShipping() {
  const api = useApi()
  const cartApi = useCartApi()

  const providers = () => api.get<ApiCollection<ShippingProvider>>('/shipping/providers')
  const methods = () => api.get<ApiCollection<ShippingMethod>>('/shipping/methods')
  const offices = (query: Record<string, unknown> = {}) => api.get<ApiCollection<ShippingOffice>>('/shipping/offices', query)

  async function calculatePrice(body: Record<string, unknown>) {
    const config = useRuntimeConfig()
    return await $fetch<{ data: ShippingCalculation }>('/shipping/calculate', {
      baseURL: config.public.apiBaseUrl,
      method: 'POST',
      body,
      headers: cartApi.sessionId.value ? { 'X-Cart-Session': cartApi.sessionId.value } : {},
    })
  }

  return { providers, methods, offices, calculatePrice }
}
