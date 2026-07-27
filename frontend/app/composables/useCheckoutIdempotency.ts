const CHECKOUT_KEY_BYTES = 32

export function generateCheckoutIdempotencyKey(
  cryptoProvider: Pick<Crypto, 'getRandomValues'> = globalThis.crypto,
): string {
  if (!cryptoProvider?.getRandomValues) {
    throw new Error('Secure random key generation is unavailable.')
  }

  const bytes = cryptoProvider.getRandomValues(new Uint8Array(CHECKOUT_KEY_BYTES))
  let binary = ''

  for (const byte of bytes) {
    binary += String.fromCharCode(byte)
  }

  return btoa(binary)
    .replaceAll('+', '-')
    .replaceAll('/', '_')
    .replace(/=+$/u, '')
}

export function shouldRetainCheckoutIdempotencyKey(
  error: { statusCode: number | null },
): boolean {
  return error.statusCode === null
    || error.statusCode >= 500
    || [408, 429].includes(error.statusCode)
}

export function useCheckoutIdempotency() {
  const activeKey = ref<string | null>(null)

  function keyForAttempt(): string {
    activeKey.value ??= generateCheckoutIdempotencyKey()

    return activeKey.value
  }

  function clear(): void {
    activeKey.value = null
  }

  return {
    activeKey: readonly(activeKey),
    keyForAttempt,
    clear,
  }
}
