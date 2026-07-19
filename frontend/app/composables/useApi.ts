import { normalizeStorefrontLocale } from '~/utils/locales'

export function useApi() {
  const config = useRuntimeConfig()
  const baseURL = import.meta.server
    ? String(config.apiServerBaseUrl || config.public.apiBaseUrl)
    : config.public.apiBaseUrl
  const auth = useAuthStore()
  const { locale } = useI18n()

  function requestHeaders() {
    return {
      ...auth.authHeaders(),
      'X-Locale': normalizeStorefrontLocale(locale.value),
    }
  }

  async function get<T>(path: string, query?: Record<string, unknown>) {
    return await $fetch<T>(path, {
      baseURL,
      query,
      headers: requestHeaders(),
    })
  }

  async function post<T>(path: string, body?: Record<string, unknown>) {
    return await $fetch<T>(path, {
      baseURL,
      method: 'POST',
      body,
      headers: requestHeaders(),
    })
  }

  async function patch<T>(path: string, body?: Record<string, unknown>) {
    return await $fetch<T>(path, {
      baseURL,
      method: 'PATCH',
      body,
      headers: requestHeaders(),
    })
  }

  async function destroy<T>(path: string) {
    return await $fetch<T>(path, {
      baseURL,
      method: 'DELETE',
      headers: requestHeaders(),
    })
  }

  return { get, post, patch, destroy, baseURL }
}
