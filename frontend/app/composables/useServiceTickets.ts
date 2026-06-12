import type { ServiceTicket } from '~/types/api'

export function useServiceTickets() {
  const api = useApi()

  const list = () => api.get<{ data: ServiceTicket[]; meta?: Record<string, unknown> }>('/account/service')
  const show = (id: number | string) => api.get<{ data: ServiceTicket }>(`/account/service/${id}`)
  const create = (payload: Record<string, unknown>) => api.post<{ data: ServiceTicket }>('/account/service', payload)
  const message = (id: number | string, payload: { message: string }) => api.post<{ data: ServiceTicket }>(`/account/service/${id}/messages`, payload)
  const upload = (id: number | string, form: FormData) => $fetch<{ data: ServiceTicket }>(`/account/service/${id}/files`, {
    baseURL: api.baseURL,
    method: 'POST',
    body: form,
    headers: useAuthStore().authHeaders(),
  })
  const close = (id: number | string) => api.post<{ data: ServiceTicket }>(`/account/service/${id}/close`)
  const orderProducts = (orderId: number | string) => api.get<{ data: Array<Record<string, unknown>> }>(`/account/service/order-products/${orderId}`)

  return { list, show, create, message, upload, close, orderProducts }
}
