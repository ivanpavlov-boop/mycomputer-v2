import type { ApiCollection, PaymentMethod, PaymentTransaction } from '~/types/api'

export function usePayments() {
  const api = useApi()

  const methods = () => api.get<ApiCollection<PaymentMethod>>('/payments/methods')
  const initiate = (orderId: number, paymentMethodCode: string) => api.post<{ data: PaymentTransaction }>('/payments/initiate', {
    order_id: orderId,
    payment_method_code: paymentMethodCode,
  })

  return { methods, initiate }
}
