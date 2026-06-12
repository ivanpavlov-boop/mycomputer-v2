type AnalyticsSource = 'ga4' | 'meta' | 'merchant' | 'internal'

const MARKETING_SESSION_KEY = 'mycomputer_marketing_session'

export function useAnalytics() {
  const config = useRuntimeConfig()
  const auth = useAuthStore()
  const baseURL = config.public.apiBaseUrl

  function sessionId() {
    if (!import.meta.client) return ''

    const existing = localStorage.getItem(MARKETING_SESSION_KEY)
    if (existing) return existing

    const created = crypto.randomUUID()
    localStorage.setItem(MARKETING_SESSION_KEY, created)

    return created
  }

  function hasConsent(kind: 'analytics' | 'marketing' = 'analytics') {
    if (!import.meta.client) return false

    const raw = localStorage.getItem('mycomputer_consent')
    if (!raw) return true

    try {
      const consent = JSON.parse(raw)
      return Boolean(consent[kind])
    } catch {
      return false
    }
  }

  async function track(eventName: string, payload: Record<string, unknown> = {}, source: AnalyticsSource = 'internal') {
    if (!hasConsent(source === 'meta' ? 'marketing' : 'analytics')) return

    try {
      await $fetch('/marketing/events', {
        baseURL,
        method: 'POST',
        body: { event_name: eventName, source, payload },
        headers: {
          ...auth.authHeaders(),
          'X-Marketing-Session': sessionId(),
        },
      })
    } catch {
      // Tracking must never interrupt storefront UX.
    }
  }

  const pageView = (payload: Record<string, unknown> = {}) => track('page_view', payload, 'ga4')
  const viewContent = (payload: Record<string, unknown>) => track('ViewContent', payload, 'meta')
  const viewItem = (payload: Record<string, unknown>) => track('view_item', payload, 'ga4')
  const search = (query: string, payload: Record<string, unknown> = {}) => track('search', { query, ...payload }, 'ga4')
  const addToCart = (payload: Record<string, unknown>) => track('add_to_cart', payload, 'ga4')
  const removeFromCart = (payload: Record<string, unknown>) => track('remove_from_cart', payload, 'ga4')
  const beginCheckout = (payload: Record<string, unknown> = {}) => track('begin_checkout', payload, 'ga4')
  const addPaymentInfo = (payload: Record<string, unknown> = {}) => track('add_payment_info', payload, 'ga4')
  const purchase = (payload: Record<string, unknown>) => track('purchase', payload, 'ga4')
  const login = () => track('login', {}, 'ga4')
  const register = () => track('sign_up', {}, 'ga4')
  const builderStart = (payload: Record<string, unknown> = {}) => track('BuilderStart', payload, 'internal')
  const builderSave = (payload: Record<string, unknown> = {}) => track('BuilderSave', payload, 'internal')
  const builderComplete = (payload: Record<string, unknown> = {}) => track('BuilderComplete', payload, 'internal')
  const aiConversationStart = (payload: Record<string, unknown> = {}) => track('AiConversationStart', payload, 'internal')
  const aiRecommendationAccepted = (payload: Record<string, unknown> = {}) => track('AiRecommendationAccepted', payload, 'internal')
  const productOutOfStockView = (payload: Record<string, unknown>) => track('product_out_of_stock_view', payload, 'internal')
  const productPreorderView = (payload: Record<string, unknown>) => track('product_preorder_view', payload, 'internal')
  const productIncomingView = (payload: Record<string, unknown>) => track('product_incoming_view', payload, 'internal')
  const stockAlertSignup = (payload: Record<string, unknown>) => track('stock_alert_signup', payload, 'internal')
  const availabilityStatusClick = (payload: Record<string, unknown>) => track('availability_status_click', payload, 'internal')

  return {
    track,
    pageView,
    viewContent,
    viewItem,
    search,
    addToCart,
    removeFromCart,
    beginCheckout,
    addPaymentInfo,
    purchase,
    login,
    register,
    builderStart,
    builderSave,
    builderComplete,
    aiConversationStart,
    aiRecommendationAccepted,
    productOutOfStockView,
    productPreorderView,
    productIncomingView,
    stockAlertSignup,
    availabilityStatusClick,
  }
}
