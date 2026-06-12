import { defineStore } from 'pinia'

interface AuthUser {
  id: number
  first_name: string
  last_name: string
  name: string
  email: string
  phone?: string | null
  company_name?: string | null
  vat_number?: string | null
  roles: string[]
  profile?: Record<string, unknown> | null
}

export const useAuthStore = defineStore('auth', () => {
  const config = useRuntimeConfig()
  const token = ref<string | null>(null)
  const user = ref<AuthUser | null>(null)
  const loaded = ref(false)

  const isAuthenticated = computed(() => Boolean(token.value && user.value))

  function authHeaders() {
    return token.value ? { Authorization: `Bearer ${token.value}` } : {}
  }

  function setSession(nextToken: string, nextUser: AuthUser) {
    token.value = nextToken
    user.value = nextUser
    if (import.meta.client) {
      localStorage.setItem('auth_token', nextToken)
    }
  }

  function loadToken() {
    if (import.meta.client && !token.value) {
      token.value = localStorage.getItem('auth_token')
    }
  }

  async function register(payload: Record<string, unknown>) {
    const response = await $fetch<{ data: { token: string, user: AuthUser } }>('/auth/register', {
      baseURL: config.public.apiBaseUrl,
      method: 'POST',
      body: payload,
    })
    setSession(response.data.token, response.data.user)
    await useAnalytics().register()
  }

  async function login(payload: Record<string, unknown>) {
    const response = await $fetch<{ data: { token: string, user: AuthUser } }>('/auth/login', {
      baseURL: config.public.apiBaseUrl,
      method: 'POST',
      body: payload,
    })
    setSession(response.data.token, response.data.user)
    await useAnalytics().login()
    await useCompareStore().mergeAfterLogin().catch(() => null)
    await useWishlistStore().load().catch(() => null)
    await useCartStore().sync().catch(() => null)
  }

  async function logout() {
    if (token.value) {
      await $fetch('/auth/logout', {
        baseURL: config.public.apiBaseUrl,
        method: 'POST',
        headers: authHeaders(),
      }).catch(() => null)
    }
    token.value = null
    user.value = null
    if (import.meta.client) {
      localStorage.removeItem('auth_token')
    }
  }

  async function fetchUser() {
    loadToken()
    if (!token.value) {
      loaded.value = true
      return
    }
    try {
      const response = await $fetch<{ data: AuthUser }>('/auth/me', {
        baseURL: config.public.apiBaseUrl,
        headers: authHeaders(),
      })
      user.value = response.data
      await useCompareStore().mergeAfterLogin().catch(() => null)
      await useWishlistStore().load().catch(() => null)
      await useCartStore().sync().catch(() => null)
    } catch {
      await logout()
    } finally {
      loaded.value = true
    }
  }

  async function forgotPassword(email: string) {
    return await $fetch('/auth/forgot-password', {
      baseURL: config.public.apiBaseUrl,
      method: 'POST',
      body: { email },
    })
  }

  return { token, user, loaded, isAuthenticated, authHeaders, loadToken, register, login, logout, fetchUser, forgotPassword }
})
