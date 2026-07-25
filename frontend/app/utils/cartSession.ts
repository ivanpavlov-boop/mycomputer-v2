const CART_SESSION_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export function normalizeCartSessionId(value: unknown): string | null {
  if (typeof value !== 'string' || value.trim() !== value || !CART_SESSION_PATTERN.test(value)) {
    return null
  }

  return value.toLowerCase()
}

export function isValidCartSessionId(value: unknown): value is string {
  return normalizeCartSessionId(value) !== null
}
