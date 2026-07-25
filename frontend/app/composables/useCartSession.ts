import { normalizeCartSessionId } from '~/utils/cartSession'

const CART_SESSION_COOKIE = 'mc_cart_session'
const CART_SESSION_MAX_AGE = 14 * 24 * 60 * 60

export function useCartSession() {
  const cookie = useCookie<string | null>(CART_SESSION_COOKIE, {
    default: () => null,
    path: '/',
    sameSite: 'lax',
    secure: !import.meta.dev,
    httpOnly: false,
    maxAge: CART_SESSION_MAX_AGE,
  })
  const normalizedCookie = normalizeCartSessionId(cookie.value)
  const sessionId = useState<string | null>('cart-session-id', () => normalizedCookie)

  if (cookie.value !== null && normalizedCookie === null) {
    cookie.value = null
    sessionId.value = null
  } else if (normalizedCookie !== null && sessionId.value !== normalizedCookie) {
    sessionId.value = normalizedCookie
  }

  function persist(value: unknown): string {
    const normalized = normalizeCartSessionId(value)

    if (normalized === null) {
      throw new TypeError('Invalid Cart session response.')
    }

    cookie.value = normalized
    sessionId.value = normalized

    return normalized
  }

  function clear() {
    cookie.value = null
    sessionId.value = null
  }

  return {
    cookieName: CART_SESSION_COOKIE,
    sessionId,
    persist,
    clear,
  }
}
