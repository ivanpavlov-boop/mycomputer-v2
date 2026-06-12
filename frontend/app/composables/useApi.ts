export function useApi() {
  const config = useRuntimeConfig()
  const baseURL = config.public.apiBaseUrl
  const auth = useAuthStore()

  async function get<T>(path: string, query?: Record<string, unknown>) {
    return await $fetch<T>(path, {
      baseURL,
      query,
      headers: auth.authHeaders(),
    })
  }

  async function post<T>(path: string, body?: Record<string, unknown>) {
    return await $fetch<T>(path, {
      baseURL,
      method: 'POST',
      body,
      headers: auth.authHeaders(),
    })
  }

  async function patch<T>(path: string, body?: Record<string, unknown>) {
    return await $fetch<T>(path, {
      baseURL,
      method: 'PATCH',
      body,
      headers: auth.authHeaders(),
    })
  }

  async function destroy<T>(path: string) {
    return await $fetch<T>(path, {
      baseURL,
      method: 'DELETE',
      headers: auth.authHeaders(),
    })
  }

  return { get, post, patch, destroy, baseURL }
}
