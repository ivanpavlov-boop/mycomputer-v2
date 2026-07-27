import type { ApiCollection, PaymentMethod } from '~/types/api'

export function usePayments() {
  const api = useApi()

  const methods = () => api.get<ApiCollection<PaymentMethod>>('/payments/methods')

  return { methods }
}
