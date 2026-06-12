export function useB2B() {
  const api = useApi()
  const cartApi = useCartApi()

  return {
    status: () => api.get('/b2b/status'),
    apply: (payload: Record<string, unknown>) => api.post('/b2b/apply', payload),
    company: () => api.get('/account/b2b/company'),
    updateCompany: (payload: Record<string, unknown>) => api.patch('/account/b2b/company', payload),
    users: () => api.get('/account/b2b/users'),
    invite: (payload: Record<string, unknown>) => api.post('/account/b2b/users/invite', payload),
    quotes: (query?: Record<string, unknown>) => api.get('/account/quotes', query),
    quote: (id: string | number) => api.get(`/account/quotes/${id}`),
    createQuote: (payload: Record<string, unknown>) => api.post('/account/quotes', payload),
    updateQuote: (id: string | number, payload: Record<string, unknown>) => api.patch(`/account/quotes/${id}`, payload),
    submitQuote: (id: string | number) => api.post(`/account/quotes/${id}/submit`),
    acceptQuote: (id: string | number) => api.post(`/account/quotes/${id}/accept`),
    sendMessage: (id: string | number, message: string) => api.post(`/account/quotes/${id}/messages`, { message }),
    requestCartQuote: (payload: Record<string, unknown>) => cartApi.request('/cart/request-quote', { method: 'POST', body: payload }),
    requestProductQuote: (slug: string, payload: Record<string, unknown>) => api.post(`/products/${slug}/request-quote`, payload),
  }
}
