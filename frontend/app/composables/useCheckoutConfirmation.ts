import type { ApiDataResponse, CheckoutConfirmation } from '~/types/api'
import { normalizeApiError } from '~/utils/apiError'
import { normalizeStorefrontLocale } from '~/utils/locales'

export function useCheckoutConfirmation() {
  const config = useRuntimeConfig()
  const { locale } = useI18n()
  const baseURL = import.meta.server
    ? String(config.apiServerBaseUrl || config.public.apiBaseUrl)
    : config.public.apiBaseUrl

  async function get(): Promise<ApiDataResponse<CheckoutConfirmation>> {
    const incomingHeaders = import.meta.server
      ? useRequestHeaders(['cookie'])
      : {}

    try {
      return await $fetch<ApiDataResponse<CheckoutConfirmation>>('/checkout/confirmation', {
        baseURL,
        credentials: 'include',
        headers: {
          ...incomingHeaders,
          'X-Locale': normalizeStorefrontLocale(locale.value),
        },
      })
    } catch (error) {
      throw normalizeApiError(error)
    }
  }

  return { get }
}
