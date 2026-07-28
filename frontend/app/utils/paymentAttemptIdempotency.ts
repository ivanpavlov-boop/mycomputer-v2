const PAYMENT_ATTEMPT_KEY_BYTES = 32

export function generatePaymentAttemptIdempotencyKey(
  cryptoProvider: Pick<Crypto, 'getRandomValues'> = globalThis.crypto,
): string {
  if (!cryptoProvider?.getRandomValues) {
    throw new Error('Secure random key generation is unavailable.')
  }

  const bytes = cryptoProvider.getRandomValues(
    new Uint8Array(PAYMENT_ATTEMPT_KEY_BYTES),
  )
  let binary = ''

  for (const byte of bytes) {
    binary += String.fromCharCode(byte)
  }

  return btoa(binary)
    .replaceAll('+', '-')
    .replaceAll('/', '_')
    .replace(/=+$/u, '')
}

export function shouldRetainPaymentAttemptKey(error: {
  statusCode: number | null
  code?: string | null
}): boolean {
  return error.statusCode === null
    || error.statusCode >= 500
    || error.statusCode === 408
    || error.statusCode === 429
    || (
      error.statusCode === 409
      && error.code === 'payment_attempt_in_progress'
    )
}
